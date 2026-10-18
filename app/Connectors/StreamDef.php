<?php

namespace App\Connectors;

/**
 * A stream as one source provides it. `cursorField` is the record field incremental syncs resume
 * from; without one, every sync reads the whole stream. A stream that is read whole is also
 * `complete`: records it no longer returns are removed on every sync, not only on full syncs.
 */
final readonly class StreamDef
{
    public bool $complete;

    public function __construct(
        public string $name,
        public ?string $cursorField = null,
        ?bool $complete = null,
    ) {
        $this->complete = $complete ?? $cursorField === null;
    }
}
