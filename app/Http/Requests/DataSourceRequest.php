<?php

namespace App\Http\Requests;

use App\Connectors\ConfigField;
use App\Connectors\ConnectorDefinition;
use App\Connectors\ConnectorRegistry;
use App\Models\DataSource;
use App\Sync\StreamCatalog;
use Closure;
use Cron\CronExpression;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Create or update a data source. `config` is validated against the chosen connector's fields;
 * on update, a secret left empty keeps its saved value.
 */
class DataSourceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $registry = app(ConnectorRegistry::class);
        $definition = $this->definition();

        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('data_sources', 'name')->ignore($this->existing())],
            'type' => $this->existing()
                ? ['prohibited']
                : ['required', 'string', Rule::in(array_keys($registry->types()))],
            'platform' => ['required', 'string', Rule::in($definition?->platforms ?? app(StreamCatalog::class)->platforms())],
            'tenant' => ['required', 'string', 'max:64', 'alpha_dash'],
            'schedule' => ['required', 'string', 'max:64', function (string $attribute, mixed $value, Closure $fail) {
                if (! is_string($value) || ! CronExpression::isValidExpression($value)) {
                    $fail('The schedule must be a valid cron expression, e.g. "0 * * * *".');
                }
            }],
            'is_active' => ['sometimes', 'boolean'],
            'config' => ['present', 'array'],
            ...($definition?->rules() ?? []),
        ];
    }

    /**
     * The validated config, limited to the connector's fields and to the fields whose condition holds.
     *
     * @return array<string, mixed>
     */
    public function connectorConfig(): array
    {
        $config = $this->validated('config', []);
        $definition = $this->definition();

        return collect($definition->fields)
            ->filter(fn (ConfigField $field) => $field->appliesTo($config))
            ->mapWithKeys(function (ConfigField $field) use ($config) {
                $value = $config[$field->key] ?? $field->default;

                return [$field->key => match (true) {
                    $field->type === 'map' => array_filter((array) $value, fn ($v) => is_string($v) && trim($v) !== ''),
                    $field->type === 'boolean' => (bool) $value,
                    $field->generated && blank($value) => Str::random(48),
                    default => $value,
                }];
            })
            ->filter(fn ($value) => $value !== null && $value !== '' && $value !== [])
            ->all();
    }

    /**
     * Keep saved secrets that were left empty, so admins never have to re-enter them.
     */
    protected function prepareForValidation(): void
    {
        $source = $this->existing();
        $definition = $this->definition();

        if (! $source || ! $definition) {
            return;
        }

        $config = (array) $this->input('config', []);

        foreach ($definition->secretKeys() as $key) {
            if (($config[$key] ?? '') === '' && isset($source->config[$key])) {
                $config[$key] = $source->config[$key];
            }
        }

        $this->merge(['config' => $config]);
    }

    private function existing(): ?DataSource
    {
        return $this->route('data_source');
    }

    private function definition(): ?ConnectorDefinition
    {
        $type = $this->existing()?->type ?? $this->input('type');
        $registry = app(ConnectorRegistry::class);

        return is_string($type) && $registry->has($type) ? $registry->definition($type) : null;
    }
}
