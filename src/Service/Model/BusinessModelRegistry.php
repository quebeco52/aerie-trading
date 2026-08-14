<?php

declare(strict_types=1);

namespace App\Service\Model;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Registry service for business model strategies.
 * Manages model resolution, dependency injection, and strategy lifecycle.
 */
class BusinessModelRegistry implements BusinessModelRegistryInterface
{
    /** @var array<string, BusinessModelInterface> */
    private array $models = [];

    /**
     * Map of specific class names to canonical sector identifiers.
     */
    private const CLASS_MAP = [
        AssetManagementBusinessModel::class => 'asset_manager',
        StandardCorporateBusinessModel::class => 'none',
    ];

    /**
     * @param iterable<BusinessModelInterface> $models
     */
    public function __construct(
        #[AutowireIterator('app.business_model')]
        iterable $models = []
    ) {
        foreach ($models as $model) {
            $identifier = $this->resolveIdentifier($model);
            $model->setModelIdentifier($identifier);
            $this->models[$identifier] = $model;

            // Register aliases if applicable
            if ($identifier === 'none') {
                $this->models['standard_corporate'] = $model;
            } elseif ($identifier === 'asset_manager') {
                $this->models['asset_management'] = $model;
            }
        }
    }

    public function register(string $identifier, BusinessModelInterface $model): void
    {
        $model->setModelIdentifier($identifier);
        $this->models[$identifier] = $model;
    }

    public function get(string $identifier): BusinessModelInterface
    {
        if (isset($this->models[$identifier])) {
            return $this->models[$identifier];
        }

        // Fallback to standard corporate if not found
        if (isset($this->models['none'])) {
            return $this->models['none'];
        }

        $fallback = new StandardCorporateBusinessModel();
        $fallback->setModelIdentifier($identifier);
        return $fallback;
    }

    public function has(string $identifier): bool
    {
        return isset($this->models[$identifier]);
    }

    public function all(): array
    {
        return $this->models;
    }

    private function resolveIdentifier(BusinessModelInterface $model): string
    {
        $class = get_class($model);
        if (isset(self::CLASS_MAP[$class])) {
            return self::CLASS_MAP[$class];
        }

        $shortName = (new \ReflectionClass($model))->getShortName();
        $base = (string) preg_replace('/BusinessModel$/', '', $shortName);
        $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $base));

        return $snake !== '' ? $snake : 'none';
    }
}
