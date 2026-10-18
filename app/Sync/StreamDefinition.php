<?php

namespace App\Sync;

/**
 * One canonical stream from config/cis_streams.php, parsed.
 */
final readonly class StreamDefinition
{
    /**
     * @param  array<string, array{cast: string, field: string}>  $columns  mart column => cast and payload field
     * @param  list<list<string>>  $indexes
     */
    public function __construct(
        public string $platform,
        public string $name,
        public string $keyColumn,
        public string $keyCast,
        public array $columns,
        public array $indexes = [],
        public ?string $identity = null,
    ) {}

    /**
     * @param  array{key: string, columns: array<string, string>, indexes?: list<list<string>>, identity?: string}  $config
     */
    public static function fromConfig(string $platform, string $name, array $config): self
    {
        [$keyColumn, $keyCast] = explode(':', $config['key'], 2) + [1 => 'string'];

        $columns = [];
        foreach ($config['columns'] as $column => $spec) {
            [$cast, $field] = explode(':', $spec, 2) + [1 => $column];
            $columns[$column] = ['cast' => $cast, 'field' => $field];
        }

        return new self($platform, $name, $keyColumn, $keyCast, $columns, $config['indexes'] ?? [], $config['identity'] ?? null);
    }

    public function table(): string
    {
        return "mart_{$this->platform}_{$this->name}";
    }
}
