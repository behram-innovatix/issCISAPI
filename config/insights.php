<?php

return [

    /*
    |--------------------------------------------------------------------------
    | EDU dashboards
    |--------------------------------------------------------------------------
    |
    | The dashboards cover the school weeks up to the latest attendance mark,
    | at most `max_weeks` of them. The school week is Sunday to Thursday, with
    | an AM and a PM register each day.
    |
    */

    'edu' => [
        'max_weeks' => (int) env('CIS_INSIGHTS_EDU_MAX_WEEKS', 15),
    ],

    // Dashboard datasets are cached until one of the platform's sources syncs again.
    'cache_hours' => (int) env('CIS_INSIGHTS_CACHE_HOURS', 24),

];
