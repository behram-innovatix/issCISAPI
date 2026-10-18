<?php

namespace App\Console\Commands;

use App\Insights\InsightsCache;
use App\Sync\StreamCatalog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('cis:insights {platforms?* : Platforms to rebuild (default: all)}')]
#[Description('Rebuild and cache the dashboard datasets (runs automatically after each successful sync)')]
class WarmInsights extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(InsightsCache $insights, StreamCatalog $catalog): int
    {
        foreach ($this->argument('platforms') ?: $catalog->platforms() as $platform) {
            $this->components->task($platform, fn () => $insights->warm($platform));
        }

        return self::SUCCESS;
    }
}
