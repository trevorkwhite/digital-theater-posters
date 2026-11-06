<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Portable between SQLite (tests) and MySQL 8 utf8mb4 (production).
 * Indexed string columns are kept short so composite indexes stay well under
 * InnoDB's 3072-byte key limit (4 bytes per utf8mb4 char).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posters', function (Blueprint $table) {
            $table->id();
            $table->string('source', 32);
            $table->string('source_item_id', 128);
            $table->string('media_type', 32)->default('movie');
            $table->string('title', 500);
            $table->string('sort_title', 500)->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->text('overview')->nullable();
            $table->string('official_rating', 32)->nullable();
            $table->float('community_rating')->nullable();
            $table->unsignedSmallInteger('runtime_minutes')->nullable();
            $table->json('genres')->nullable();
            $table->string('library_name', 255)->nullable();
            $table->string('image_tag', 128)->nullable();
            $table->string('image_path', 512)->nullable();
            $table->string('image_mime', 64)->nullable();
            $table->unsignedInteger('image_width')->nullable();
            $table->unsignedInteger('image_height')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('missed_syncs')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_shown_at')->nullable();
            $table->unsignedInteger('show_count')->default(0);
            $table->json('extra')->nullable();
            $table->timestamps();

            $table->unique(['source', 'source_item_id']);
            $table->index(['is_active', 'last_shown_at']);
            // The picker's shuffle bag works on the lowest show_count bucket.
            $table->index(['is_active', 'show_count']);
            $table->index('official_rating');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posters');
    }
};
