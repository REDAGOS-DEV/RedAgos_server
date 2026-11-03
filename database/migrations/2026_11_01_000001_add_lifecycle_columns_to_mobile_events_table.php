<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A drive's planning figure and its lifecycle (drive pre-registration, phase 1).
 *
 *  - `expected_participants`: how many people the organisers expect. A planning
 *    estimate only; `max_capacity` stays the hard limit on active bookings.
 *  - `published_at`: when donors could first see and book it. Null is a draft,
 *    seen by staff alone and announced to nobody.
 *  - `registration_closed_at`: staff stopped new bookings early. The drive
 *    stays published and its bookings stand.
 *  - `cancelled_at`, `cancelled_by`, `cancellation_reason`: the drive will not
 *    happen. Its waiting bookings are cancelled with it and the donors told
 *    the reason.
 *
 * Every drive scheduled before this was visible to donors from the moment it
 * was saved, so each is backfilled as published when it was created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mobile_events', function (Blueprint $table) {
            $table->unsignedInteger('expected_participants')->nullable()->after('max_capacity');
            $table->timestamp('published_at')->nullable()->after('announcement');
            $table->timestamp('registration_closed_at')->nullable()->after('published_at');
            $table->timestamp('cancelled_at')->nullable()->after('registration_closed_at');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')
                ->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->string('cancellation_reason', 255)->nullable()->after('cancelled_by');
        });

        DB::table('mobile_events')
            ->whereNull('published_at')
            ->update(['published_at' => DB::raw('COALESCE(created_at, CURRENT_TIMESTAMP)')]);
    }

    public function down(): void
    {
        Schema::table('mobile_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn([
                'expected_participants',
                'published_at',
                'registration_closed_at',
                'cancelled_at',
                'cancellation_reason',
            ]);
        });
    }
};
