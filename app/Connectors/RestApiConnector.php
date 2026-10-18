<?php

namespace App\Connectors;

use App\Connectors\Concerns\NormalizesRecords;
use App\Connectors\Contracts\Connector;
use App\Models\DataSource;
use App\Sync\StreamCatalog;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Pulls any JSON API: an endpoint per stream, the array of records found at a path in each
 * response, page / cursor / next-link paging, and an optional field mapping from the API's
 * field names (dot paths) to the stream's canonical fields.
 */
class RestApiConnector implements Connector
{
    use NormalizesRecords;

    /** Stop runaway paging (a "next" link that never ends). */
    private const MAX_PAGES = 10_000;

    public function __construct(private StreamCatalog $catalog) {}

    public static function definition(): ConnectorDefinition
    {
        $paged = ['pagination' => ['cursor', 'next_url']];

        return new ConnectorDefinition(
            label: 'REST API',
            description: 'Any JSON API (accounting ERP, LMS, admissions CRM): an endpoint per stream, paging, and a field mapping onto the canonical streams.',
            category: 'Third-party',
            icon: 'globe',
            fields: [
                new ConfigField('base_url', 'Base URL', 'url', required: true, placeholder: 'https://api.vendor.com/v2'),
                new ConfigField('auth', 'Authentication', 'select', required: true, default: 'bearer', options: [
                    'none' => 'None',
                    'bearer' => 'Bearer token',
                    'basic' => 'Basic (user + password)',
                    'header' => 'API key header',
                ]),
                new ConfigField('token', 'Token', 'secret', required: true, when: ['auth' => 'bearer']),
                new ConfigField('username', 'Username', required: true, when: ['auth' => 'basic']),
                new ConfigField('password', 'Password', 'secret', required: true, when: ['auth' => 'basic']),
                new ConfigField('header_name', 'Header name', required: true, default: 'X-API-Key', when: ['auth' => 'header']),
                new ConfigField('header_value', 'API key', 'secret', required: true, when: ['auth' => 'header']),
                new ConfigField('endpoints', 'Endpoints', 'map', required: true, language: 'text', placeholder: '/students?status=active',
                    help: 'Path (and query) for each stream, relative to the base URL. Leave a stream empty to skip it.'),
                new ConfigField('records_path', 'Records path', default: 'data',
                    help: 'Where the array of records is in each response, as a dot path. Empty when the response is the array.'),
                new ConfigField('pagination', 'Paging', 'select', required: true, default: 'none', options: [
                    'none' => 'None',
                    'page' => 'Page number',
                    'cursor' => 'Cursor',
                    'next_url' => 'Next link',
                ]),
                new ConfigField('page_param', 'Page parameter', default: 'page', when: ['pagination' => 'page']),
                new ConfigField('cursor_param', 'Cursor parameter', default: 'cursor', when: ['pagination' => 'cursor']),
                new ConfigField('next_path', 'Next cursor / link path', required: true, default: 'next_cursor', when: $paged,
                    help: 'Dot path in the response to the next cursor (or next page URL); paging stops when it is empty.'),
                new ConfigField('updated_since_param', 'Updated-since parameter', placeholder: 'updated_since',
                    help: 'Query parameter for incremental syncs. Leave empty to read everything each time.'),
                new ConfigField('field_map', 'Field mapping', 'map', language: 'json', placeholder: '{"id": "uuid", "name": "profile.full_name"}',
                    help: 'JSON per stream: canonical field → the API\'s field (dot path). Unmapped fields are taken as named.'),
            ],
        );
    }

    public function test(DataSource $source): HealthResult
    {
        $stream = $this->streams($source)[0] ?? null;

        if (! $stream) {
            return HealthResult::failed('No endpoints are configured.');
        }

        try {
            $response = $this->get($source, $this->url($source, $source->config['endpoints'][$stream->name]), []);
            $records = $this->records($source, $response);

            return HealthResult::ok("Connected; {$stream->name} returned ".count($records).' records on the first page');
        } catch (Throwable $e) {
            return HealthResult::failed($e->getMessage());
        }
    }

    public function streams(DataSource $source): array
    {
        $known = $this->catalog->streams($source->platform);
        $cursorField = empty($source->config['updated_since_param']) ? null : 'updated_at';

        return collect($source->config['endpoints'] ?? [])
            ->filter(fn ($path, $stream) => isset($known[$stream]) && is_string($path) && trim($path) !== '')
            ->map(fn ($path, string $stream) => new StreamDef($stream, $cursorField))
            ->values()
            ->all();
    }

    public function read(DataSource $source, StreamDef $stream, ?string $cursor): LazyCollection
    {
        $definition = $this->catalog->streams($source->platform)[$stream->name];
        $map = $this->fieldMap($source, $stream->name);
        $config = $source->config;

        return LazyCollection::make(function () use ($source, $stream, $cursor, $definition, $map, $config) {
            $url = $this->url($source, $config['endpoints'][$stream->name]);
            $query = array_filter([$config['updated_since_param'] ?? null => $stream->cursorField ? $cursor : null], fn ($v, $k) => $k !== '' && $v !== null, ARRAY_FILTER_USE_BOTH);
            $page = 1;

            for ($i = 0; $i < self::MAX_PAGES; $i++) {
                $params = $query + match ($config['pagination'] ?? 'none') {
                    'page' => [$config['page_param'] ?? 'page' => $page],
                    default => [],
                };

                $response = $this->get($source, $url, $params);
                $records = $this->records($source, $response);

                foreach ($records as $record) {
                    yield $this->normalize($definition, $this->mapFields(is_array($record) ? $record : [], $map));
                }

                $next = filled($config['next_path'] ?? null) ? data_get($response->json(), $config['next_path']) : null;

                [$url, $query, $page, $more] = match ($config['pagination'] ?? 'none') {
                    'page' => [$url, $query, $page + 1, $records !== []],
                    'cursor' => [$url, [...$query, ($config['cursor_param'] ?? 'cursor') => $next], $page, filled($next)],
                    'next_url' => [is_string($next) ? $next : $url, [], $page, filled($next)],
                    default => [$url, $query, $page, false],
                };

                if (! $more) {
                    return;
                }
            }

            throw new RuntimeException("Stopped paging {$stream->name} after ".self::MAX_PAGES.' pages.');
        });
    }

    /**
     * @return list<mixed>
     */
    private function records(DataSource $source, Response $response): array
    {
        $path = $source->config['records_path'] ?? null;
        $records = filled($path) ? data_get($response->json(), $path) : $response->json();

        if (! is_array($records) || ! array_is_list($records)) {
            throw new RuntimeException(filled($path) ? "No array of records at \"{$path}\" in the response." : 'The response is not an array of records.');
        }

        return $records;
    }

    /**
     * @return array<string, string> canonical field => source path
     */
    private function fieldMap(DataSource $source, string $stream): array
    {
        $json = $source->config['field_map'][$stream] ?? null;

        if (blank($json)) {
            return [];
        }

        $map = json_decode($json, true);

        if (! is_array($map)) {
            throw new InvalidArgumentException("The field mapping for {$stream} is not valid JSON.");
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, string>  $map
     * @return array<string, mixed>
     */
    private function mapFields(array $record, array $map): array
    {
        if ($map === []) {
            return $record;
        }

        $mapped = $record;
        foreach ($map as $field => $path) {
            $mapped[$field] = data_get($record, $path);
        }

        return $mapped;
    }

    /**
     * GET a URL with extra query parameters, keeping any query string already in the URL (the HTTP
     * client would otherwise replace it).
     *
     * @param  array<string, mixed>  $params
     */
    private function get(DataSource $source, string $url, array $params): Response
    {
        $query = [];
        if (($at = strpos($url, '?')) !== false) {
            parse_str(substr($url, $at + 1), $query);
            $url = substr($url, 0, $at);
        }

        return $this->request($source)->get($url, [...$query, ...$params])->throw();
    }

    private function request(DataSource $source): PendingRequest
    {
        $config = $source->config;
        $request = Http::acceptJson()->timeout(60)
            ->retry(3, 1000, fn (Throwable $e) => ! $e instanceof RequestException || $e->response->serverError() || $e->response->status() === 429);

        return match ($config['auth'] ?? 'none') {
            'bearer' => $request->withToken($config['token']),
            'basic' => $request->withBasicAuth($config['username'], $config['password'] ?? ''),
            'header' => $request->withHeaders([$config['header_name'] ?? 'X-API-Key' => $config['header_value']]),
            default => $request,
        };
    }

    private function url(DataSource $source, string $path): string
    {
        return preg_match('#^https?://#i', $path) ? $path : rtrim($source->config['base_url'], '/').'/'.ltrim($path, '/');
    }
}
