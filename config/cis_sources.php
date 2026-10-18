<?php

/*
|--------------------------------------------------------------------------
| Seeded connections
|--------------------------------------------------------------------------
|
| Settings for the connections DataSourceSeeder creates, read from the
| environment so credentials stay out of the code. Once seeded, connections
| are managed in the admin Connections screen; seeding again never
| overwrites a connection that already exists.
|
*/

return [

    // issEduApi's SQL Server database, read with SELECT queries (database/seeders/connections/issedu)
    'issedu' => [
        'host' => env('CIS_EDU_DB_HOST'),
        'port' => env('CIS_EDU_DB_PORT'),
        'database' => env('CIS_EDU_DB_DATABASE'),
        'username' => env('CIS_EDU_DB_USERNAME'),
        'password' => env('CIS_EDU_DB_PASSWORD'),
        'trust_server_certificate' => (bool) env('CIS_EDU_DB_TRUST_SERVER_CERTIFICATE', true),
        'academic_year' => env('CIS_EDU_ACADEMIC_YEAR', '2025/2026'),
        // first day of school-year data the large tables (attendance, behaviour) are copied from
        'from_date' => env('CIS_EDU_FROM_DATE', '2025-08-01'),
        'schedule' => env('CIS_EDU_SCHEDULE', '0 * * * *'),
    ],

    // HRM's cis-export API (to be built in the HRM app)
    'hrm' => [
        'base_url' => env('CIS_HRM_API_URL'),
        'token' => env('CIS_HRM_API_TOKEN'),
    ],

    // Finance's cis-export API (to be built in the Finance app)
    'finance' => [
        'base_url' => env('CIS_FINANCE_API_URL'),
        'token' => env('CIS_FINANCE_API_TOKEN'),
    ],

];
