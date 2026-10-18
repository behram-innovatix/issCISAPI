<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Insights\InsightsCache;
use App\Models\DataSource;
use App\Sync\StreamCatalog;
use Illuminate\Http\JsonResponse;

class InsightsController extends Controller
{
    /**
     * The dashboard dataset for one platform, built from its sources' marts, with the sources'
     * freshness. Cached until one of those sources syncs again.
     */
    public function show(string $platform, StreamCatalog $catalog, InsightsCache $insights): JsonResponse
    {
        abort_unless(in_array($platform, $catalog->platforms(), true), 404);

        $sources = $insights->sources($platform);

        return response()->json(['data' => [
            ...$insights->get($platform),
            'platform' => $platform,
            'synced_at' => $sources->max('last_synced_at')?->toIso8601String(),
            'sources' => $sources->map(fn (DataSource $source) => [
                'name' => $source->name,
                'status' => $source->status->value,
                'is_active' => $source->is_active,
                'last_synced_at' => $source->last_synced_at?->toIso8601String(),
            ]),
        ]]);
    }
}
