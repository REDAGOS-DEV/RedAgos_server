<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The blood centre's create-drive form collects an hours window, the staff
     * on duty and a note for donors. None of it had anywhere to go, so the form
     * could never be saved. All four are nullable: the drives seeded before this
     * migration have no such details, and a drive is still meaningful without
     * them.
     */
    public function up(): void
    {
        Schema::table('mobile_events', function (Blueprint $table) {
            $table->time('start_time')->nullable()->after('event_date');
            $table->time('end_time')->nullable()->after('start_time');
            $table->string('assigned_staff', 255)->nullable()->after('max_capacity');
            $table->text('announcement')->nullable()->after('assigned_staff');
        });
    }

    public function down(): void
    {
        Schema::table('mobile_events', function (Blueprint $table) {
            $table->dropColumn(['start_time', 'end_time', 'assigned_staff', 'announcement']);
        });
    }
};
