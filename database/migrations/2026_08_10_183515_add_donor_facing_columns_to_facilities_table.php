<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facilities', function (Blueprint $table) {
            $table->string('operating_hours', 100)->nullable()->after('address');
            $table->boolean('is_accepting_donations')->default(true)->after('operating_hours');
            $table->unsignedSmallInteger('slot_capacity')->default(4)->after('is_accepting_donations');
            $table->unsignedSmallInteger('slot_interval_minutes')->default(30)->after('slot_capacity');
            $table->time('slots_start_at')->nullable()->after('slot_interval_minutes');
            $table->time('slots_end_at')->nullable()->after('slots_start_at');

            $table->index('is_accepting_donations');
        });
    }

    public function down(): void
    {
        Schema::table('facilities', function (Blueprint $table) {
            $table->dropIndex(['is_accepting_donations']);
            $table->dropColumn([
                'operating_hours',
                'is_accepting_donations',
                'slot_capacity',
                'slot_interval_minutes',
                'slots_start_at',
                'slots_end_at',
            ]);
        });
    }
};
