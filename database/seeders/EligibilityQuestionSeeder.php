<?php

namespace Database\Seeders;

use App\Models\EligibilityQuestion;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class EligibilityQuestionSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed every version of the donor questionnaire.
     *
     * APPEND-ONLY. Never delete a version's rows, and never set is_active to
     * false on one that has screenings against it. The blood centre resolves a
     * historical answer's wording by [version, code]; removing v1 would render
     * every v1 screening ever taken as a list of bare codes.
     *
     * Idempotent on [version, code], so re-running is safe.
     */
    public function run(): void
    {
        $this->seedVersionOne();
        $this->seedVersionTwo();
    }

    /**
     * Version 1: the original eight-question pre-screen.
     *
     * Superseded by version 2 but kept active and untouched, because screenings
     * taken against it still have to render. Its wording was transcribed from
     * the frontend's EligibilityPage.vue so the server, not the browser, owned
     * it.
     */
    private function seedVersionOne(): void
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

    /**
     * Version 2: Section I-B of the DOH Blood Donor's Health Questionnaire.
     *
     * DOH-DCHD-RD-SNBC-DMS-FORM002, effective 3 July 2023. Twenty-nine numbered
     * items across the form's own six headings, in thirty rows -- question 2
     * carries a sub-question about the deferral list.
     *
     * On `disqualify_if_answer`: this is a REVIEW MARKER, not a deferral
     * trigger. Nothing in RedAgos turns a donor away on their own answers. A
     * set flag highlights that row in the blood centre's questionnaire drawer
     * so a nurse's eye lands on it; the decision is theirs, from their own
     * assessment. The form says as much on its face: "A 'YES' answer may not
     * necessarily exclude you from blood donation."
     *
     * Markers follow the version 1 precedent where one exists -- those values
     * are already in production -- plus the answers that plainly warrant a
     * second look. null means recorded and rendered plainly, with no highlight.
     */
    /**
     * Display grouping for the donor app (App\Enums\QuestionCategory).
     *
     * Only regroups what the donor sees; the DOH section, number and order
     * above are what the record and the staff view use. Every v2 code must
     * appear here: the seeder fails loudly on a missing one.
     */
    private const V2_CATEGORIES = [
        // Your health today
        'v2_ay_1' => 'health_today',
        'v2_ay_2' => 'health_today',
        'v2_ay_2b' => 'health_today',
        'v2_d3_1' => 'health_today',
        'v2_ay_3' => 'health_today',
        // Women's health
        'v2_fd_1' => 'womens_health',
        // Donations and procedures
        'v2_m3_1' => 'donations_procedures',
        'v2_m12_1' => 'donations_procedures',
        'v2_m12_2' => 'donations_procedures',
        'v2_m12_3' => 'donations_procedures',
        'v2_ev_4' => 'donations_procedures',
        // Travel and exposure
        'v2_ev_1' => 'travel_exposure',
        'v2_ev_2' => 'travel_exposure',
        'v2_m12_8' => 'travel_exposure',
        'v2_m12_9' => 'travel_exposure',
        'v2_m12_10' => 'travel_exposure',
        // Sexual history
        'v2_m12_4' => 'sexual_history',
        'v2_m12_5' => 'sexual_history',
        'v2_m12_6' => 'sexual_history',
        'v2_m12_7' => 'sexual_history',
        // Infections and risk
        'v2_ev_3' => 'infections',
        'v2_ev_5' => 'infections',
        'v2_ev_6' => 'infections',
        'v2_ev_7' => 'infections',
        'v2_ev_8' => 'infections',
        // Medical history
        'v2_ev_9' => 'medical_history',
        'v2_ev_10' => 'medical_history',
        'v2_ev_11' => 'medical_history',
        // Before you donate
        'v2_ev_12' => 'before_you_donate',
        'v2_ev_13' => 'before_you_donate',
    ];

    private function seedVersionTwo(): void
    {
        $sections = [
            [
                'key' => 'are_you',
                'title' => 'Are you',
                'number' => 1,
                'questions' => [
                    // v1 gh_1 (feeling well) and gh_2 (fever/cold/flu) both land
                    // here: the DOH wording folds them into one question.
                    ['v2_ay_1', 1, 'Feeling healthy and well today and not experiencing any signs and symptoms of COVID-19 infection such as colds, cough, fever, sore throat, generalized weakness, and diarrhea?', false],
                    ['v2_ay_2', 2, 'Currently taking medication?', null],
                    ['v2_ay_2b', 2, 'Have you taken any medications from the Deferral list?', null],
                    ['v2_ay_3', 3, 'Have you received any vaccination?', null],
                ],
            ],
            [
                'key' => 'past_3_days',
                'title' => 'In the past three days',
                'number' => 2,
                'questions' => [
                    // Aspirin defers a platelet donation, not whole blood. The
                    // canonical "recorded, never highlighted" case.
                    ['v2_d3_1', 4, 'Have you taken aspirin or anything that has aspirin in it?', null],
                ],
            ],
            [
                'key' => 'female_donors',
                'title' => 'Question No. 5 for female donors: In the past 1 and 1/2 months (6 weeks)',
                'number' => 3,
                // Asked of female donors. Offered but not required where gender
                // is unrecorded, 'other' or 'prefer_not_to_say'; omitted for
                // male donors rather than answered with a clinical falsehood.
                'applies_to_gender' => 'female',
                'questions' => [
                    ['v2_fd_1', 5, 'Have you been pregnant or are you pregnant now?', null],
                ],
            ],
            [
                'key' => 'past_3_months',
                'title' => 'In the past 3 months, have you',
                'number' => 4,
                'questions' => [
                    // v1 gh_4 asked the same thing at 90 days and flagged it.
                    // Note this sits alongside config('donation.interval_days')
                    // = 56, which is the rule that actually governs -- and is
                    // derived from blood_collections, not from this answer.
                    ['v2_m3_1', 6, 'Donated blood, platelets or plasma?', true],
                ],
            ],
            [
                'key' => 'past_12_months',
                'title' => 'In the past 12 months, have you',
                'number' => 5,
                'questions' => [
                    ['v2_m12_1', 7, 'Had a blood transfusion?', true],
                    ['v2_m12_2', 8, 'Had surgical operation? Dental operation?', true],
                    ['v2_m12_3', 9, 'Had a tattoo, ear or body piercing, accidental contact with blood, needle-stick injury and acupuncture?', true],
                    ['v2_m12_4', 10, 'Had sexual contact with high-risk individuals?', true],
                    ['v2_m12_5', 11, 'Had sexual contact with anyone in exchange material or monetary gain?', true],
                    ['v2_m12_6', 12, 'Had sexual contact with a person who has worked abroad?', null],
                    ['v2_m12_7', 13, 'Engaged in casual sex?', true],
                    ['v2_m12_8', 14, 'Lived with a person who has hepatitis?', true],
                    ['v2_m12_9', 15, 'Have you been imprisoned?', true],
                    ['v2_m12_10', 16, 'Have any of your relatives had Creutzfeldt – Jacob (Mad Cow) disease?', true],
                ],
            ],
            [
                'key' => 'have_you_ever',
                'title' => 'Have you ever',
                'number' => 6,
                'questions' => [
                    ['v2_ev_1', 17, 'Travel outside your place of residence for the past year?', null],
                    // v1 mh_3 recorded travel without flagging it: travel is a
                    // temporary deferral a nurse judges, not a bar.
                    ['v2_ev_2', 18, 'Travel outside the Philippines?', null],
                    ['v2_ev_3', 19, 'Used needles to take drugs, steroids or anything not prescribed by your doctor?', true],
                    ['v2_ev_4', 20, 'Used clotting factor concentrates?', true],
                    ['v2_ev_5', 21, 'Had a positive test for HIV or Syphilis?', true],
                    ['v2_ev_6', 22, 'Had hepatitis?', true],
                    ['v2_ev_7', 23, 'Had malaria?', true],
                    ['v2_ev_8', 24, 'Been told to have or treated for genital wart, syphilis, gonorrhea, or other Sexually Transmissible Infections?', true],
                    ['v2_ev_9', 25, 'Had any type of cancer? For example, leukemia?', true],
                    ['v2_ev_10', 26, 'Had any problems with your heart or lungs?', true],
                    ['v2_ev_11', 27, 'Had a bleeding condition or a blood disease?', true],
                    ['v2_ev_12', 28, 'Are you giving blood because you want to be tested for HIV or Hepatitis virus?', true],
                    // Not a risk question: it asks the donor to confirm they
                    // understand that a negative test does not make their blood
                    // safe. Highlighted when the answer is No.
                    ['v2_ev_13', 29, 'Are you aware that if you have HIV or Hepatitis, you can give it to someone else though you may feel well and have a negative HIV/Hepatitis test?', false, 'acknowledgement'],
                ],
            ],
        ];

        foreach ($sections as $section) {
            foreach ($section['questions'] as $question) {
                [$code, $number, $text, $disqualifyIfAnswer] = $question;

                EligibilityQuestion::updateOrCreate(
                    ['version' => 2, 'code' => $code],
                    [
                        'section_key' => $section['key'],
                        'section_title' => $section['title'],
                        'section_number' => $section['number'],
                        'category' => self::V2_CATEGORIES[$code],
                        'number' => $number,
                        'text' => $text,
                        'disqualify_if_answer' => $disqualifyIfAnswer,
                        'applies_to_gender' => $section['applies_to_gender'] ?? null,
                        'kind' => $question[4] ?? 'risk',
                        'is_active' => true,
                    ]
                );
            }
        }
    }
}
