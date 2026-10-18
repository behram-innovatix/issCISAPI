<?php

namespace App\Http\Controllers\Api;

use App\Connectors\ConnectorDefinition;
use App\Connectors\ConnectorRegistry;
use App\Http\Controllers\Controller;
use App\Sync\StreamCatalog;
use Illuminate\Http\JsonResponse;

class ConnectorController extends Controller
{
    /**
     * The connection catalog: installed connector types with their settings forms, planned
     * types, and the CIS platforms (with their streams) a data source can feed.
     */
    public function index(ConnectorRegistry $registry, StreamCatalog $catalog): JsonResponse
    {
        return response()->json(['data' => [
            'connectors' => collect($registry->definitions())
                ->map(fn (ConnectorDefinition $definition, string $type) => ['type' => $type, ...$definition->toArray()])
                ->values(),
            'planned' => collect(config('connectors.planned'))
                ->map(fn (array $connector, string $type) => ['type' => $type, ...$connector])
                ->values(),
            'platforms' => collect($catalog->platforms())
                ->map(fn (string $platform) => ['code' => $platform, 'streams' => array_keys($catalog->streams($platform))]),
            'default_schedule' => config('connectors.default_schedule'),
        ]]);
    }
}
