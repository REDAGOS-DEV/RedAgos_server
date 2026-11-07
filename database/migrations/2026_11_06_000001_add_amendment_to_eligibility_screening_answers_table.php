<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An answer the screening physician changed, and what the donor declared.
 *
 * `original_answer` holds the same data as `answer`, so it is ciphertext through
 * the model's `encrypted` cast and just as hidden. It is null on an answer nobody
 * changed, and on one changed from blank, where the donor had declared nothing:
 * `amended_at` is what tells those two apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eligibility_screening_answers', function (Blueprint $table) {
            $table->text('original_answer')->nullable()->after('answer');
            $table->foreignId('amended_by')
                ->nullable()
                ->after('original_answer')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('amended_at')->nullable()->after('amended_by');
        });
    }

    public function down(): void
    {
        Schema::table('eligibility_screening_answers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('amended_by');
            $table->dropColumn(['original_answer', 'amended_at']);
        });
    }
};
