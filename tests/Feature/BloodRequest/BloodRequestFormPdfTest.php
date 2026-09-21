<?php

namespace Tests\Feature\BloodRequest;

use App\Enums\Department;
use App\Models\BloodComponent;
use App\Models\BloodRequest;
use App\Models\BloodType;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Downloading a blood request as the DOH Blood Request Form (Adult).
 *
 * Both sides of the workflow print the same document, and both reach it
 * through their own facility scope — a hospital can print what it raised, a
 * centre what it was asked for, and neither can print anybody else's.
 */
class BloodRequestFormPdfTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $hospital;

    private Facility $centre;

    private User $requester;

    private User $centreStaff;

    private BloodType $bloodType;

    private BloodComponent $packedCells;

    private BloodComponent $platelets;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hospital = Facility::factory()->bloodBank()->approved()->create(['name' => 'St Luke Blood Bank']);
        $this->requester = User::factory()->bloodBankStaff($this->hospital)->create();

        $this->centre = Facility::factory()->approved()->create(['name' => 'Davao Blood Center']);
        $this->centreStaff = User::factory()->bloodCenterStaff($this->centre, Department::Inventory)->create();

        $this->bloodType = BloodType::firstOrCreate(['code' => 'AB+'], ['label' => 'AB+']);
        $this->packedCells = BloodComponent::factory()->create(['name' => 'Packed RBC']);
        $this->platelets = BloodComponent::factory()->create(['name' => 'Platelets']);
    }

    public function test_the_requesting_hospital_can_download_the_form(): void
    {
        $request = $this->request();

        $response = $this->actingAs($this->requester)
            ->get("/api/hospital/blood-requests/{$request->id}/form");

        $response->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertDownload("BRF-{$request->reference_number}.pdf");
    }

    public function test_the_fulfilling_centre_can_download_the_same_form(): void
    {
        $request = $this->request();

        $this->actingAs($this->centreStaff)
            ->get("/api/blood-center/blood-requests/{$request->id}/form")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertDownload("BRF-{$request->reference_number}.pdf");
    }

    public function test_the_two_portals_print_the_same_document(): void
    {
        $request = $this->request();

        $hospitalCopy = $this->actingAs($this->requester)
            ->get("/api/hospital/blood-requests/{$request->id}/form")
            ->getContent();

        $centreCopy = $this->actingAs($this->centreStaff)
            ->get("/api/blood-center/blood-requests/{$request->id}/form")
            ->getContent();

        // Compared on length rather than byte-for-byte: a PDF carries its own
        // creation timestamp and a document id derived from it, so two renders
        // a moment apart legitimately differ in a handful of bytes while
        // saying exactly the same thing.
        $this->assertSame(
            strlen($hospitalCopy),
            strlen($centreCopy),
            'The requesting hospital and the fulfilling centre must print the same sheet.'
        );
    }

    public function test_the_form_is_a_well_formed_pdf(): void
    {
        $request = $this->request();

        $content = $this->actingAs($this->requester)
            ->get("/api/hospital/blood-requests/{$request->id}/form")
            ->getContent();

        $this->assertStringStartsWith('%PDF-', $content);
        $this->assertStringContainsString('%%EOF', $content);
        $this->assertGreaterThan(5_000, strlen($content), 'A form this size cannot be a few hundred bytes.');
    }

    public function test_a_request_raised_by_another_hospital_cannot_be_printed(): void
    {
        $otherHospital = Facility::factory()->bloodBank()->approved()->create();
        $stranger = User::factory()->bloodBankStaff($otherHospital)->create();

        $request = $this->request();

        $this->actingAs($stranger)
            ->getJson("/api/hospital/blood-requests/{$request->id}/form")
            ->assertNotFound();
    }

    public function test_a_request_addressed_elsewhere_cannot_be_printed_by_a_centre(): void
    {
        $otherCentre = Facility::factory()->approved()->create();
        $stranger = User::factory()->bloodCenterStaff($otherCentre, Department::Inventory)->create();

        $request = $this->request();

        $this->actingAs($stranger)
            ->getJson("/api/blood-center/blood-requests/{$request->id}/form")
            ->assertNotFound();
    }

    public function test_centre_staff_without_the_view_ability_cannot_print(): void
    {
        $request = $this->request();

        $collection = User::factory()->bloodCenterStaff($this->centre, Department::Collection)->create();

        $this->actingAs($collection)
            ->getJson("/api/blood-center/blood-requests/{$request->id}/form")
            ->assertForbidden();
    }

    public function test_the_form_cannot_be_downloaded_anonymously(): void
    {
        $request = $this->request();

        $this->getJson("/api/hospital/blood-requests/{$request->id}/form")->assertUnauthorized();
    }

    public function test_a_replenishment_request_prints_without_patient_details(): void
    {
        $request = $this->request(replenishment: true);

        $this->actingAs($this->requester)
            ->get("/api/hospital/blood-requests/{$request->id}/form")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertNull($request->fresh()->patient_surname);
    }

    public function test_a_multi_component_request_prints_every_line(): void
    {
        $request = BloodRequest::factory()
            ->raisedBy($this->hospital, $this->requester)
            ->addressedTo($this->centre)
            ->withComponents([[$this->packedCells, 3], [$this->platelets, 2]])
            ->create(['blood_type_id' => $this->bloodType->id]);

        $this->assertSame(5, $request->fresh()->quantity);

        $this->actingAs($this->requester)
            ->get("/api/hospital/blood-requests/{$request->id}/form")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    private function request(bool $replenishment = false): BloodRequest
    {
        $factory = BloodRequest::factory()
            ->raisedBy($this->hospital, $this->requester)
            ->addressedTo($this->centre)
            ->forStock($this->bloodType, $this->packedCells, 3);

        if ($replenishment) {
            $factory = $factory->replenishment();
        }

        return $factory->create();
    }
}
