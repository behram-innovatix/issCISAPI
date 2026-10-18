<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Table replication
    |--------------------------------------------------------------------------
    |
    | Database connections copy the tables an administrator selects into the
    | replica database, then build the dashboard streams from those copies.
    | `connection` is where the copies live (null: the default connection,
    | which the tests use).
    |
    */

    'connection' => env('CIS_REPLICA_CONNECTION', 'replica'),

    // rows shown when previewing a source table or a dashboard stream
    'preview_rows' => 20,

    // how long a source's table list is cached (seconds)
    'catalog_ttl' => 600,

];
