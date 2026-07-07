<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donor_profiles', function (Blueprint $table) {
            $table->foreignId('donor_id')->primary()->constrained('users')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('blood_type_id')->nullable()->constrained('blood_types')->cascadeOnUpdate()->nullOnDelete();
            $table->date('last_donation_date')->nullable();
            $table->string('valid_id_number', 50)->unique();
            $table->string('profile_image_path', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('blood_type_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donor_profiles');
    }
};