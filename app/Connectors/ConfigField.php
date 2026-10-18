<?php

namespace App\Connectors;

use Illuminate\Validation\Rule;

/**
 * One setting in a connector's config form. The admin UI renders the form from these, and the API
 * validates `config` with the rules they produce, so a connector declares its settings once.
 */
final readonly class ConfigField
{
    public const TYPES = ['text', 'textarea', 'url', 'secret', 'number', 'boolean', 'select', 'list', 'path', 'map'];

    /**
     * @param  string  $type  text, textarea, url, secret (write-only, never returned), number, boolean, select,
     *                        list (of strings), path, or map (one text value per stream of the platform)
     * @param  array<string, string>  $options  select value => label
     * @param  array<string, string|list<string>>|null  $when  only shown (and required) when other settings have
     *                                                         one of these values, e.g. ['auth' => 'client_credentials']
     * @param  string|null  $language  editor hint for textarea and map values: sql, json or text
     * @param  bool  $generated  a secret the CIS generates when it is left empty (e.g. a signing secret)
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $type = 'text',
        public bool $required = false,
        public ?string $help = null,
        public ?string $placeholder = null,
        public array $options = [],
        public mixed $default = null,
        public ?array $when = null,
        public ?string $language = null,
        public bool $generated = false,
    ) {}

    public function isSecret(): bool
    {
        return $this->type === 'secret';
    }

    /**
     * Whether this field is in use for the given config (its `when` condition holds).
     *
     * @param  array<string, mixed>  $config
     */
    public function appliesTo(array $config): bool
    {
        return $this->when === null
            || collect($this->when)->every(fn (string|array $values, string $key) => in_array($config[$key] ?? null, (array) $values, true));
    }

    /**
     * Validation rules for this field inside a data source's `config`.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $presence = match (true) {
            ! $this->required || $this->generated => ['nullable'],
            $this->when === null => ['required'],
            default => [
                'required_if:config.'.array_key_first($this->when).','.implode(',', (array) $this->when[array_key_first($this->when)]),
                'nullable',
            ],
        };

        $rules = ["config.{$this->key}" => [...$presence, ...match ($this->type) {
            'url' => ['url:http,https', 'max:2000'],
            'number' => ['integer', 'min:0'],
            'boolean' => ['boolean'],
            'select' => [Rule::in(array_keys($this->options))],
            'list', 'map' => ['array'],
            'textarea' => ['string', 'max:20000'],
            default => ['string', 'max:2000'],
        }]];

        if ($this->type === 'list') {
            $rules["config.{$this->key}.*"] = ['string', 'max:191'];
        }
        if ($this->type === 'map') {
            $rules["config.{$this->key}.*"] = ['nullable', 'string', 'max:20000'];
        }

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type,
            'required' => $this->required,
            'help' => $this->help,
            'placeholder' => $this->placeholder,
            'options' => collect($this->options)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values()->all(),
            'default' => $this->default,
            'when' => $this->when,
            'language' => $this->language,
            'generated' => $this->generated,
        ];
    }
}
