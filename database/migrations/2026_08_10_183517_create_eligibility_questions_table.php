<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eligibility_questions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('version');
            $table->string('section_key', 50);
            $table->string('code', 20);
            $table->unsignedSmallInteger('number');
            $table->string('text', 500);
            $table->boolean('disqualify_if_answer')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['version', 'code']);
            $table->index(['version', 'is_active']);
            $table->index(['version', 'section_key', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eligibility_questions');
    }
};
