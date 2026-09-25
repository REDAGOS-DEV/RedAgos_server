<?php

namespace Tests\Feature\Donor;

use App\Enums\EligibilityStatus;
use App\Models\EligibilityQuestion;
use App\Models\EligibilityScreening;
use App\Models\User;
use App\Repository\EligibilityRepository;
use Database\Seeders\EligibilityQuestionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * The question bank across versions, and the one line that breaks everything.
 *
 * Two failure modes are guarded here, and neither announces itself.
 *
 * The first is the questionnaire version reaching a number that has no seeded
 * rows: zero questions served, the completeness check trivially satisfied, and
 * every submission recorded as a fully answered form nobody was ever asked.
 *
 * The second is scopeCurrentlyValid(). It used to filter result = eligible.
 * Every screening is now recorded `pending`, so leaving that filter in place
 * makes every new screening invisible to currentValidScreening() -- which
 * silently breaks the QR refresh and the re-screen guard without raising
 * anything at all.
 */
class QuestionnaireVersionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $donor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(EligibilityQuestionSeeder::class);
        $this->donor = User::factory()->donor()->create();
        $this->donor->donorProfile->update([
            'birth_date' => now()->subYears(30)->toDateString(),
            'gender' => 'male',
        ]);
    }

    // --- The version actually has questions behind it ----------------------

    public function test_the_configured_version_has_seeded_questions(): void
    {
        $version = (int) config('donation.questionnaire_version');

        $this->assertGreaterThan(
            0,
            EligibilityQuestion::forVersion($version)->count(),
            "Questionnaire version {$version} is configured but has no seeded questions. "
                .'Serving it would hand donors an empty form and record it as complete.'
        );
    }

    public function test_the_seeder_is_idempotent(): void
    {
        $before = EligibilityQuestion::count();

        $this->seed(EligibilityQuestionSeeder::class);
        $this->seed(EligibilityQuestionSeeder::class);

        $this->assertSame($before, EligibilityQuestion::count());
    }

    // --- Version 1 is never disturbed ---------------------------------------

    public function test_version_one_survives_the_version_two_seeder(): void
    {
        // Append-only. The counter resolves a historical answer's wording by
        // [version, code], so dropping v1 would render every screening ever
        // taken against it as a list of bare codes.
        $this->assertSame(8, EligibilityQuestion::where('version', 1)->count());
        $this->assertSame(30, EligibilityQuestion::where('version', 2)->count());
    }

    public function test_version_one_questions_stay_resolvable(): void
    {
        $map = app(EligibilityRepository::class)->questionTextMap(1);

        $this->assertCount(8, $map);
        $this->assertSame(
            'Are you currently feeling well and in good health today?',
            $map['gh_1']->text
        );
    }

    public function test_the_text_map_ignores_is_active_so_a_deactivation_cannot_blank_history(): void
    {
        EligibilityQuestion::where('version', 1)->update(['is_active' => false]);

        // scopeForVersion filters is_active, which is right for serving a form
        // and wrong for reading back one already answered.
        $this->assertSame(0, EligibilityQuestion::forVersion(1)->count());
        $this->assertCount(8, app(EligibilityRepository::class)->questionTextMap(1));
    }

    public function test_the_two_versions_share_no_codes(): void
    {
        $v1 = EligibilityQuestion::where('version', 1)->pluck('code');
        $v2 = EligibilityQuestion::where('version', 2)->pluck('code');

        // eligibility_screening_answers stores a code but not a version, so the
        // version comes only from the parent screening. Version-prefixed codes
        // make a cross-version mix-up impossible rather than merely unlikely.
        $this->assertEmpty(
            $v1->intersect($v2),
            'A code shared between versions could render one version\'s wording against the other\'s answer.'
        );
    }

    // --- The sharp edge: scopeCurrentlyValid --------------------------------

    public function test_a_pending_screening_is_found_by_the_current_valid_lookup(): void
    {
        $screening = EligibilityScreening::factory()->create([
            'donor_id' => $this->donor->id,
            'result' => EligibilityStatus::Pending,
            'valid_until' => now()->addDays(90),
        ]);

        $found = app(EligibilityRepository::class)->currentValidScreening($this->donor->id);

        $this->assertNotNull(
            $found,
            'currentValidScreening() cannot see a pending screening. Every new screening is pending, '
                .'so this breaks the QR refresh and the re-screen guard for every donor.'
        );
        $this->assertSame($screening->id, $found->id);
    }

    public function test_an_expired_screening_is_not_current_whatever_its_result(): void
    {
        EligibilityScreening::factory()->expired()->create([
            'donor_id' => $this->donor->id,
            'result' => EligibilityStatus::Pending,
        ]);

        $this->assertNull(app(EligibilityRepository::class)->currentValidScreening($this->donor->id));
    }

    public function test_a_historical_deferred_screening_still_counts_as_answered(): void
    {
        // Rows written before RedAgos stopped ruling on a donor's own answers.
        // The questionnaire was answered and has not expired, which is all
        // "currently valid" claims now.
        EligibilityScreening::factory()->deferred()->create([
            'donor_id' => $this->donor->id,
            'valid_until' => now()->addDays(30),
        ]);

        $this->assertNotNull(app(EligibilityRepository::class)->currentValidScreening($this->donor->id));
    }

    public function test_the_model_agrees_with_the_query_scope(): void
    {
        $screening = EligibilityScreening::factory()->make([
            'result' => EligibilityStatus::Pending,
            'valid_until' => now()->addDay(),
        ]);

        // isValid() and scopeCurrentlyValid() are two statements of one rule,
        // and a drift between them would be invisible until a donor was refused
        // a QR the database says they should have.
        $this->assertTrue($screening->isValid());
    }
}
