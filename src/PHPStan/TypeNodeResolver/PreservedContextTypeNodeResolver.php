<?php

declare(strict_types=1);

namespace TwigStan\PHPStan\TypeNodeResolver;

use PHPStan\Analyser\NameScope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\PhpDoc\TypeNodeResolver;
use PHPStan\PhpDoc\TypeNodeResolverAwareExtension;
use PHPStan\PhpDoc\TypeNodeResolverExtension;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\LateResolvableType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeTraverser;
use WeakMap;

#[AutowiredService]
final class PreservedContextTypeNodeResolver implements TypeNodeResolverExtension, TypeNodeResolverAwareExtension
{
    private TypeNodeResolver $resolver;

    /**
     * @var array<int, true>
     */
    private array $resolving = [];

    /**
     * @var null|WeakMap<NameScope, array<string, Type>>
     */
    private ?WeakMap $resolvedShapes = null;

    public function setTypeNodeResolver(TypeNodeResolver $typeNodeResolver): void
    {
        $this->resolver = $typeNodeResolver;
    }

    public function resolve(TypeNode $typeNode, NameScope $nameScope): ?Type
    {
        if ( ! $typeNode instanceof ArrayShapeNode || ! str_starts_with($nameScope->getClassName() ?? '', '__TwigTemplate_')) {
            return null;
        }

        $id = spl_object_id($typeNode);

        if (isset($this->resolving[$id])) {
            return null;
        }

        $this->resolvedShapes ??= new WeakMap();
        $key = (string) $typeNode;

        if (isset($this->resolvedShapes[$nameScope][$key])) {
            return $this->resolvedShapes[$nameScope][$key];
        }

        $this->resolving[$id] = true;

        try {
            // Resolve nested shapes through this extension before the default resolver combines them.
            $type = $this->resolver->resolve($typeNode, $nameScope);

            $type = TypeTraverser::map($type, static function (Type $type, callable $traverse): Type {
                if ($type instanceof LateResolvableType) {
                    $type = $type->resolve();
                }

                // @phpstan-ignore phpstanApi.instanceofType (The preservation API belongs to the concrete shape.)
                if ($type instanceof ConstantArrayType) {
                    return $type->withPreservedArrayShape();
                }

                return $traverse($type);
            });

            $this->resolvedShapes[$nameScope] ??= [];
            $this->resolvedShapes[$nameScope][$key] = $type;

            return $type;
        } finally {
            unset($this->resolving[$id]);

            // Share immutable subtrees only within this resolution, with each name scope kept separate.
            if ($this->resolving === []) {
                $this->resolvedShapes = null;
            }
        }
    }
}
