<?php

use App\Connectors\IssExportConnector;
use App\Connectors\JsonFilesConnector;
use App\Connectors\PushIngestConnector;
use App\Connectors\RestApiConnector;
use App\Connectors\SpreadsheetConnector;
use App\Connectors\SqlDatabaseConnector;

return [

    /*
    |--------------------------------------------------------------------------
    | Connector types
    |--------------------------------------------------------------------------
    |
    | Every kind of source the CIS can pull from. A data_sources row names one
    | of these types; adding a new kind of source is a class that implements
    | App\Connectors\Contracts\Connector plus one line here.
    |
    */

    'types' => [
        'iss-export' => IssExportConnector::class,
        'database' => SqlDatabaseConnector::class,
        'rest-api' => RestApiConnector::class,
        'spreadsheet' => SpreadsheetConnector::class,
        'push-ingest' => PushIngestConnector::class,
        'json-files' => JsonFilesConnector::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Planned connector types
    |--------------------------------------------------------------------------
    |
    | Shown in the admin catalog as "coming soon" (type => label, description,
    | category, icon). Move an entry to `types` once it has a class.
    |
    */

    'planned' => [],

    /*
    |--------------------------------------------------------------------------
    | Pipeline
    |--------------------------------------------------------------------------
    |
    | chunk_size: rows per upsert/insert statement.
    | max_drop_ratio: a stream whose active record count falls by more than
    | this share since the last good run fails the quality gate, and the marts
    | keep their last good data.
    |
    */

    'chunk_size' => (int) env('CIS_SYNC_CHUNK_SIZE', 1000),

    'max_drop_ratio' => (float) env('CIS_SYNC_MAX_DROP_RATIO', 0.5),

    'default_schedule' => env('CIS_SYNC_DEFAULT_SCHEDULE', '0 * * * *'),

];
