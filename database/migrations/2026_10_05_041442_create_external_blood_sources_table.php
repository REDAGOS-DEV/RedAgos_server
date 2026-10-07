<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The blood services outside RedAgos a hospital receives bags from.
     *
     * A pick list rather than free text, because a bag is identified by its
     * source plus the number that source printed on it: two spellings of one
     * source would make the same bag look like two. Philippine Red Cross is
     * seeded; blood banks add others as they need them.
     */
    public function up(): void
    {
        Schema::create('external_blood_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150)->unique();
            $table->string('code', 20)->nullable()->unique();
            $table->foreignId('created_by')->nullable()
                ->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();
        });

        DB::table('external_blood_sources')->insert([
            'name' => 'Philippine Red Cross',
            'code' => 'PRC',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('external_blood_sources');
    }
};
