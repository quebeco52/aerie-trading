<?php

declare(strict_types=1);

namespace App\Tests\Support\Model;

use App\Service\Model\AbstractBusinessModel;

/**
 * Reads whether a model sets a hook constant itself. Every hook is declared on AbstractBusinessModel with its default,
 * so `defined()` is true for every model; a test about what a sector declares asks where the value was declared.
 */
final class HookConstant
{
    /**
     * Whether the model, or a class between it and the base, redeclares the hook rather than taking its default.
     *
     * @param class-string $modelClass
     */
    public static function isRedeclared(string $modelClass, string $constant): bool
    {
        return defined($modelClass . '::' . $constant)
            && (new \ReflectionClassConstant($modelClass, $constant))->getDeclaringClass()->getName() !== AbstractBusinessModel::class;
    }
}
