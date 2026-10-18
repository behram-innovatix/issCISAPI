<?php

namespace App\Insights;

use App\Jobs\BuildInsights;
use App\Models\DataSource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Dashboard datasets per platform, cached until one of the platform's sources syncs again.
 * Successful syncs warm the cache in the background (BuildInsights), so dashboards read a ready
 * dataset instead of building one on request.
 */
class InsightsCache
{
    public function __construct(private EduInsights $edu) {}

    /**
     * The current dataset. While a rebuild for newer data is pending, the last built dataset is
     * served (marked `stale`) so a dashboard never waits on a build; with nothing built yet, it is
     * built now.
     *
     * @return array<string, mixed>
     */
    public function get(string $platform): array
    {
        $sources = $this->sources($platform);

        if (is_array($current = Cache::get($this->key($platform, $sources)))) {
            return $current;
        }

        if (is_array($last = Cache::get($this->latestKey($platform)))) {
            BuildInsights::dispatch($platform);

            return [...$last, 'stale' => true];
        }

        return $this->store($platform, $sources);
    }

    /**
     * Build (or rebuild) the platform's dataset now and cache it.
     */
    public function warm(string $platform): void
    {
        $this->store($platform, $this->sources($platform));
    }

    /**
     * @param  Collection<int, DataSource>  $sources
     * @return array<string, mixed>
     */
    private function store(string $platform, Collection $sources): array
    {
        $dataset = $this->build($platform, $sources);
        Cache::put($this->key($platform, $sources), $dataset, now()->addHours(config('insights.cache_hours')));
        Cache::forever($this->latestKey($platform), $dataset);

        return $dataset;
    }

    private function latestKey(string $platform): string
    {
        return "insights:{$platform}:latest";
    }

    /**
     * @return Collection<int, DataSource>
     */
    public function sources(string $platform): Collection
    {
        return DataSource::where('platform', $platform)->orderBy('name')->get();
    }

    /**
     * @param  Collection<int, DataSource>  $sources
     */
    private function key(string $platform, Collection $sources): string
    {
        return "insights:{$platform}:".md5(json_encode([$sources->pluck('id'), $sources->max('data_changed_at')?->toIso8601String(), config('insights')]));
    }

    /**
     * @param  Collection<int, DataSource>  $sources
     * @return array<string, mixed>
     */
    private function build(string $platform, Collection $sources): array
    {
        return match ($platform) {
            'edu' => $this->edu->build($sources->pluck('id')->all()),
            default => [
                'connected' => false,
                'reason' => match (true) {
                    $sources->isEmpty() => 'No data source is connected for this platform yet.',
                    $sources->every(fn (DataSource $s) => $s->last_synced_at === null) => 'Its data sources haven’t synced yet.',
                    default => 'Its synced data isn’t shown on these dashboards yet.',
                },
            ],
        };
    }
}
