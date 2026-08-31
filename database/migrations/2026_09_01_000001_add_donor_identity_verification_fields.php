<?php

use App\Support\AccountIdentity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->guardAgainstNormalizationCollisions();

        Schema::table('donor_profiles', function (Blueprint $table) {
            $table->string('valid_id_type', 40)->nullable()->after('address');
            $table->string('valid_id_image_path', 255)->nullable()->after('valid_id_number');
            $table->string('identity_status', 30)->default('unsubmitted')->after('valid_id_image_path');
            $table->timestamp('identity_submitted_at')->nullable()->after('identity_status');
            $table->unsignedInteger('identity_submission_version')->default(0)->after('identity_submitted_at');
            $table->timestamp('identity_reviewed_at')->nullable()->after('identity_submission_version');
            $table->foreignId('identity_reviewed_by')->nullable()->after('identity_reviewed_at')
                ->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->string('identity_rejection_reason', 255)->nullable()->after('identity_reviewed_by');

            $table->index('identity_status');
        });

        $this->normalizeExistingIdNumbers();
    }

    public function down(): void
    {
        Schema::table('donor_profiles', function (Blueprint $table) {
            $table->dropForeign(['identity_reviewed_by']);
            $table->dropIndex(['identity_status']);
            $table->dropColumn([
                'valid_id_type',
                'valid_id_image_path',
                'identity_status',
                'identity_submitted_at',
                'identity_submission_version',
                'identity_reviewed_at',
                'identity_reviewed_by',
                'identity_rejection_reason',
            ]);
        });
    }

    /**
     * Refuse to run when two existing rows normalise to the same ID number.
     *
     * This runs before the first write rather than letting the unique index
     * reject an UPDATE halfway through: DDL is not transactional on MySQL, so an
     * abort mid-run would leave some rows normalised and some not, with no way
     * to tell which. A collision is duplicate donor records — a data problem for
     * a person to resolve, not something a migration should guess at.
     */
    private function guardAgainstNormalizationCollisions(): void
    {
        $collisions = DB::table('donor_profiles')
            ->whereNotNull('valid_id_number')
            ->pluck('valid_id_number', 'donor_id')
            ->groupBy(fn (string $number): string => AccountIdentity::normalizeValidIdNumber($number) ?? '')
            ->filter(fn ($group): bool => $group->count() > 1);

        if ($collisions->isEmpty()) {
            return;
        }

        $detail = $collisions
            ->map(fn ($group, string $normalized): string => $normalized.' <- donors '.$group->keys()->implode(', '))
            ->implode('; ');

        throw new RuntimeException(
            'Cannot normalise valid_id_number: these donors would collide and must be merged first. '.$detail
        );
    }

    /**
     * Rewrite existing ID numbers into the form every lookup now compares on.
     */
    private function normalizeExistingIdNumbers(): void
    {
        DB::table('donor_profiles')
            ->whereNotNull('valid_id_number')
            ->orderBy('donor_id')
            ->each(function (object $profile): void {
                $normalized = AccountIdentity::normalizeValidIdNumber($profile->valid_id_number);

                if ($normalized === $profile->valid_id_number) {
                    return;
                }

                DB::table('donor_profiles')
                    ->where('donor_id', $profile->donor_id)
                    ->update(['valid_id_number' => $normalized]);
            });
    }
};
