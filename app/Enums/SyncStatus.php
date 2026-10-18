<?php

namespace App\Enums;

enum SyncStatus: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Degraded = 'degraded';
    case Failed = 'failed';
}
