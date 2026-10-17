<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A blood centre's own wording for the emails its staff send donors.
 *
 * One row per centre per template (App\Enums\DonorEmailTemplate). A centre with
 * no row sends the built-in default, so nothing needs seeding and a centre can
 * return to the default by deleting its row.
 *
 * Plain text with {placeholders}, never HTML: the body is escaped when the
 * email is built, so a template cannot inject markup into a donor's inbox.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_email_templates', function (Blueprint $table) {
            $table->id();

            $table->foreignId('facility_id')->constrained('facilities')->cascadeOnDelete();

            $table->string('template', 40);
            $table->string('subject', 150);
            $table->text('body');

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['facility_id', 'template']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_email_templates');
    }
};
