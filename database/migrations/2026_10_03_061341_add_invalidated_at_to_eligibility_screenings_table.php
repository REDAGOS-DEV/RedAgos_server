<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eligibility_screenings', function (Blueprint $table) {
            $table->timestamp('invalidated_at')
                ->nullable()
                ->index()
                ->after('valid_until');
        });
    }

    public function down(): void
    {
        Schema::table('eligibility_screenings', function (Blueprint $table) {
            $table->dropIndex(['invalidated_at']);
            $table->dropColumn('invalidated_at');
        });
    }
};
