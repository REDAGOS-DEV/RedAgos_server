<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop two tables Laravel's installer created that RedAgos never uses.
 *
 * `sessions`: the API authenticates with Sanctum bearer tokens
 * (`personal_access_tokens`), not sessions, so the only thing that ever wrote
 * here was a visit to the `/` welcome page. SESSION_DRIVER is now `cookie`.
 *
 * `job_batches`: only Bus::batch() writes to it, and nothing does.
 *
 * `jobs` and `failed_jobs` are kept on purpose. Nothing is queued today, but
 * they are what queued notification emails would need, and empty they cost
 * nothing. `cache` and `cache_locks` are in use: rate limiting and the
 * scheduler's withoutOverlapping()->onOneServer() both go through them.
 *
 * A new migration rather than an edit to the installer's own, because
 * databases that already ran those must stay consistent with their history.
 * `down()` restores both tables exactly as the installer made them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('job_batches');
    }

    public function down(): void
    {
        if (! Schema::hasTable('sessions')) {
            Schema::create('sessions', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->foreignId('user_id')->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->longText('payload');
                $table->integer('last_activity')->index();
            });
        }

        if (! Schema::hasTable('job_batches')) {
            Schema::create('job_batches', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('name');
                $table->integer('total_jobs');
                $table->integer('pending_jobs');
                $table->integer('failed_jobs');
                $table->longText('failed_job_ids');
                $table->mediumText('options')->nullable();
                $table->integer('cancelled_at')->nullable();
                $table->integer('created_at');
                $table->integer('finished_at')->nullable();
            });
        }
    }
};
