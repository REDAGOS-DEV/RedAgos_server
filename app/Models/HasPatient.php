<?php

namespace App\Models;

/**
 * The patient a request names, read the way the DOH form prints it.
 *
 * Shared by a patient's requirement and by each facility allocation of it,
 * which carry the same patient columns.
 */
trait HasPatient
{
    /**
     * Get the patient's name as the request form prints it, if there is one.
     */
    public function patientFullName(): ?string
    {
        if ($this->patient_surname === null && $this->patient_first_name === null) {
            return null;
        }

        $given = trim(implode(' ', array_filter([
            $this->patient_first_name,
            $this->patient_middle_name,
        ])));

        $surname = mb_strtoupper((string) $this->patient_surname);

        return $given === '' ? $surname : "{$surname}, {$given}";
    }
}
