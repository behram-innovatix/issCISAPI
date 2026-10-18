<?php

namespace App\Connectors;

use App\Connectors\Concerns\NormalizesRecords;
use App\Connectors\Contracts\Connector;
use App\Models\DataSource;
use App\Sync\StreamCatalog;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use RuntimeException;
use SplFileObject;

/**
 * Loads streams from spreadsheets that staff upload in the admin screen: one CSV or Excel file per
 * stream, a header row, then one record per row. Headers match the stream's field names (case and
 * spaces don't matter) unless a column mapping says otherwise. Each upload replaces the stream.
 */
class SpreadsheetConnector implements Connector
{
    use NormalizesRecords;

    public const EXTENSIONS = ['csv', 'txt', 'xlsx', 'xls'];

    public function __construct(private StreamCatalog $catalog) {}

    public static function definition(): ConnectorDefinition
    {
        return new ConnectorDefinition(
            label: 'Excel / CSV upload',
            description: 'Budgets, targets and other data kept in spreadsheets: upload a CSV or Excel file per stream, checked before it loads.',
            category: 'Files',
            icon: 'sheet',
            fields: [
                new ConfigField('sheet', 'Worksheet', placeholder: 'First sheet',
                    help: 'Excel files only: the worksheet to read. Leave empty for the first one.'),
                new ConfigField('column_map', 'Column mapping', 'map', language: 'json', placeholder: '{"id": "Budget code", "annual": "FY total"}',
                    help: 'JSON per stream: canonical field → column header, for headers that differ from the field names.'),
            ],
            features: ['uploads'],
        );
    }

    public function test(DataSource $source): HealthResult
    {
        $files = self::files($source);

        if ($files === []) {
            return HealthResult::failed('No files uploaded yet. Upload a spreadsheet for at least one stream.');
        }

        try {
            foreach ($files as $file) {
                $this->rows($source, $file['stream'])->take(1)->all();
            }
        } catch (\Throwable $e) {
            return HealthResult::failed($e->getMessage());
        }

        return HealthResult::ok(count($files).' '.Str::plural('file', count($files)).' ready');
    }

    public function streams(DataSource $source): array
    {
        return array_map(fn (array $file) => new StreamDef($file['stream']), self::files($source));
    }

    public function read(DataSource $source, StreamDef $stream, ?string $cursor): LazyCollection
    {
        $definition = $this->catalog->streams($source->platform)[$stream->name];
        $map = $this->columnMap($source, $stream->name);

        return $this->rows($source, $stream->name)->map(function (array $row) use ($definition, $map) {
            $record = [];
            foreach ($row as $header => $value) {
                $record[self::fieldName($header)] = $value;
            }
            foreach ($map as $field => $header) {
                $record[$field] = $row[$header] ?? $record[self::fieldName($header)] ?? null;
            }

            return $this->normalize($definition, $record);
        });
    }

    /**
     * The uploaded file for each stream.
     *
     * @return list<array{stream: string, name: string, size: int, uploaded_at: string}>
     */
    public static function files(DataSource $source): array
    {
        $disk = Storage::disk('local');

        return collect($disk->files(self::directory($source)))
            ->filter(fn (string $path) => in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::EXTENSIONS, true))
            ->map(fn (string $path) => [
                'stream' => pathinfo($path, PATHINFO_FILENAME),
                'name' => $disk->get("{$path}.name") ?? basename($path),
                'size' => $disk->size($path),
                'uploaded_at' => date(DATE_ATOM, $disk->lastModified($path)),
            ])
            ->sortBy('stream')
            ->values()
            ->all();
    }

    /**
     * Replace a stream's file with a new upload.
     */
    public static function store(DataSource $source, string $stream, UploadedFile $file): void
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        if (! in_array($extension, self::EXTENSIONS, true)) {
            throw new InvalidArgumentException('Upload a .csv or Excel (.xlsx, .xls) file.');
        }

        self::remove($source, $stream);
        $file->storeAs(self::directory($source), "{$stream}.{$extension}", 'local');
        Storage::disk('local')->put(self::directory($source)."/{$stream}.{$extension}.name", $file->getClientOriginalName());
    }

    public static function remove(DataSource $source, string $stream): void
    {
        $disk = Storage::disk('local');
        foreach (self::EXTENSIONS as $extension) {
            $disk->delete([self::directory($source)."/{$stream}.{$extension}", self::directory($source)."/{$stream}.{$extension}.name"]);
        }
    }

    public static function directory(DataSource $source): string
    {
        return "connections/{$source->id}";
    }

    /**
     * The header row and data rows of a stream's file, as header => value.
     *
     * @return LazyCollection<int, array<string, mixed>>
     */
    private function rows(DataSource $source, string $stream): LazyCollection
    {
        $path = collect(self::EXTENSIONS)
            ->map(fn (string $extension) => self::directory($source)."/{$stream}.{$extension}")
            ->first(fn (string $path) => Storage::disk('local')->exists($path))
            ?? throw new RuntimeException("No file uploaded for {$stream}.");
        $file = Storage::disk('local')->path($path);

        $rows = in_array(pathinfo($file, PATHINFO_EXTENSION), ['csv', 'txt'], true)
            ? $this->csvRows($file)
            : $this->excelRows($file, $source->config['sheet'] ?? null);

        return LazyCollection::make(function () use ($rows, $stream) {
            $headers = null;
            foreach ($rows as $row) {
                if ($headers === null) {
                    $headers = array_map(fn ($h) => trim((string) $h, " \t\n\r\0\x0B\u{FEFF}"), $row);
                    if (array_filter($headers) === []) {
                        throw new RuntimeException("The {$stream} file has no header row.");
                    }

                    continue;
                }
                if (array_filter($row, fn ($v) => $v !== null && $v !== '') === []) {
                    continue;
                }
                yield array_combine($headers, array_pad(array_slice($row, 0, count($headers)), count($headers), null));
            }
        });
    }

    /**
     * @return \Generator<int, list<string|null>>
     */
    private function csvRows(string $file): \Generator
    {
        $csv = new SplFileObject($file);
        $first = (string) $csv->fgets();
        $delimiter = collect([',', ';', "\t", '|'])->sortByDesc(fn (string $d) => substr_count($first, $d))->first();
        $csv->rewind();
        $csv->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD);
        $csv->setCsvControl($delimiter, '"', '\\');

        foreach ($csv as $row) {
            if (is_array($row) && $row !== [null]) {
                yield $row;
            }
        }
    }

    /**
     * @return \Generator<int, list<mixed>>
     */
    private function excelRows(string $file, ?string $sheet): \Generator
    {
        $book = IOFactory::load($file);
        $worksheet = filled($sheet) ? $book->getSheetByName($sheet) : $book->getSheet(0);

        if (! $worksheet) {
            throw new RuntimeException("There is no worksheet called \"{$sheet}\".");
        }

        foreach ($worksheet->getRowIterator() as $row) {
            $cells = [];
            foreach ($row->getCellIterator() as $cell) {
                $cells[] = $this->cellValue($cell);
            }
            yield $cells;
        }
    }

    private function cellValue(Cell $cell): mixed
    {
        $value = $cell->getCalculatedValue();

        if (is_numeric($value) && Date::isDateTime($cell)) {
            $date = Date::excelToDateTimeObject((float) $value);

            return $date->format(fmod((float) $value, 1.0) === 0.0 ? 'Y-m-d' : DateTimeInterface::ATOM);
        }

        return $value;
    }

    /**
     * @return array<string, string> canonical field => column header
     */
    private function columnMap(DataSource $source, string $stream): array
    {
        $json = $source->config['column_map'][$stream] ?? null;

        if (blank($json)) {
            return [];
        }

        $map = json_decode($json, true);
        if (! is_array($map)) {
            throw new InvalidArgumentException("The column mapping for {$stream} is not valid JSON.");
        }

        return $map;
    }

    private static function fieldName(string $header): string
    {
        return Str::snake(Str::lower(trim(preg_replace('/[^A-Za-z0-9]+/', ' ', $header))));
    }
}
