<?php

namespace Database\Seeders;

use App\Models\EligibilityQuestion;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class EligibilityQuestionSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed version 1 of the preliminary screening questionnaire.
     *
     * Wording and disqualification flags are transcribed from the frontend's
     * EligibilityPage.vue so the server, not the browser, owns them.
     *
     * A null `disqualify_if_answer` means the answer is recorded for blood
     * centre staff but never defers the donor on its own.
     */
    public function run(): void
    {
        $questions = [
            ['general_health', 'gh_1', 1, 'Are you currently feeling well and in good health today?', false],
            ['general_health', 'gh_2', 2, 'Do you have a fever, cold, or flu symptoms in the last 7 days?', true],
            ['general_health', 'gh_3', 3, 'Are you taking any prescription medications currently?', null],
            ['general_health', 'gh_4', 4, 'Have you donated blood in the last 90 days?', true],
            ['medical_history', 'mh_1', 5, 'Have you ever been diagnosed with HIV, Hepatitis B, or Hepatitis C?', true],
            ['medical_history', 'mh_2', 6, 'Have you had surgery or a blood transfusion in the last 12 months?', true],
            ['medical_history', 'mh_3', 7, 'Have you traveled outside the country in the last 6 months?', null],
            ['medical_history', 'mh_4', 8, 'Do you weigh at least 50 kilograms?', false],
        ];

        foreach ($questions as [$sectionKey, $code, $number, $text, $disqualifyIfAnswer]) {
            EligibilityQuestion::updateOrCreate(
                ['version' => 1, 'code' => $code],
                [
                    'section_key' => $sectionKey,
                    'number' => $number,
                    'text' => $text,
                    'disqualify_if_answer' => $disqualifyIfAnswer,
                    'is_active' => true,
                ]
            );
        }
    }
}
