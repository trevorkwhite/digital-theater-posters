<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Single-row table: the current state of the poster screen.
        Schema::create('display_states', function (Blueprint $table) {
            $table->id();
            $table->string('mode', 16)->default('normal'); // normal | dim | black
            $table->unsignedTinyInteger('brightness')->default(100); // 0-100, 0 = fully black
            $table->unsignedInteger('fade_ms')->default(2000);
            $table->string('reason')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('version')->default(1); // bumped on every change
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('display_states');
    }
};
