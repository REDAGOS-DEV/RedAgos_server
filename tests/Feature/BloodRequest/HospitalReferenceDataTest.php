<?php

namespace Tests\Feature\BloodRequest;

use App\Enums\IndicationCode;
use App\Models\BloodComponent;
use App\Models\BloodType;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * The reference data a hospital needs to fill in a blood request form.
 *
 * The indication codes are served from the enum rather than hard-coded in the
 * client, so the dropdown cannot offer a code the API would refuse.
 */
class HospitalReferenceDataTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $requester;

    protected function setUp(): void
    {
        parent::setUp();

        $hospital = Facility::factory()->bloodBank()->approved()->create();
        $this->requester = User::factory()->bloodBankStaff($hospital)->create();

        BloodType::firstOrCreate(['code' => 'O+'], ['label' => 'O+']);
        BloodComponent::factory()->create(['name' => 'Packed RBC']);
    }

    public function test_it_serves_blood_types_components_purposes_and_priorities(): void
    {
        $this->actingAs($this->requester)
            ->getJson('/api/hospital/reference-data')
            ->assertOk()
            ->assertJsonStructure([
                'blood_types' => [['id', 'code', 'label']],
                'components' => [['id', 'name', 'indication_codes' => [['code', 'label', 'description', 'requires_explanation']]]],
                'purposes' => [['value', 'label', 'requires_patient']],
                'priorities' => [['value', 'label']],
            ]);
    }

    public function test_each_component_carries_only_its_own_indication_codes(): void
    {
        $response = $this->actingAs($this->requester)
            ->getJson('/api/hospital/reference-data')
            ->assertOk();

        $packedCells = collect($response->json('components'))->firstWhere('name', 'Packed RBC');

        $this->assertSame(
            ['R-1', 'R-2', 'R-3', 'R-4', 'R-5'],
            array_column($packedCells['indication_codes'], 'code')
        );
    }

    public function test_the_others_code_is_flagged_as_needing_an_explanation(): void
    {
        $response = $this->actingAs($this->requester)
            ->getJson('/api/hospital/reference-data')
            ->assertOk();

        $codes = collect($response->json('components'))
            ->firstWhere('name', 'Packed RBC')['indication_codes'];

        $others = collect($codes)->firstWhere('code', IndicationCode::R5->value);

        $this->assertTrue($others['requires_explanation']);
        $this->assertFalse(collect($codes)->firstWhere('code', IndicationCode::R1->value)['requires_explanation']);
    }

    public function test_priorities_are_labelled_routine_and_stat(): void
    {
        $priorities = $this->actingAs($this->requester)
            ->getJson('/api/hospital/reference-data')
            ->assertOk()
            ->json('priorities');

        // The stored value stays `emergency`; only the label reads STAT, which
        // is what the DOH form prints.
        $this->assertSame(
            [['value' => 'routine', 'label' => 'Routine'], ['value' => 'emergency', 'label' => 'STAT']],
            $priorities
        );
    }

    public function test_it_cannot_be_read_anonymously(): void
    {
        $this->getJson('/api/hospital/reference-data')->assertUnauthorized();
    }
}
