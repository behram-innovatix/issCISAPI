<?php

namespace App\Enums;

enum SourceStatus: string
{
    /** Never synced. */
    case New = 'new';

    /** Last run succeeded. */
    case Healthy = 'healthy';

    /** Last run failed a quality gate; the marts still hold the last good data. */
    case Degraded = 'degraded';

    /** Last run errored (unreachable source, bad credentials, invalid records). */
    case Failing = 'failing';
}
