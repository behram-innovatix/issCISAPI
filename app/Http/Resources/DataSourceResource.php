<?php

namespace App\Http\Resources;

use App\Connectors\ConnectorRegistry;
use App\Models\DataSource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Validator;

/**
 * @mixin DataSource
 */
class DataSourceResource extends JsonResource
{
    /**
     * Transform the resource into an array. Secret settings are never returned; `secrets` lists
     * the ones that are set.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $registry = app(ConnectorRegistry::class);
        $definition = $registry->has($this->type) ? $registry->definition($this->type) : null;
        $secretKeys = $definition?->secretKeys() ?? [];
        $config = $this->config ?? [];

        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'connector' => $definition
                ? ['label' => $definition->label, 'category' => $definition->category, 'icon' => $definition->icon, 'features' => $definition->features]
                : ['label' => $this->type, 'category' => 'Unknown', 'icon' => 'plug', 'features' => []],
            'settings_complete' => $settingsComplete = $definition && Validator::make(['config' => $config], $definition->rules())->passes(),
            // settings incomplete (e.g. seeded), or a database source with no dashboard stream mapped yet
            'needs_setup' => ! $settingsComplete || (in_array('tables', $definition->features, true) && ! $this->streamModels()->exists()),
            'tables' => $definition && in_array('tables', $definition->features, true)
                ? ['copied' => $this->sourceTables()->count(), 'streams' => $this->streamModels()->count()]
                : null,
            'ingest_url' => $definition && in_array('ingest', $definition->features, true)
                ? str_replace('__stream__', '{stream}', route('ingest.store', [$this->id, '__stream__']))
                : null,
            'platform' => $this->platform,
            'tenant' => $this->tenant,
            'schedule' => $this->schedule,
            'is_active' => $this->is_active,
            'status' => $this->status->value,
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'last_error' => $this->last_error,
            'config' => (object) array_diff_key($config, array_flip($secretKeys)),
            'secrets' => array_values(array_filter($secretKeys, fn (string $key) => ! empty($config[$key]))),
            'latest_run' => SyncRunResource::make($this->whenLoaded('latestRun')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
