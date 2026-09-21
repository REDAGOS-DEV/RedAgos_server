<?php

namespace App\Enums;

/**
 * The clinical indication codes printed on the DOH Blood Request Form (Adult).
 *
 * These are fixed national reference data, not a per-facility setting, so they
 * live in source rather than a seeded table: a blood centre cannot invent a
 * code, and the text beside each one is the criterion a requesting physician
 * certifies against. Each code belongs to exactly one component, and the form
 * groups them under that component's heading.
 *
 * Codes are tied to a component by NAME rather than id. blood_components.name
 * is unique and is what BloodComponentSeeder seeds by, so this mapping survives
 * a reseed that renumbers the table.
 */
enum IndicationCode: string
{
    case WB1 = 'WB-1';
    case WB2 = 'WB-2';

    case R1 = 'R-1';
    case R2 = 'R-2';
    case R3 = 'R-3';
    case R4 = 'R-4';
    case R5 = 'R-5';

    case WP1 = 'WP-1';
    case WP2 = 'WP-2';
    case WP3 = 'WP-3';
    case WP4 = 'WP-4';

    case P1 = 'P-1';
    case P2 = 'P-2';
    case P3 = 'P-3';
    case P4 = 'P-4';
    case P5 = 'P-5';
    case P6 = 'P-6';

    case C1 = 'C-1';
    case C2 = 'C-2';
    case C3 = 'C-3';
    case C4 = 'C-4';

    case F1 = 'F-1';
    case F2 = 'F-2';
    case F3 = 'F-3';
    case F4 = 'F-4';
    case F5 = 'F-5';
    case F6 = 'F-6';

    /**
     * Get every accepted indication code.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get the code as it is written on the form.
     */
    public function label(): string
    {
        return $this->value;
    }

    /**
     * Get the name of the blood component this code may be claimed against.
     */
    public function componentName(): string
    {
        return match ($this) {
            self::WB1, self::WB2 => 'Whole Blood',
            self::R1, self::R2, self::R3, self::R4, self::R5 => 'Packed RBC',
            self::WP1, self::WP2, self::WP3, self::WP4 => 'Washed RBC',
            self::P1, self::P2, self::P3, self::P4, self::P5, self::P6 => 'Platelets',
            self::C1, self::C2, self::C3, self::C4 => 'Cryoprecipitate',
            self::F1, self::F2, self::F3, self::F4, self::F5, self::F6 => 'Fresh Frozen Plasma',
        };
    }

    /**
     * Get the clinical criterion the form prints beside this code.
     */
    public function description(): string
    {
        return match ($this) {
            self::WB1 => 'Active bleeding with at least one of the following: (a) loss of over 15% blood volume; (b) Hb less than 9 g/dl; (c) blood pressure decrease over 20%, or less than 90 mm Hg systolic.',
            self::WB2 => 'Others, please specify.',

            self::R1 => 'Hb less than 8 gm/dl or Hct less than 24% (if not due to treatable cause).',
            self::R2 => 'Patients receiving general anesthesia if: (a) preoperative Hb less than 8 g/dl or Hct less than 24%; (b) major bloodletting operation and Hb less than 10 g/dl or Hct less than 30%; (c) signs of hemodynamic instability or inadequate oxygen carrying capacity (symptomatic anemia).',
            self::R3 => 'Symptomatic anemia regardless of Hb level (dyspnea, syncope, postural hypotension, tachycardia, chest pains, TIA).',
            self::R4 => 'Hb less than 8 g/dl or Hct less than 24% with concomitant hemorrhage, COPD, CAD, hemoglobinopathy, sepsis.',
            self::R5 => 'Others, please specify.',

            self::WP1 => 'History of previous severe allergic transfusion reactions or anaphylactoid reactions in immunocompromised patients.',
            self::WP2 => 'Transfusion of group "O" blood during emergencies when the specific blood is not immediately available.',
            self::WP3 => 'Paroxysmal nocturnal hemoglobinuria.',
            self::WP4 => 'Others, please specify.',

            self::P1 => 'Prophylactic administration with count < 20,000 and not due to TTP, ITP, HUS.',
            self::P2 => 'Active bleeding with count < 50,000.',
            self::P3 => 'Platelet count < 50,000 and patient to undergo invasive procedure within 8 hrs.',
            self::P4 => 'Platelet count < 100,000 if surgery is on a critical area (e.g. eye, brain).',
            self::P5 => 'Massive transfusion with diffuse microvascular bleeding and no time to obtain platelet count.',
            self::P6 => 'Others, please specify.',

            self::C1 => 'Significant hypofibrinogenemia (< 100 mg/dl).',
            self::C2 => 'Hemophilia A.',
            self::C3 => 'Von Willebrand\'s disease or uremic bleeding with prolonged bleeding time.',
            self::C4 => 'Others, please specify.',

            self::F1 => 'PT or PTT > 1.5 times mid-normal range within 8 hrs. of transfusion.',
            self::F2 => 'Specific factor deficiencies not treatable with cryoprecipitate.',
            self::F3 => 'Reversal of Coumadin anticoagulation in patients who are bleeding and not treatable with vitamin K.',
            self::F4 => 'Treatment of TTP.',
            self::F5 => 'Clinical coagulopathy associated with: (a) massive transfusion (> 25 units of blood in 24 hrs.); (b) late pregnancy termination or abruptio placentae.',
            self::F6 => 'Others, please specify.',
        };
    }

    /**
     * Determine whether choosing this code obliges the requester to explain it.
     *
     * The form says each "Others" code automatically triggers a review of the
     * indication, which it cannot do unless the requester wrote down what the
     * indication actually was.
     */
    public function triggersReview(): bool
    {
        return in_array($this, [
            self::WB2, self::R5, self::WP4, self::P6, self::C4, self::F6,
        ], true);
    }

    /**
     * Get the codes claimable against one component, in the order the form lists them.
     *
     * @return array<int, self>
     */
    public static function forComponentName(string $componentName): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $code): bool => $code->componentName() === $componentName
        ));
    }

    /**
     * Determine whether this code may be claimed against the named component.
     */
    public function belongsToComponent(string $componentName): bool
    {
        return $this->componentName() === $componentName;
    }
}
