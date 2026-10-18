<?php

namespace App\Jobs;

use App\Insights\InsightsCache;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Rebuild a platform's dashboard dataset after its data changed, so the next dashboard request
 * reads it from the cache. One pending build per platform at a time.
 */
class BuildInsights implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 1800;

    /**
     * Create a new job instance.
     */
    public function __construct(public string $platform) {}

    public function uniqueId(): string
    {
        return $this->platform;
    }

    /**
     * Execute the job.
     */
    public function handle(InsightsCache $insights): void
    {
        $insights->warm($this->platform);
    }
}
