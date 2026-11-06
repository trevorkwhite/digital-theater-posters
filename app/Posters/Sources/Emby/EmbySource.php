<?php

namespace App\Posters\Sources\Emby;

use App\Posters\Contracts\PosterSource;
use App\Posters\Data\FetchedImage;
use App\Posters\Data\SourceItem;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads movie posters from an Emby Server (4.8 / 4.9) over its REST API.
 *
 * Endpoints used (paths are relative to EMBY_URL, which may or may not include
 * the /emby prefix; Emby serves both, so this is purely config-driven):
 *   GET System/Info                        server name/version (needs auth)
 *   GET Users/{UserId}                     verify EMBY_USER_ID
 *   GET Items | Users/{UserId}/Items       paged library query
 *   GET Items/{Id}/Images/Primary          poster bytes
 *
 * Auth is the X-Emby-Token header so the key never appears in URLs or logs.
 */
class EmbySource implements PosterSource
{
    /** Emby RunTimeTicks are 100ns units: 10,000,000 per second, 600,000,000 per minute. */
    private const TICKS_PER_MINUTE = 600_000_000;

    private const CLIENT_NAME = 'Digital Theater Posters';

    private const CLIENT_VERSION = '1.0.0';

    private const ITEM_FIELDS = [
        'SortName',
        'ProductionYear',
        'Overview',
        'OfficialRating',
        'CommunityRating',
        'RunTimeTicks',
        'Genres',
        'ProviderIds',
        'ParentId',
    ];

    private string $baseUrl;

    private ?string $apiKey;

    private ?string $userId;

    /** @var list<string> */
    private array $libraryIds;

    /** @var list<string> */
    private array $itemTypes;

    private int $pageSize;

    private int $timeout;

    /** @var list<int> Backoff in ms between attempts; count = number of retries. */
    private array $retryBackoff;

    private ?string $serverName = null;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private string $key, private array $config)
    {
        $this->baseUrl = self::normalizeBaseUrl((string) ($config['base_url'] ?? ''));
        $this->apiKey = self::nullableString($config['api_key'] ?? null);
        $this->userId = self::nullableString($config['user_id'] ?? null);
        $this->libraryIds = self::stringList($config['library_ids'] ?? []);
        $this->itemTypes = self::stringList($config['item_types'] ?? []) ?: ['Movie'];
        $this->pageSize = max(1, min(1000, (int) ($config['page_size'] ?? 200)));
        $this->timeout = max(1, (int) ($config['timeout'] ?? 20));
        // Optional, not in config/posters.php by default: override retry backoff (ms per retry).
        $this->retryBackoff = array_values(array_map('intval', (array) ($config['retry_backoff_ms'] ?? [500, 1500, 3000])));
    }

    public function key(): string
    {
        return $this->key;
    }

    public function displayName(): string
    {
        $name = (string) ($this->config['name'] ?? 'Emby');

        return $this->serverName ? "{$name} ({$this->serverName})" : $name;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function testConnection(): array
    {
        try {
            $info = $this->getJson('System/Info');
            $this->serverName = self::nullableString($info['ServerName'] ?? null);

            $parts = [sprintf(
                'Connected to Emby server "%s"%s at %s.',
                $this->serverName ?? 'unknown',
                isset($info['Version']) ? ' (version '.$info['Version'].')' : '',
                $this->baseUrl,
            )];

            if ($this->userId !== null) {
                $user = $this->getUser();
                $parts[] = sprintf('Using the library view of user "%s".', $user['Name'] ?? $this->userId);
            }

            $libraries = $this->libraryIds ?: [null];
            $total = 0;
            foreach ($libraries as $parentId) {
                $result = $this->getJson($this->itemsPath(), $this->itemsQuery($parentId, 0, 0));
                $total += (int) ($result['TotalRecordCount'] ?? 0);
            }

            $parts[] = sprintf(
                '%d %s with poster artwork%s.',
                $total,
                $this->itemTypeLabel($total),
                $this->libraryIds ? ' in '.count($this->libraryIds).' configured '.(count($this->libraryIds) === 1 ? 'library' : 'libraries') : '',
            );

            return ['ok' => true, 'message' => implode(' ', $parts)];
        } catch (EmbyException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Unexpected error talking to Emby at '.$this->baseUrl.': '.$e->getMessage()];
        }
    }

    /**
     * @return \Generator<int, SourceItem>
     */
    public function items(): iterable
    {
        $libraryNames = $this->libraryIds ? $this->libraryNames() : [];
        /** @var array<string, true> $seen */
        $seen = [];

        foreach ($this->libraryIds ?: [null] as $parentId) {
            $startIndex = 0;

            while (true) {
                $page = $this->getJson($this->itemsPath(), $this->itemsQuery($parentId, $startIndex, $this->pageSize));
                $items = $page['Items'] ?? null;

                if (! is_array($items)) {
                    throw EmbyException::notJson($this->baseUrl, $this->itemsPath());
                }

                foreach ($items as $raw) {
                    $item = $this->mapItem($raw, $parentId !== null ? ($libraryNames[$parentId] ?? null) : null);

                    if ($item === null || isset($seen[$item->sourceItemId])) {
                        continue;
                    }

                    $seen[$item->sourceItemId] = true;

                    yield $item;
                }

                $startIndex += count($items);
                $total = isset($page['TotalRecordCount']) ? (int) $page['TotalRecordCount'] : null;

                // Prefer TotalRecordCount (the server may cap Limit below page_size);
                // fall back to "short page = last page" if the count is missing.
                $done = $total !== null ? $startIndex >= $total : count($items) < $this->pageSize;

                if (count($items) === 0 || $done) {
                    break;
                }
            }
        }
    }

    public function fetchImage(SourceItem $item): FetchedImage
    {
        $path = 'Items/'.rawurlencode($item->sourceItemId).'/Images/Primary';

        $query = array_filter([
            'Tag' => $item->imageTag !== '' ? $item->imageTag : null,
            'MaxHeight' => (int) config('posters.images.max_height', 2160) ?: null,
            'Quality' => (int) config('posters.images.quality', 90) ?: null,
        ], fn ($v) => $v !== null);

        $response = $this->send($path, $query, 'image/*');

        $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        $mime = $mime === '' ? 'image/jpeg' : $mime;

        if (! str_starts_with($mime, 'image/')) {
            throw EmbyException::badImage($item->sourceItemId, "expected an image but got content type \"{$mime}\"");
        }

        $bytes = $response->body();

        if ($bytes === '') {
            throw EmbyException::badImage($item->sourceItemId, 'the response body was empty');
        }

        return new FetchedImage($bytes, $mime === 'image/jpg' ? 'image/jpeg' : $mime);
    }

    /**
     * Map one BaseItemDto into a SourceItem. Returns null (and logs) for items
     * that cannot be used, so one bad record never fails a whole sync.
     *
     * @param  mixed  $raw
     */
    private function mapItem($raw, ?string $libraryName): ?SourceItem
    {
        try {
            if (! is_array($raw)) {
                Log::warning('Emby: skipping malformed item (not an object).', ['source' => $this->key]);

                return null;
            }

            $id = self::nullableString($raw['Id'] ?? null);
            $title = self::nullableString($raw['Name'] ?? null);

            if ($id === null || $title === null) {
                Log::warning('Emby: skipping item without Id or Name.', ['source' => $this->key, 'id' => $id, 'name' => $title]);

                return null;
            }

            $imageTag = self::nullableString($raw['ImageTags']['Primary'] ?? null);

            if ($imageTag === null) {
                // Should not happen with ImageTypes=Primary, but older servers may ignore the filter.
                return null;
            }

            $type = (string) ($raw['Type'] ?? 'Movie');
            $ticks = $raw['RunTimeTicks'] ?? null;
            $runtime = is_numeric($ticks) && $ticks > 0 ? (int) round($ticks / self::TICKS_PER_MINUTE) : null;
            $year = $raw['ProductionYear'] ?? null;
            $rating = $raw['CommunityRating'] ?? null;
            $genres = array_values(array_filter(
                is_array($raw['Genres'] ?? null) ? $raw['Genres'] : [],
                fn ($g) => is_string($g) && trim($g) !== '',
            ));
            $providerIds = is_array($raw['ProviderIds'] ?? null) ? $raw['ProviderIds'] : [];

            $extra = ['provider_ids' => $providerIds];
            if (isset($raw['ParentId']) && is_string($raw['ParentId'])) {
                $extra['parent_id'] = $raw['ParentId'];
            }

            return new SourceItem(
                sourceItemId: $id,
                title: $title,
                imageTag: $imageTag,
                mediaType: $type === 'Movie' ? 'movie' : strtolower($type),
                sortTitle: self::nullableString($raw['SortName'] ?? null),
                year: is_numeric($year) && (int) $year > 0 ? (int) $year : null,
                overview: self::nullableString($raw['Overview'] ?? null),
                officialRating: self::nullableString($raw['OfficialRating'] ?? null),
                communityRating: is_numeric($rating) ? round((float) $rating, 1) : null,
                runtimeMinutes: $runtime,
                genres: array_map('trim', $genres),
                libraryName: $libraryName,
                extra: $extra,
            );
        } catch (Throwable $e) {
            Log::warning('Emby: skipping item that could not be mapped.', [
                'source' => $this->key,
                'id' => is_array($raw) ? ($raw['Id'] ?? null) : null,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function itemsPath(): string
    {
        return $this->userId !== null ? 'Users/'.rawurlencode($this->userId).'/Items' : 'Items';
    }

    /**
     * @return array<string, string|int>
     */
    private function itemsQuery(?string $parentId, int $startIndex, int $limit): array
    {
        $query = [
            'Recursive' => 'true',
            'IncludeItemTypes' => implode(',', $this->itemTypes),
            // Server-side filter: only items that have a Primary image.
            'ImageTypes' => 'Primary',
            'Fields' => implode(',', self::ITEM_FIELDS),
            'EnableImageTypes' => 'Primary',
            'ImageTypeLimit' => 1,
            'EnableUserData' => 'false',
            // Stable order so StartIndex paging doesn't skip/duplicate.
            'SortBy' => 'SortName,ProductionYear',
            'SortOrder' => 'Ascending',
            'EnableTotalRecordCount' => 'true',
            'StartIndex' => $startIndex,
            'Limit' => $limit,
        ];

        if ($parentId !== null) {
            $query['ParentId'] = $parentId;
        }

        return $query;
    }

    /**
     * Resolve configured library IDs to names in one request. Best effort:
     * a failure here just means posters have no library_name.
     *
     * @return array<string, string>
     */
    private function libraryNames(): array
    {
        try {
            $result = $this->getJson($this->itemsPath(), [
                'Ids' => implode(',', $this->libraryIds),
                'EnableImages' => 'false',
                'EnableUserData' => 'false',
            ]);
        } catch (EmbyException $e) {
            if (in_array($e->status, [401, 403], true)) {
                throw $e;
            }
            Log::warning('Emby: could not resolve library names.', ['source' => $this->key, 'error' => $e->getMessage()]);

            return [];
        }

        $names = [];
        foreach ($result['Items'] ?? [] as $lib) {
            if (is_array($lib) && isset($lib['Id'], $lib['Name']) && is_string($lib['Id']) && is_string($lib['Name'])) {
                $names[$lib['Id']] = $lib['Name'];
            }
        }

        return $names;
    }

    /**
     * @return array<string, mixed>
     */
    private function getUser(): array
    {
        try {
            return $this->getJson('Users/'.rawurlencode((string) $this->userId));
        } catch (EmbyException $e) {
            // Emby answers 404 (or 400 for a malformed GUID) for unknown users.
            if (in_array($e->status, [400, 404], true)) {
                throw EmbyException::userNotFound($this->baseUrl, (string) $this->userId);
            }
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function getJson(string $path, array $query = []): array
    {
        $response = $this->send($path, $query, 'application/json');
        $json = $response->json();

        if (! is_array($json)) {
            throw EmbyException::notJson($this->baseUrl, $path);
        }

        return $json;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function send(string $path, array $query, string $accept): Response
    {
        if ($this->baseUrl === '') {
            throw EmbyException::missingBaseUrl();
        }
        if ($this->apiKey === null) {
            throw EmbyException::missingApiKey();
        }

        try {
            $response = $this->http($accept)->get($path, $query);
        } catch (ConnectionException $e) {
            throw EmbyException::unreachable($this->baseUrl, $e);
        }

        if ($response->successful()) {
            return $response;
        }

        $status = $response->status();

        throw match (true) {
            $status === 401 => EmbyException::unauthorized($this->baseUrl),
            $status === 403 => EmbyException::forbidden($this->baseUrl, $this->userId),
            $status === 404 && $this->userId !== null && str_starts_with($path, 'Users/') && str_ends_with($path, '/Items')
                => EmbyException::userNotFound($this->baseUrl, $this->userId),
            $status === 404 => EmbyException::notFound($this->baseUrl, $path),
            default => EmbyException::badStatus($this->baseUrl, $path, $status),
        };
    }

    private function http(string $accept): PendingRequest
    {
        $request = Http::baseUrl($this->baseUrl)
            ->withHeaders([
                'X-Emby-Token' => (string) $this->apiKey,
                'X-Emby-Authorization' => sprintf(
                    'Emby Client="%s", Device="%s", DeviceId="%s", Version="%s"',
                    self::CLIENT_NAME,
                    $this->deviceName(),
                    $this->deviceId(),
                    self::CLIENT_VERSION,
                ),
                'Accept' => $accept,
            ])
            ->timeout($this->timeout)
            ->connectTimeout(min(10, $this->timeout));

        if ($this->retryBackoff !== []) {
            // Retry connection errors, 5xx and 429; never 4xx auth/config errors.
            // throw: false so the final response comes back to send() for a friendly message.
            $backoff = $this->retryBackoff;
            $request = $request->retry(
                count($backoff) + 1,
                fn (int $attempt) => $backoff[$attempt - 1] ?? end($backoff),
                fn (Throwable $e) => $e instanceof ConnectionException
                    || ($e instanceof RequestException && ($e->response->serverError() || $e->response->status() === 429)),
                false,
            );
        }

        return $request;
    }

    private function deviceName(): string
    {
        $host = gethostname();

        return str_replace('"', '', $host !== false && $host !== '' ? $host : 'theater-pc');
    }

    /** Stable per machine + server, so Emby shows one device instead of a new one per request. */
    private function deviceId(): string
    {
        return 'dtp-'.substr(sha1($this->deviceName().'|'.$this->baseUrl), 0, 16);
    }

    private function itemTypeLabel(int $count): string
    {
        if ($this->itemTypes === ['Movie']) {
            return $count === 1 ? 'movie' : 'movies';
        }

        return $count === 1 ? 'item' : 'items';
    }

    public static function normalizeBaseUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        if (! preg_match('#^https?://#i', $url)) {
            $url = 'http://'.$url;
        }

        // Accept pasted dashboard URLs like http://host:8096/web/index.html
        $url = preg_replace('#/web(/.*)?$#i', '', $url) ?? $url;

        return rtrim($url, '/');
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($v) => is_scalar($v) ? trim((string) $v) : '',
            $value,
        ), fn ($v) => $v !== '')));
    }
}
