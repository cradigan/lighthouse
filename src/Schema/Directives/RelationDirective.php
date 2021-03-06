<?php

namespace Nuwave\Lighthouse\Schema\Directives;

use Closure;
use GraphQL\Language\AST\FieldDefinitionNode;
use GraphQL\Language\AST\ObjectTypeDefinitionNode;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Nuwave\Lighthouse\Exceptions\DefinitionException;
use Nuwave\Lighthouse\Execution\DataLoader\BatchLoaderRegistry;
use Nuwave\Lighthouse\Execution\DataLoader\PaginatedRelationLoader;
use Nuwave\Lighthouse\Execution\DataLoader\RelationBatchLoader;
use Nuwave\Lighthouse\Execution\DataLoader\SimpleRelationLoader;
use Nuwave\Lighthouse\Pagination\PaginationArgs;
use Nuwave\Lighthouse\Pagination\PaginationManipulator;
use Nuwave\Lighthouse\Pagination\PaginationType;
use Nuwave\Lighthouse\Schema\AST\DocumentAST;
use Nuwave\Lighthouse\Schema\Values\FieldValue;
use Nuwave\Lighthouse\Support\Contracts\FieldResolver;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

abstract class RelationDirective extends BaseDirective implements FieldResolver
{
    public function resolveField(FieldValue $value): FieldValue
    {
        $value->setResolver(
            function (Model $parent, array $args, GraphQLContext $context, ResolveInfo $resolveInfo) {
                $relationName = $this->directiveArgValue('relation', $this->nodeName());

                $decorateBuilder = $this->makeBuilderDecorator($resolveInfo);
                $paginationArgs = $this->paginationArgs($args);

                if (config('lighthouse.batchload_relations')) {
                    /** @var \Nuwave\Lighthouse\Execution\DataLoader\RelationBatchLoader $relationBatchLoader */
                    $relationBatchLoader = BatchLoaderRegistry::instance(
                        RelationBatchLoader::class,
                        $relationName,
                        $resolveInfo->path,
                        $this->directiveArgValue('scopes', []),
                        $resolveInfo->argumentSet->arguments
                    );

                    if (! $relationBatchLoader->hasRelationLoader()) {
                        if ($paginationArgs !== null) {
                            $relationLoader = new PaginatedRelationLoader($decorateBuilder, $paginationArgs);
                        } else {
                            $relationLoader = new SimpleRelationLoader($decorateBuilder);
                        }

                        $relationBatchLoader->registerRelationLoader($relationLoader, $relationName);
                    }

                    return $relationBatchLoader->load($parent);
                }

                /** @var \Illuminate\Database\Eloquent\Relations\Relation $relation */
                $relation = $parent->{$relationName}();

                $decorateBuilder($relation);

                if ($paginationArgs !== null) {
                    return $paginationArgs->applyToBuilder($relation);
                } else {
                    return $relation->getResults();
                }
            }
        );

        return $value;
    }

    /**
     * @return Closure(object): void
     */
    protected function makeBuilderDecorator(ResolveInfo $resolveInfo): Closure
    {
        return function (object $builder) use ($resolveInfo) {
            if ($builder instanceof Relation) {
                $builder = $builder->getQuery();
            }

            $resolveInfo
                ->argumentSet
                ->enhanceBuilder(
                    $builder,
                    $this->directiveArgValue('scopes', [])
                );
        };
    }

    /**
     * @return array<string|class-string<\Illuminate\Database\Eloquent\Model>>
     */
    protected function buildPath(ResolveInfo $resolveInfo, Model $parent): array
    {
        $path = $resolveInfo->path;

        // When dealing with polymorphic relations, we might have a case where
        // there are multiple different models at the same path in the query.
        // Because the RelationBatchLoader can only deal with one kind of parent model,
        // we make sure we get one unique batch loader instance per model class.
        $path [] = get_class($parent);

        return $path;
    }

    public function manipulateFieldDefinition(
        DocumentAST &$documentAST,
        FieldDefinitionNode &$fieldDefinition,
        ObjectTypeDefinitionNode &$parentType
    ): void {
        $paginationType = $this->paginationType();

        // We default to not changing the field if no pagination type is set explicitly.
        // This makes sense for relations, as there should not be too many entries.
        if ($paginationType === null) {
            return;
        }

        $paginationManipulator = new PaginationManipulator($documentAST);
        $paginationManipulator->transformToPaginatedField(
            $paginationType,
            $fieldDefinition,
            $parentType,
            $this->directiveArgValue('defaultCount')
                ?? config('lighthouse.pagination.default_count'),
            $this->paginateMaxCount(),
            $this->edgeType($documentAST)
        );
    }

    /**
     * @throws \Nuwave\Lighthouse\Exceptions\DefinitionException
     */
    protected function edgeType(DocumentAST $documentAST): ?ObjectTypeDefinitionNode
    {
        if ($edgeTypeName = $this->directiveArgValue('edgeType')) {
            $edgeType = $documentAST->types[$edgeTypeName] ?? null;
            if (! $edgeType instanceof ObjectTypeDefinitionNode) {
                throw new DefinitionException(
                    "The edgeType argument on {$this->nodeName()} must reference an existing object type definition."
                );
            }

            return $edgeType;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $args
     */
    protected function paginationArgs(array $args): ?PaginationArgs
    {
        if (($paginationType = $this->paginationType()) !== null) {
            return PaginationArgs::extractArgs($args, $paginationType, $this->paginateMaxCount());
        }

        return null;
    }

    protected function paginationType(): ?PaginationType
    {
        if ($paginationType = $this->directiveArgValue('type')) {
            return new PaginationType($paginationType);
        }

        return null;
    }

    /**
     * Get either the specific max or the global setting.
     */
    protected function paginateMaxCount(): ?int
    {
        return $this->directiveArgValue('maxCount')
            ?? config('lighthouse.pagination.max_count');
    }
}
