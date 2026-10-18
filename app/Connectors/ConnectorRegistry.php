<?php

namespace App\Connectors;

use App\Connectors\Contracts\Connector;
use App\Models\DataSource;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Resolves a data source's `type` to its connector, using the map in config/connectors.php.
 */
class ConnectorRegistry
{
    public function __construct(private Container $container) {}

    /**
     * @return array<string, class-string<Connector>>
     */
    public function types(): array
    {
        return config('connectors.types');
    }

    /**
     * @return class-string<Connector>
     */
    public function classFor(string $type): string
    {
        return $this->types()[$type] ?? throw new InvalidArgumentException("Unknown connector type [{$type}].");
    }

    public function has(string $type): bool
    {
        return isset($this->types()[$type]);
    }

    public function definition(string $type): ConnectorDefinition
    {
        return $this->classFor($type)::definition();
    }

    /**
     * @return array<string, ConnectorDefinition> type => definition, for every installed connector
     */
    public function definitions(): array
    {
        return array_map(fn (string $class) => $class::definition(), $this->types());
    }

    public function for(DataSource $source): Connector
    {
        return $this->container->make($this->classFor($source->type));
    }
}
