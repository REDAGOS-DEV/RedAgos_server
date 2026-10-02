<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The installer tables RedAgos never used are gone, and the ones it does use are not.
 */
class UnusedTablesMigrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_unused_installer_tables_are_dropped(): void
    {
        $this->refreshDatabase();

        $this->assertFalse(Schema::hasTable('sessions'));
        $this->assertFalse(Schema::hasTable('job_batches'));
    }

    public function test_the_installer_tables_in_use_are_kept(): void
    {
        $this->refreshDatabase();

        // Tokens, password resets, notifications, rate limiting and the
        // scheduler's locks all depend on these; jobs and failed_jobs are
        // kept for queued email.
        foreach (['users', 'password_reset_tokens', 'personal_access_tokens', 'notifications', 'cache', 'cache_locks', 'jobs', 'failed_jobs'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} must exist.");
        }
    }

    public function test_rolling_back_restores_both_tables(): void
    {
        $this->refreshDatabase();

        $migration = require database_path('migrations/2026_09_27_000004_drop_unused_sessions_and_job_batches_tables.php');

        $migration->down();
        $this->assertTrue(Schema::hasTable('sessions'));
        $this->assertTrue(Schema::hasTable('job_batches'));

        $migration->up();
        $this->assertFalse(Schema::hasTable('sessions'));
        $this->assertFalse(Schema::hasTable('job_batches'));
    }

    public function test_the_welcome_page_still_works_without_a_sessions_table(): void
    {
        config(['session.driver' => 'cookie']);

        $this->get('/')->assertOk();
    }
}
