<?php

namespace App\Sync;

use InvalidArgumentException;

/**
 * Read access to the canonical streams in config/cis_streams.php.
 */
class StreamCatalog
{
    /**
     * @return list<string>
     */
    public function platforms(): array
    {
        return array_keys(config('cis_streams'));
    }

    /**
     * @return array<string, StreamDefinition> stream name => definition, in config order
     */
    public function streams(string $platform): array
    {
        $streams = config("cis_streams.{$platform}");

        if (! is_array($streams)) {
            throw new InvalidArgumentException("Unknown CIS platform [{$platform}].");
        }

        return collect($streams)
            ->map(fn (array $config, string $name) => StreamDefinition::fromConfig($platform, $name, $config))
            ->all();
    }

    /**
     * Look up a stream by "<platform>.<stream>".
     */
    public function find(string $qualifiedName): StreamDefinition
    {
        [$platform, $name] = explode('.', $qualifiedName, 2) + [1 => ''];

        return $this->streams($platform)[$name]
            ?? throw new InvalidArgumentException("Unknown CIS stream [{$qualifiedName}].");
    }
}
