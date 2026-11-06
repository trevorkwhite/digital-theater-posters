<?php

namespace Tests\Feature\Sources;

use App\Posters\Data\SourceItem;
use App\Posters\Sources\Emby\EmbyException;
use App\Posters\Sources\Emby\EmbySource;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class EmbySourceTest extends TestCase
{
    private const BASE = 'http://emby.test:8096';

    private const USER_ID = '3a7c9e1f5b2d4a6c8e0f1a2b3c4d5e6f';

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Http::preventStrayRequests();
        config([
            'posters.images.max_height' => 1600,
            'posters.images.quality' => 85,
        ]);
    }

    // ---------------------------------------------------------------- helpers

    /** @param array<string, mixed> $overrides */
    private function source(array $overrides = []): EmbySource
    {
        return new EmbySource('emby', array_merge([
            'driver' => EmbySource::class,
            'name' => 'Emby',
            'base_url' => self::BASE,
            'api_key' => 'secret-key',
            'user_id' => null,
            'library_ids' => [],
            'item_types' => ['Movie'],
            'page_size' => 200,
            'timeout' => 5,
            'retry_backoff_ms' => [10, 20],
        ], $overrides));
    }

    /** @return array<string, mixed> */
    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path("tests/Fixtures/emby/{$name}.json")), true, flags: JSON_THROW_ON_ERROR);
    }

    private static function path(Request $request): string
    {
        return (string) parse_url($request->url(), PHP_URL_PATH);
    }

    /** @return array<string, string> */
    private static function query(Request $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query;
    }

    /** @return list<SourceItem> */
    private static function collect(iterable $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $out[] = $item;
        }

        return $out;
    }

    // ------------------------------------------------------------------ items

    public function test_it_paginates_through_all_pages_and_maps_items(): void
    {
        Http::fake(function (Request $request) {
            $this->assertSame('/Items', self::path($request));
            $q = self::query($request);

            return match ($q['StartIndex']) {
                '0' => Http::response($this->fixture('items_page1')),
                '2' => Http::response($this->fixture('items_page2')),
                default => $this->fail('Unexpected StartIndex '.$q['StartIndex']),
            };
        });

        $items = self::collect($this->source(['page_size' => 2])->items());

        $this->assertCount(3, $items);
        $this->assertSame(['1001', '1002', '1003'], array_map(fn ($i) => $i->sourceItemId, $items));
        Http::assertSentCount(2);

        $alien = $items[0];
        $this->assertSame('Alien', $alien->title);
        $this->assertSame('Alien', $alien->sortTitle);
        $this->assertSame(1979, $alien->year);
        $this->assertSame('R', $alien->officialRating);
        $this->assertSame(8.2, $alien->communityRating);
        $this->assertSame(['Horror', 'Science Fiction'], $alien->genres);
        $this->assertSame('5c9e1f0a8b7d4e2f9a3b6c1d0e4f7a28', $alien->imageTag);
        $this->assertSame('movie', $alien->mediaType);
        $this->assertStringStartsWith('During its return', $alien->overview);
        $this->assertSame(['Tmdb' => '348', 'Imdb' => 'tt0078748'], $alien->extra['provider_ids']);
        $this->assertNull($alien->libraryName);

        // The Thing: SortName differs from Name.
        $this->assertSame('Thing', $items[2]->sortTitle);
    }

    public function test_run_time_ticks_are_converted_to_rounded_minutes(): void
    {
        $page = $this->fixture('items_page1');
        $page['TotalRecordCount'] = 2;
        Http::fake(fn () => Http::response($page));

        $items = self::collect($this->source()->items());

        // 70,110,000,000 ticks = 116.85 min -> 117; 70,200,000,000 = 117.0 -> 117
        $this->assertSame(117, $items[0]->runtimeMinutes);
        $this->assertSame(117, $items[1]->runtimeMinutes);
    }

    public function test_items_query_requests_primary_images_and_only_needed_fields(): void
    {
        Http::fake(fn () => Http::response($this->fixture('items_count')));

        self::collect($this->source(['item_types' => ['Movie', 'Video']])->items());

        Http::assertSent(function (Request $request) {
            $q = self::query($request);
            $fields = explode(',', $q['Fields']);

            return self::path($request) === '/Items'
                && $q['Recursive'] === 'true'
                && $q['IncludeItemTypes'] === 'Movie,Video'
                && $q['ImageTypes'] === 'Primary'
                && $q['StartIndex'] === '0'
                && $q['Limit'] === '200'
                && ! isset($q['ParentId'])
                && ! isset($q['api_key'])
                && empty(array_diff(['ProductionYear', 'SortName', 'Overview', 'OfficialRating', 'CommunityRating', 'RunTimeTicks', 'Genres', 'ProviderIds'], $fields));
        });
    }

    public function test_server_wide_endpoint_is_used_without_user_id(): void
    {
        Http::fake(fn () => Http::response($this->fixture('items_count')));

        self::collect($this->source()->items());

        Http::assertSent(fn (Request $r) => self::path($r) === '/Items');
        Http::assertNotSent(fn (Request $r) => str_contains(self::path($r), '/Users/'));
    }

    public function test_user_scoped_endpoint_is_used_with_user_id(): void
    {
        $page = $this->fixture('items_page1');
        $page['TotalRecordCount'] = 2;
        Http::fake(fn () => Http::response($page));

        $items = self::collect($this->source(['user_id' => self::USER_ID])->items());

        $this->assertCount(2, $items);
        Http::assertSent(fn (Request $r) => self::path($r) === '/Users/'.self::USER_ID.'/Items');
        Http::assertNotSent(fn (Request $r) => self::path($r) === '/Items');
    }

    public function test_unknown_user_id_gives_helpful_exception_from_items(): void
    {
        Http::fake(fn () => Http::response('User not found', 404));

        try {
            self::collect($this->source(['user_id' => 'deadbeef'])->items());
            $this->fail('Expected EmbyException');
        } catch (EmbyException $e) {
            $this->assertStringContainsString('EMBY_USER_ID', $e->getMessage());
            $this->assertStringContainsString('deadbeef', $e->getMessage());
        }
    }

    public function test_library_ids_are_iterated_and_items_deduped(): void
    {
        $movies = $this->fixture('items_page1');
        $movies['TotalRecordCount'] = 2;

        Http::fake(function (Request $request) use ($movies) {
            $q = self::query($request);

            if (isset($q['Ids'])) {
                $this->assertSame('41,77', $q['Ids']);

                return Http::response($this->fixture('libraries'));
            }

            return match ($q['ParentId'] ?? null) {
                '41' => Http::response($movies),
                '77' => Http::response($this->fixture('items_library_4k')),
                default => $this->fail('Unexpected ParentId'),
            };
        });

        $items = self::collect($this->source(['library_ids' => ['41', '77']])->items());

        // 1002 (Blade Runner) is in both libraries but only yielded once.
        $this->assertSame(['1001', '1002', '3001'], array_map(fn ($i) => $i->sourceItemId, $items));
        $this->assertSame('Movies', $items[0]->libraryName);
        $this->assertSame('Movies', $items[1]->libraryName);
        $this->assertSame('4K Movies', $items[2]->libraryName);

        Http::assertSent(fn (Request $r) => (self::query($r)['ParentId'] ?? null) === '41');
        Http::assertSent(fn (Request $r) => (self::query($r)['ParentId'] ?? null) === '77');
    }

    public function test_items_without_primary_image_are_skipped_and_malformed_items_logged(): void
    {
        Log::spy();
        Http::fake(fn () => Http::response($this->fixture('items_mixed_quality')));

        $items = self::collect($this->source()->items());

        // 2002 has no Primary tag, 2003 has no Name.
        $this->assertSame(['2001', '2004'], array_map(fn ($i) => $i->sourceItemId, $items));
        $this->assertSame(170, $items[0]->runtimeMinutes);
        $this->assertNull($items[1]->runtimeMinutes);
        $this->assertNull($items[1]->year);
        $this->assertSame([], $items[1]->genres);

        Log::shouldHaveReceived('warning')->once();
    }

    public function test_non_movie_types_map_to_lowercase_media_type(): void
    {
        Http::fake(fn () => Http::response([
            'Items' => [[
                'Name' => 'Band of Brothers', 'Id' => '9001', 'Type' => 'Series',
                'ImageTags' => ['Primary' => 'abc123'],
            ]],
            'TotalRecordCount' => 1,
        ]));

        $items = self::collect($this->source(['item_types' => ['Series']])->items());

        $this->assertSame('series', $items[0]->mediaType);
    }

    public function test_items_is_lazy(): void
    {
        Http::fake(fn () => Http::response($this->fixture('items_count')));

        $iterable = $this->source()->items();

        $this->assertInstanceOf(\Generator::class, $iterable);
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------ auth & http

    public function test_api_key_is_sent_as_header_not_query_string(): void
    {
        Http::fake(fn () => Http::response($this->fixture('items_count')));

        self::collect($this->source()->items());

        Http::assertSent(function (Request $r) {
            return $r->hasHeader('X-Emby-Token', 'secret-key')
                && str_contains($r->header('X-Emby-Authorization')[0] ?? '', 'Client="Digital Theater Posters"')
                && str_contains($r->header('X-Emby-Authorization')[0] ?? '', 'DeviceId="dtp-')
                && ! str_contains($r->url(), 'secret-key');
        });
    }

    public function test_401_throws_helpful_exception_without_retrying(): void
    {
        Http::fake(fn () => Http::response('Access token is invalid or expired.', 401));

        try {
            self::collect($this->source()->items());
            $this->fail('Expected EmbyException');
        } catch (EmbyException $e) {
            $this->assertSame(401, $e->status);
            $this->assertStringContainsString('EMBY_API_KEY', $e->getMessage());
            $this->assertStringNotContainsString('secret-key', $e->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_403_is_not_retried(): void
    {
        Http::fake(fn () => Http::response('', 403));

        $this->expectException(EmbyException::class);

        try {
            self::collect($this->source(['user_id' => self::USER_ID])->items());
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_server_errors_are_retried_then_succeed(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            return $calls < 3
                ? Http::response('Service Unavailable', 503)
                : Http::response($this->fixture('items_count'));
        });

        self::collect($this->source()->items());

        $this->assertSame(3, $calls);
    }

    public function test_persistent_server_errors_throw_after_retries(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            return Http::response('boom', 500);
        });

        try {
            self::collect($this->source()->items());
            $this->fail('Expected EmbyException');
        } catch (EmbyException $e) {
            $this->assertSame(500, $e->status);
        }

        $this->assertSame(3, $calls);
    }

    public function test_html_response_is_reported_as_wrong_url(): void
    {
        Http::fake(fn () => Http::response('<html>Router login</html>', 200, ['Content-Type' => 'text/html']));

        $this->expectException(EmbyException::class);
        $this->expectExceptionMessage('EMBY_URL');

        self::collect($this->source()->items());
    }

    public function test_base_url_is_normalized_and_emby_prefix_is_kept(): void
    {
        $this->assertSame('http://emby.test:8096', EmbySource::normalizeBaseUrl('http://emby.test:8096/'));
        $this->assertSame('http://emby.test:8096/emby', EmbySource::normalizeBaseUrl(' http://emby.test:8096/emby/ '));
        $this->assertSame('http://emby.test:8096', EmbySource::normalizeBaseUrl('http://emby.test:8096/web/index.html'));
        $this->assertSame('http://emby.test:8096', EmbySource::normalizeBaseUrl('emby.test:8096'));

        Http::fake(fn () => Http::response($this->fixture('system_info')));
        $source = $this->source(['base_url' => self::BASE.'/emby/']);
        $source->testConnection();

        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), self::BASE.'/emby/System/Info'));
    }

    // ------------------------------------------------------------- fetchImage

    private function item(): SourceItem
    {
        return new SourceItem(sourceItemId: '1001', title: 'Alien', imageTag: '5c9e1f0a8b7d4e2f9a3b6c1d0e4f7a28');
    }

    public function test_fetch_image_returns_bytes_and_mime(): void
    {
        $bytes = "\x89PNG\r\n\x1a\nfake-png-bytes";
        Http::fake(fn () => Http::response($bytes, 200, ['Content-Type' => 'image/png']));

        $image = $this->source()->fetchImage($this->item());

        $this->assertSame($bytes, $image->bytes);
        $this->assertSame('image/png', $image->mimeType);

        Http::assertSent(function (Request $r) {
            $q = self::query($r);

            return self::path($r) === '/Items/1001/Images/Primary'
                && $q['Tag'] === '5c9e1f0a8b7d4e2f9a3b6c1d0e4f7a28'
                && $q['MaxHeight'] === '1600'
                && $q['Quality'] === '85'
                && $r->hasHeader('X-Emby-Token', 'secret-key');
        });
    }

    public function test_fetch_image_defaults_to_jpeg_when_content_type_missing(): void
    {
        Http::fake(fn () => Http::response("\xFF\xD8\xFFjpeg", 200, ['Content-Type' => '']));

        $image = $this->source()->fetchImage($this->item());

        $this->assertSame('image/jpeg', $image->mimeType);
    }

    public function test_fetch_image_rejects_non_image_content_type(): void
    {
        Http::fake(fn () => Http::response('{"error":"nope"}', 200, ['Content-Type' => 'application/json; charset=utf-8']));

        $this->expectException(EmbyException::class);
        $this->expectExceptionMessage('application/json');

        $this->source()->fetchImage($this->item());
    }

    public function test_fetch_image_rejects_empty_body(): void
    {
        Http::fake(fn () => Http::response('', 200, ['Content-Type' => 'image/jpeg']));

        $this->expectException(EmbyException::class);
        $this->expectExceptionMessage('empty');

        $this->source()->fetchImage($this->item());
    }

    public function test_fetch_image_throws_on_404(): void
    {
        Http::fake(fn () => Http::response('', 404));

        $this->expectException(EmbyException::class);

        $this->source()->fetchImage($this->item());
    }

    // --------------------------------------------------------- testConnection

    public function test_connection_ok_reports_server_and_movie_count(): void
    {
        Http::fake(fn (Request $r) => match (self::path($r)) {
            '/System/Info' => Http::response($this->fixture('system_info')),
            '/Items' => Http::response($this->fixture('items_count')),
            default => $this->fail('Unexpected '.self::path($r)),
        });

        $source = $this->source();
        $this->assertSame('Emby', $source->displayName());

        $result = $source->testConnection();

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('Living Room Server', $result['message']);
        $this->assertStringContainsString('4.9.1.80', $result['message']);
        $this->assertStringContainsString('1234 movies', $result['message']);
        $this->assertSame('Emby (Living Room Server)', $source->displayName());

        Http::assertSent(fn (Request $r) => self::path($r) === '/Items' && self::query($r)['Limit'] === '0');
    }

    public function test_connection_with_user_verifies_user_and_counts_user_items(): void
    {
        Http::fake(fn (Request $r) => match (self::path($r)) {
            '/System/Info' => Http::response($this->fixture('system_info')),
            '/Users/'.self::USER_ID => Http::response($this->fixture('user')),
            '/Users/'.self::USER_ID.'/Items' => Http::response(['Items' => [], 'TotalRecordCount' => 812]),
            default => $this->fail('Unexpected '.self::path($r)),
        });

        $result = $this->source(['user_id' => self::USER_ID])->testConnection();

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertStringContainsString('"Theater"', $result['message']);
        $this->assertStringContainsString('812 movies', $result['message']);
    }

    public function test_connection_reports_unknown_user(): void
    {
        Http::fake(fn (Request $r) => match (self::path($r)) {
            '/System/Info' => Http::response($this->fixture('system_info')),
            default => Http::response('', 404),
        });

        $result = $this->source(['user_id' => 'not-a-user'])->testConnection();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('EMBY_USER_ID', $result['message']);
    }

    public function test_connection_failed_on_bad_key_never_throws(): void
    {
        Http::fake(fn () => Http::response('', 401));

        $result = $this->source()->testConnection();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('EMBY_API_KEY', $result['message']);
        Http::assertSentCount(1);
    }

    public function test_connection_failed_when_unreachable(): void
    {
        Http::fake(['*' => Http::failedConnection()]);

        $result = $this->source()->testConnection();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Could not connect', $result['message']);
        $this->assertStringContainsString('EMBY_URL', $result['message']);
    }

    public function test_connection_failed_when_api_key_missing(): void
    {
        Http::fake();

        $result = $this->source(['api_key' => null])->testConnection();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('EMBY_API_KEY', $result['message']);
        Http::assertNothingSent();
    }
}
