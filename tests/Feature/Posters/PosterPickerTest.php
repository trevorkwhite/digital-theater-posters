<?php

namespace Tests\Feature\Posters;

use App\Models\Poster;
use App\Posters\PosterPicker;

class PosterPickerTest extends PosterTestCase
{
    private function picker(): PosterPicker
    {
        return $this->app->make(PosterPicker::class);
    }

    public function test_every_poster_is_shown_once_before_any_repeats(): void
    {
        for ($i = 1; $i <= 15; $i++) {
            $this->makePoster((string) $i);
        }

        for ($round = 1; $round <= 3; $round++) {
            $ids = [];
            for ($i = 0; $i < 15; $i++) {
                $ids[] = $this->picker()->next()->id;
            }

            $this->assertCount(15, array_unique($ids), "Round {$round} repeated a poster.");
        }

        $this->assertSame([3], Poster::pluck('show_count')->unique()->values()->all());
        $this->assertNotNull(Poster::first()->last_shown_at);
    }

    public function test_new_posters_join_the_current_round(): void
    {
        $a = $this->makePoster('a', ['show_count' => 4]);
        $b = $this->makePoster('b', ['show_count' => 4]);
        $new = $this->makePoster('new');

        $this->assertSame($new->id, $this->picker()->next()->id);
    }

    public function test_exclude_ids_are_honoured(): void
    {
        $a = $this->makePoster('a');
        $b = $this->makePoster('b');
        $c = $this->makePoster('c');

        for ($i = 0; $i < 20; $i++) {
            $picked = $this->picker()->next([$a->id, $b->id]);
            $this->assertSame($c->id, $picked->id);
        }
    }

    public function test_exclude_prefers_unexcluded_even_if_shown_more(): void
    {
        $a = $this->makePoster('a', ['show_count' => 0]);
        $b = $this->makePoster('b', ['show_count' => 5]);

        $this->assertSame($b->id, $this->picker()->next([$a->id])->id);
    }

    public function test_excluding_everything_falls_back_to_any_poster(): void
    {
        $a = $this->makePoster('a');

        $this->assertSame($a->id, $this->picker()->next([$a->id])->id);
    }

    public function test_returns_null_when_nothing_displayable(): void
    {
        $this->assertNull($this->picker()->next());

        $this->makePoster('inactive', ['is_active' => false]);
        $this->makePoster('noimage', ['image_path' => null]);

        $this->assertNull($this->picker()->next());
    }

    public function test_rating_filter_is_applied(): void
    {
        config(['posters.filters.exclude_ratings' => ['R', 'NC-17']]);
        $pg = $this->makePoster('pg', ['official_rating' => 'PG']);
        $this->makePoster('r', ['official_rating' => 'R']);
        $this->makePoster('nc', ['official_rating' => 'NC-17']);
        $unrated = $this->makePoster('unrated', ['official_rating' => null]);

        $ids = Poster::displayable()->pluck('id')->sort()->values()->all();
        $this->assertSame([$pg->id, $unrated->id], $ids);

        for ($i = 0; $i < 10; $i++) {
            $this->assertContains($this->picker()->next()->id, [$pg->id, $unrated->id]);
        }
    }

    public function test_nr_in_filter_also_excludes_unrated(): void
    {
        config(['posters.filters.exclude_ratings' => ['R', 'NR']]);
        $pg = $this->makePoster('pg', ['official_rating' => 'PG']);
        $this->makePoster('unrated', ['official_rating' => null]);
        $this->makePoster('nr', ['official_rating' => 'NR']);

        $this->assertSame([$pg->id], Poster::displayable()->pluck('id')->all());
    }
}
