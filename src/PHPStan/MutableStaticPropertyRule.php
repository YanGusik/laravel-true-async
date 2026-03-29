<?php

declare(strict_types=1);

namespace Spawn\Laravel\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Stmt\Property;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Flags mutable (non-readonly) static properties.
 *
 * In a coroutine environment every static property is shared across
 * concurrent requests inside the same worker. Any write after boot
 * causes state leakage between coroutines.
 *
 * @implements Rule<Property>
 */
final class MutableStaticPropertyRule implements Rule
{
    public function getNodeType(): string
    {
        return Property::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        // Only interested in static properties
        if (! $node->isStatic()) {
            return [];
        }

        // Readonly statics are safe — immutable after initialization
        if ($node->isReadonly()) {
            return [];
        }

        $classReflection = $scope->getClassReflection();
        if ($classReflection === null) {
            return [];
        }

        $className = $classReflection->getDisplayName(false);

        $errors = [];

        foreach ($node->props as $prop) {
            $errors[] = RuleErrorBuilder::message(
                sprintf(
                    'Class %s has mutable static property $%s — potential coroutine state leak.',
                    $className,
                    $prop->name->toString(),
                )
            )
                ->identifier('coroutine.mutableStaticProperty')
                ->line($node->getStartLine())
                ->build();
        }

        return $errors;
    }
}
