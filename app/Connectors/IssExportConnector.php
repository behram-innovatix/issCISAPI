<?php

namespace App\Connectors;

use App\Connectors\Contracts\Connector;
use App\Models\DataSource;
use App\Sync\StreamCatalog;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\LazyCollection;
use Throwable;

/**
 * Pulls from an ISS platform's cis-export API (issEduApi, HRM, Finance):
 *
 *   GET {base_url}/cis-export/{stream}?updated_since=&cursor=&per_page=
 *   → { "data": [ {record}, … ], "next_cursor": "…" | null }
 *
 * Records carry `updated_at`, which is the incremental cursor. Auth is either a static bearer token
 * (Sanctum) or OAuth2 client credentials (Passport); `entity` is sent as X-ISS-Entity for tenant scoping.
 */
class IssExportConnector implements Connector
{
    /** @var array<int, string> data source id => access token, for the lifetime of the job */
    private array $tokens = [];

    public function __construct(private StreamCatalog $catalog) {}

    public static function definition(): ConnectorDefinition
    {
        $clientCredentials = ['auth' => 'client_credentials'];

        return new ConnectorDefinition(
            label: 'ISS platform API',
            description: 'Pulls the cis-export endpoints of an ISS app such as issEduApi, HRM or Finance. Incremental by updated_at, with paging.',
            category: 'ISS platforms',
            icon: 'server',
            fields: [
                new ConfigField('base_url', 'API base URL', 'url', required: true, placeholder: 'https://isseduapi.test/api',
                    help: 'The CIS calls {base URL}/cis-export/{stream}.'),
                new ConfigField('auth', 'Authentication', 'select', required: true, default: 'token', options: [
                    'token' => 'API token (Sanctum)',
                    'client_credentials' => 'OAuth client credentials (Passport)',
                ]),
                new ConfigField('token', 'API token', 'secret', required: true, when: ['auth' => 'token'],
                    help: 'A read-only token issued by the source app.'),
                new ConfigField('token_url', 'Token URL', 'url', required: true, when: $clientCredentials, placeholder: 'https://isseduapi.test/oauth/token'),
                new ConfigField('client_id', 'Client ID', required: true, when: $clientCredentials),
                new ConfigField('client_secret', 'Client secret', 'secret', required: true, when: $clientCredentials),
                new ConfigField('scope', 'Scope', when: $clientCredentials, placeholder: 'cis-export'),
                new ConfigField('entity', 'Entity', placeholder: 'e.g. AJA',
                    help: 'Sent as X-ISS-Entity to limit the export to one school or tenant. Leave empty for all.'),
                new ConfigField('per_page', 'Page size', 'number', default: 1000, help: 'Records per request.'),
                new ConfigField('streams', 'Streams', 'list',
                    help: 'Leave empty to pull every stream of the platform.'),
            ],
        );
    }

    public function test(DataSource $source): HealthResult
    {
        $stream = $this->streams($source)[0] ?? null;

        if (! $stream) {
            return HealthResult::failed('No streams configured.');
        }

        try {
            $this->request($source)->get($this->url($source, $stream->name), ['per_page' => 1])->throw();

            return HealthResult::ok();
        } catch (Throwable $e) {
            return HealthResult::failed($e->getMessage());
        }
    }

    public function streams(DataSource $source): array
    {
        $names = ($source->config['streams'] ?? null) ?: array_keys($this->catalog->streams($source->platform));

        return array_map(fn (string $name) => new StreamDef($name, 'updated_at'), array_values($names));
    }

    public function read(DataSource $source, StreamDef $stream, ?string $cursor): LazyCollection
    {
        return LazyCollection::make(function () use ($source, $stream, $cursor) {
            $page = null;

            do {
                $response = $this->request($source)
                    ->get($this->url($source, $stream->name), array_filter([
                        'updated_since' => $cursor,
                        'cursor' => $page,
                        'per_page' => $source->config['per_page'] ?? 1000,
                    ], fn ($value) => $value !== null))
                    ->throw();

                // yield one by one: `yield from` would reuse each page's keys and chunks would overwrite records
                foreach ($response->json('data', []) as $record) {
                    yield $record;
                }

                $page = $response->json('next_cursor');
            } while ($page);
        });
    }

    private function request(DataSource $source): PendingRequest
    {
        return Http::acceptJson()
            ->withToken($this->token($source))
            ->withHeaders(array_filter(['X-ISS-Entity' => $source->config['entity'] ?? null]))
            ->timeout(60)
            ->retry(3, 1000, fn (Throwable $e) => ! $e instanceof RequestException || $e->response->serverError());
    }

    private function token(DataSource $source): string
    {
        $auth = $source->config['auth'] ?? (empty($source->config['token']) ? 'client_credentials' : 'token');

        if ($auth === 'token') {
            return $source->config['token'];
        }

        return $this->tokens[$source->id] ??= Http::asForm()
            ->acceptJson()
            ->post($source->config['token_url'], array_filter([
                'grant_type' => 'client_credentials',
                'client_id' => $source->config['client_id'],
                'client_secret' => $source->config['client_secret'],
                'scope' => $source->config['scope'] ?? null,
            ]))
            ->throw()
            ->json('access_token');
    }

    private function url(DataSource $source, string $stream): string
    {
        return rtrim($source->config['base_url'], '/')."/cis-export/{$stream}";
    }
}
