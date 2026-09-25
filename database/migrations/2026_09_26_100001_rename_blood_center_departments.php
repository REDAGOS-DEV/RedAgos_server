<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Move staff onto the five-department chart.
     *
     * Inventory is renamed to Issuance and keeps its abilities, so that is a
     * straight rename. Laboratory is split in two and cannot be mapped
     * faithfully: laboratory staff land in Testing, whose abilities are a
     * subset of what Laboratory held, so the migration never grants anyone
     * something they did not already have. A supervisor moves whoever does the
     * processing bench into Processing.
     *
     * users.department is a plain string column, so no schema change is needed.
     */
    public function up(): void
    {
        DB::table('users')->where('department', 'inventory')->update(['department' => 'issuance']);
        DB::table('users')->where('department', 'laboratory')->update(['department' => 'testing']);
    }

    public function down(): void
    {
        DB::table('users')->where('department', 'issuance')->update(['department' => 'inventory']);
        DB::table('users')->whereIn('department', ['testing', 'processing'])->update(['department' => 'laboratory']);
    }
};
