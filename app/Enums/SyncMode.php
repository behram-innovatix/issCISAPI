<?php

namespace App\Enums;

enum SyncMode: string
{
    /** Pull records changed since each stream's cursor. */
    case Incremental = 'incremental';

    /** Pull everything and mark records the source no longer returns as deleted. */
    case Full = 'full';
}
