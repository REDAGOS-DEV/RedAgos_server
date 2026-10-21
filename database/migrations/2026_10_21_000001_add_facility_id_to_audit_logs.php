<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tie each audit row to the facility whose trail it belongs to, for the
     * Blood Center Admin's Audit Log (project owner, 2026-10-21).
     *
     * Until now the facility could only be read back through the actor or out
     * of the JSON context, and a JSON key compares differently on PostgreSQL
     * (text) and SQLite (its stored type), so a filter on it would pass the
     * tests and miss on the real database. AuditLogger fills the column from
     * now on; this backfills the rows already written, the same way it would
     * have: the actor's facility, else the context's facility_id, else (for a
     * row nobody signed in wrote) the subject's own facility.
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->foreignId('facility_id')->nullable()->after('actor_id')
                ->constrained('facilities')->cascadeOnUpdate()->nullOnDelete();

            $table->index(['facility_id', 'created_at']);
        });

        DB::statement(
            'UPDATE audit_logs SET facility_id = (SELECT users.facility_id FROM users WHERE users.id = audit_logs.actor_id) '
            .'WHERE actor_id IS NOT NULL'
        );

        DB::table('audit_logs')
            ->whereNull('facility_id')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $facilityId = $this->facilityFor($row);

                    if ($facilityId !== null) {
                        DB::table('audit_logs')->where('id', $row->id)->update(['facility_id' => $facilityId]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex(['facility_id', 'created_at']);
            $table->dropConstrainedForeignId('facility_id');
        });
    }

    private function facilityFor(object $row): ?int
    {
        $context = is_string($row->context) ? json_decode($row->context, true) : null;

        if (is_array($context) && is_numeric($context['facility_id'] ?? null)) {
            return $this->existing((int) $context['facility_id']);
        }

        if ($row->actor_id !== null || $row->auditable_id === null) {
            return null;
        }

        // By table rather than through the models, so this migration keeps
        // running whatever the models become.
        return match ($row->auditable_type) {
            'App\\Models\\Facility' => $this->existing((int) $row->auditable_id),
            'App\\Models\\BloodUnit' => DB::table('blood_units')->where('id', $row->auditable_id)->value('facility_id'),
            'App\\Models\\User' => DB::table('users')->where('id', $row->auditable_id)->value('facility_id'),
            default => null,
        };
    }

    private function existing(int $facilityId): ?int
    {
        return DB::table('facilities')->where('id', $facilityId)->exists() ? $facilityId : null;
    }
};
