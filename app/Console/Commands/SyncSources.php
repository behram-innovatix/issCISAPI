<?php

namespace App\Console\Commands;

use App\Enums\SyncMode;
use App\Enums\SyncStatus;
use App\Jobs\SyncDataSource;
use App\Models\DataSource;
use App\Sync\SyncPipeline;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('cis:sync
    {sources?* : Data source ids or names}
    {--all : Every active data source}
    {--due : Active data sources whose schedule is due now}
    {--full : Full reconcile (re-read everything, drop records the source no longer returns)}
    {--queue : Dispatch to the queue instead of syncing now}')]
#[Description('Pull data sources into the CIS raw store and rebuild their marts')]
class SyncSources extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(SyncPipeline $pipeline): int
    {
        $names = $this->argument('sources');

        if ($names === [] && ! $this->option('all') && ! $this->option('due')) {
            $this->components->error('Name the data sources to sync, or pass --all or --due.');

            return self::INVALID;
        }

        $sources = DataSource::query()
            ->when($names !== [], fn ($query) => $query->where(fn ($query) => $query->whereIn('id', array_filter($names, 'is_numeric'))->orWhereIn('name', $names)))
            ->when($names === [], fn ($query) => $query->active())
            ->orderBy('id')
            ->get()
            ->when($this->option('due'), fn ($sources) => $sources->filter->isDue());

        if ($sources->isEmpty()) {
            $this->components->info('No data sources to sync.');

            return self::SUCCESS;
        }

        $mode = $this->option('full') ? SyncMode::Full : SyncMode::Incremental;
        $failed = false;

        foreach ($sources as $source) {
            if ($this->option('queue')) {
                SyncDataSource::dispatch($source, $mode);
                $this->components->twoColumnDetail($source->name, "queued ({$mode->value})");

                continue;
            }

            try {
                $run = $pipeline->run($source, $mode);
            } catch (Throwable $e) {
                $failed = true;
                $this->components->error("{$source->name}: {$e->getMessage()}");

                continue;
            }

            $this->components->twoColumnDetail("<options=bold>{$source->name}</>", "{$run->status->value} · {$mode->value} · ".$run->started_at->diffForHumans($run->finished_at, true));

            foreach ($run->stats as $stream => $counts) {
                $detail = number_format($counts['read']).' read · '.number_format($counts['active']).' active';
                $detail .= $counts['deleted'] ? ' · '.number_format($counts['deleted']).' deleted' : '';
                $detail .= isset($counts['mart']) ? ' · '.number_format($counts['mart']).' in mart' : '';
                $this->components->twoColumnDetail("  {$stream}", $detail);
            }

            if ($run->status !== SyncStatus::Succeeded) {
                $failed = true;
                $this->components->warn($run->error);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
