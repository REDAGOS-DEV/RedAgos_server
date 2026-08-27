<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Carry the organisation details a blood centre supplies at registration,
     * plus the admin approval trail that gates the blood_center role.
     *
     * status defaults to 'approved' deliberately: the facilities FacilitySeeder
     * already created must stay usable so donor booking keeps working. Only new
     * registrations set 'pending_approval' explicitly.
     */
    public function up(): void
    {
        Schema::table('facilities', function (Blueprint $table) {
            $table->string('doh_license_number', 50)->nullable()->unique()->after('name');
            $table->string('contact_person', 150)->nullable()->after('doh_license_number');
            $table->string('email', 150)->nullable()->after('contact_person');
            $table->string('phone', 20)->nullable()->after('email');
            $table->text('description')->nullable()->after('address');
            $table->string('status', 20)->default('approved')->after('description');
            $table->timestamp('approved_at')->nullable()->after('status');
            $table->foreignId('approved_by')->nullable()->after('approved_at')
                ->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->string('rejection_reason', 255)->nullable()->after('approved_by');
            $table->timestamp('resubmitted_at')->nullable()->after('rejection_reason');

            // The only user permitted to resubmit a rejected registration. If
            // that user is deleted this goes null and an admin must intervene —
            // a deliberate fail-closed choice.
            $table->foreignId('registration_contact_user_id')->nullable()->after('resubmitted_at')
                ->constrained('users')->cascadeOnUpdate()->nullOnDelete();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('facilities', function (Blueprint $table) {
            $table->dropForeign(['registration_contact_user_id']);
            $table->dropForeign(['approved_by']);
            $table->dropIndex(['status']);
            $table->dropUnique(['doh_license_number']);
            $table->dropColumn([
                'doh_license_number',
                'contact_person',
                'email',
                'phone',
                'description',
                'status',
                'approved_at',
                'approved_by',
                'rejection_reason',
                'resubmitted_at',
                'registration_contact_user_id',
            ]);
        });
    }
};
