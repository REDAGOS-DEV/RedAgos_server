<?php

use App\Enums\RequestPurpose;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Give a blood request the purpose it was raised for and the patient it is for.
     *
     * The DOH Blood Request Form (Adult) is the paperwork this workflow has to
     * produce, and it asks two things the table cannot answer: who the blood is
     * for, and whether there is a patient at all. A hospital blood bank raises
     * requests for both reasons — one to transfuse a named patient, one to
     * restock its own shelves — and the form's patient block is filled for the
     * first and left blank for the second.
     *
     * Purpose is separate from urgency_level on purpose. A restock can be STAT
     * and a named-patient transfusion can be routine; collapsing them into one
     * column would make one of those two states unrepresentable.
     *
     * Every patient column is nullable because a replenishment request honestly
     * has none. The conditional obligation — a transfusion must name its patient
     * — is enforced in StoreBloodRequestRequest, which is the only place that
     * knows the purpose at write time.
     */
    public function up(): void
    {
        Schema::table('blood_requests', function (Blueprint $table): void {
            $table->enum('request_purpose', RequestPurpose::values())
                ->default(RequestPurpose::PatientTransfusion->value)
                ->after('requested_by');

            $table->string('patient_surname', 100)->nullable()->after('request_purpose');
            $table->string('patient_first_name', 100)->nullable()->after('patient_surname');
            $table->string('patient_middle_name', 100)->nullable()->after('patient_first_name');
            $table->unsignedSmallInteger('patient_age')->nullable()->after('patient_middle_name');
            $table->enum('patient_sex', ['male', 'female'])->nullable()->after('patient_age');

            // The incoming queue already filters by target and status; a centre
            // triaging its queue also wants restocks apart from transfusions.
            $table->index(['target_facility_id', 'request_purpose']);
        });
    }

    public function down(): void
    {
        Schema::table('blood_requests', function (Blueprint $table): void {
            $table->dropIndex(['target_facility_id', 'request_purpose']);

            $table->dropColumn([
                'request_purpose',
                'patient_surname',
                'patient_first_name',
                'patient_middle_name',
                'patient_age',
                'patient_sex',
            ]);
        });
    }
};
