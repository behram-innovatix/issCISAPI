<?php

namespace App\Http\Controllers\Api;

use App\Connectors\ConnectorRegistry;
use App\Connectors\SpreadsheetConnector;
use App\Http\Controllers\Controller;
use App\Models\DataSource;
use App\Sync\StreamCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Files uploaded to sources whose connector reads uploads (Excel / CSV), one per stream.
 */
class DataSourceFileController extends Controller
{
    /**
     * The uploaded file for each stream.
     */
    public function index(DataSource $dataSource, ConnectorRegistry $registry): JsonResponse
    {
        $this->ensureUploads($dataSource, $registry);

        return response()->json(['data' => SpreadsheetConnector::files($dataSource)]);
    }

    /**
     * Upload (or replace) the file for one stream.
     */
    public function store(Request $request, DataSource $dataSource, ConnectorRegistry $registry, StreamCatalog $catalog): JsonResponse
    {
        $this->ensureUploads($dataSource, $registry);

        $validated = $request->validate([
            'stream' => ['required', 'string', Rule::in(array_keys($catalog->streams($dataSource->platform)))],
            'file' => ['required', 'file', 'max:20480', 'extensions:'.implode(',', SpreadsheetConnector::EXTENSIONS)],
        ]);

        SpreadsheetConnector::store($dataSource, $validated['stream'], $request->file('file'));

        return response()->json(['data' => SpreadsheetConnector::files($dataSource)], 201);
    }

    /**
     * Remove a stream's file; its records go on the next sync.
     */
    public function destroy(DataSource $dataSource, string $stream, ConnectorRegistry $registry): Response
    {
        $this->ensureUploads($dataSource, $registry);

        SpreadsheetConnector::remove($dataSource, $stream);

        return response()->noContent();
    }

    private function ensureUploads(DataSource $dataSource, ConnectorRegistry $registry): void
    {
        abort_unless($registry->has($dataSource->type) && in_array('uploads', $registry->definition($dataSource->type)->features, true), 404);
    }
}
