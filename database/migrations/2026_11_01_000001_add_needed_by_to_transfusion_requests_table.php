<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the hospital needs the blood, so a centre can tell what to do first
     * (project owner, 2026-11-01). Optional: not every request has a set time.
     */
    public function up(): void
    {
        Schema::table('transfusion_requests', function (Blueprint $table): void {
            $table->timestamp('needed_by')->nullable()->after('urgency_level');
        });
    }

    public function down(): void
    {
        Schema::table('transfusion_requests', function (Blueprint $table): void {
            $table->dropColumn('needed_by');
        });
    }
};
