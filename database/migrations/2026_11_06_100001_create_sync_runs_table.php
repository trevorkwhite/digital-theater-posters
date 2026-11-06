<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('source', 32);
            $table->string('status', 16)->default('running'); // running | succeeded | failed
            $table->unsignedInteger('items_seen')->default(0);
            $table->unsignedInteger('items_added')->default(0);
            $table->unsignedInteger('items_updated')->default(0);
            $table->unsignedInteger('images_downloaded')->default(0);
            $table->unsignedInteger('images_failed')->default(0);
            $table->unsignedInteger('items_deactivated')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->index(['source', 'started_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
