<?php

namespace App\Connectors;

/**
 * How a connector type presents itself in the admin connection catalog: what it is, which
 * platforms it can feed, and the settings form for a new data source of this type.
 */
final readonly class ConnectorDefinition
{
    /**
     * @param  string  $icon  a generic icon name the UI maps to its own icon set (server, file, database, sheet, globe, webhook)
     * @param  list<string>|null  $platforms  CIS platforms this connector can feed; null for any
     * @param  list<ConfigField>  $fields
     * @param  list<string>  $features  extra admin panels the source needs: "uploads" (files per stream) or "ingest" (a push endpoint)
     */
    public function __construct(
        public string $label,
        public string $description,
        public string $category,
        public string $icon,
        public ?array $platforms = null,
        public array $fields = [],
        public array $features = [],
    ) {}

    public function field(string $key): ?ConfigField
    {
        return collect($this->fields)->first(fn (ConfigField $field) => $field->key === $key);
    }

    /**
     * @return list<string>
     */
    public function secretKeys(): array
    {
        return collect($this->fields)->filter->isSecret()->pluck('key')->values()->all();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return collect($this->fields)->flatMap(fn (ConfigField $field) => $field->rules())->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'description' => $this->description,
            'category' => $this->category,
            'icon' => $this->icon,
            'platforms' => $this->platforms,
            'fields' => array_map(fn (ConfigField $field) => $field->toArray(), $this->fields),
            'features' => $this->features,
        ];
    }
}
