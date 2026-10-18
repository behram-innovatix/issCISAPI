<?php

namespace App\Jobs;

use App\Enums\SyncMode;
use App\Models\DataSource;
use App\Sync\SyncPipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class SyncDataSource implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 3600;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public DataSource $source,
        public SyncMode $mode = SyncMode::Incremental,
    ) {}

    /**
     * Seconds to wait before each retry.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    /**
     * One sync per source at a time; a sync that would overlap a running one is dropped.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->source->id))->dontRelease()->expireAfter($this->timeout)];
    }

    /**
     * Execute the job.
     */
    public function handle(SyncPipeline $pipeline): void
    {
        $pipeline->run($this->source, $this->mode);
    }
}
