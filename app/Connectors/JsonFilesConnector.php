<?php

namespace App\Connectors;

use App\Connectors\Contracts\Connector;
use App\Models\DataSource;
use App\Sync\StreamCatalog;
use Illuminate\Support\LazyCollection;
use JsonException;
use RuntimeException;

/**
 * Reads canonical records from JSON Lines files in a folder: <path>/<platform>/<stream>.jsonl. For
 * exports that another system drops on a shared folder (SFTP, a mounted share, a nightly job).
 */
class JsonFilesConnector implements Connector
{
    public function __construct(private StreamCatalog $catalog) {}

    public static function definition(): ConnectorDefinition
    {
        return new ConnectorDefinition(
            label: 'JSON Lines files',
            description: 'Reads cis-export records from a folder of .jsonl files, one per stream. For exports another system drops on a shared folder.',
            category: 'Files',
            icon: 'file',
            fields: [
                new ConfigField('path', 'Folder', 'path', required: true, placeholder: 'D:\exports\cis',
                    help: 'Absolute, or relative to the CIS API. Files are read from <folder>/<platform>/<stream>.jsonl.'),
            ],
        );
    }

    public function test(DataSource $source): HealthResult
    {
        $dir = $this->directory($source);

        if (! is_dir($dir)) {
            return HealthResult::failed("Folder [{$dir}] does not exist.");
        }

        $streams = count($this->streams($source));

        return $streams > 0
            ? HealthResult::ok("{$streams} stream files found")
            : HealthResult::failed("No stream files found in [{$dir}].");
    }

    public function streams(DataSource $source): array
    {
        $dir = $this->directory($source);

        if (! is_dir($dir)) {
            throw new RuntimeException("Folder [{$dir}] does not exist.");
        }

        return collect($this->catalog->streams($source->platform))
            ->keys()
            ->filter(fn (string $stream) => is_file($this->file($source, $stream)))
            ->map(fn (string $stream) => new StreamDef($stream))
            ->values()
            ->all();
    }

    public function read(DataSource $source, StreamDef $stream, ?string $cursor): LazyCollection
    {
        $file = $this->file($source, $stream->name);

        return LazyCollection::make(function () use ($file) {
            $handle = fopen($file, 'r') ?: throw new RuntimeException("Cannot open [{$file}].");

            try {
                $line = 0;
                while (($json = fgets($handle)) !== false) {
                    $line++;
                    if (trim($json) === '') {
                        continue;
                    }

                    try {
                        yield json_decode($json, true, flags: JSON_THROW_ON_ERROR);
                    } catch (JsonException $e) {
                        throw new RuntimeException("Invalid JSON in [{$file}] on line {$line}: {$e->getMessage()}", previous: $e);
                    }
                }
            } finally {
                fclose($handle);
            }
        });
    }

    private function directory(DataSource $source): string
    {
        $path = $source->config['path'];

        return preg_match('#^([a-zA-Z]:)?[\\\\/]#', $path) ? $path : base_path($path);
    }

    private function file(DataSource $source, string $stream): string
    {
        return $this->directory($source)."/{$source->platform}/{$stream}.jsonl";
    }
}
