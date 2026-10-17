<?php

namespace Tests\Feature\Auth;

use App\Enums\ManagementLevel;
use App\Enums\RoleName;
use App\Enums\StaffRole;
use App\Models\User;
use App\Support\DepartmentPermissions;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthorizationSweepTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * Every route that must reject an unauthenticated caller.
     *
     * @return array<string, array{string, string}>
     */
    public static function protectedRoutes(): array
    {
        return [
            'logout' => ['post', '/api/logout'],
            'logout all' => ['post', '/api/logout-all'],
            'resend verification' => ['post', '/api/email/verification-notification'],
            'current user' => ['get', '/api/user'],
            'donor dashboard' => ['get', '/api/donors/dashboard'],
            'donor profile' => ['get', '/api/donors/profile'],
            'update donor profile' => ['patch', '/api/donors/profile'],
            'update donor password' => ['post', '/api/donors/password'],
            'notification preferences' => ['patch', '/api/donors/notification-preferences'],
            'admin user list' => ['get', '/api/users'],
            'blood center profile' => ['get', '/api/blood-center/profile'],
            'blood center password' => ['post', '/api/blood-center/password'],
            'blood center reference data' => ['get', '/api/blood-center/reference-data'],
            'admin facility list' => ['get', '/api/admin/facilities'],
            'admin facility create' => ['post', '/api/admin/facilities'],
            'hospital availability search' => ['get', '/api/hospital/availability'],
            'hospital eligible facilities' => ['get', '/api/hospital/facilities'],
            'hospital request list' => ['get', '/api/hospital/blood-requests'],
            'hospital request show' => ['get', '/api/hospital/blood-requests/1'],
            'hospital request cancel' => ['post', '/api/hospital/blood-requests/1/cancel'],
            'hospital confirm receipt' => ['post', '/api/hospital/blood-requests/1/confirm-receipt'],
            'hospital notifications' => ['get', '/api/hospital/notifications'],
            'centre incoming queue' => ['get', '/api/blood-center/blood-requests'],
            'centre queue summary' => ['get', '/api/blood-center/blood-requests/summary'],
            'centre request review' => ['get', '/api/blood-center/blood-requests/1'],
            'centre allocate' => ['post', '/api/blood-center/blood-requests/1/allocate'],
            'centre reject' => ['post', '/api/blood-center/blood-requests/1/reject'],
            'centre release holds' => ['post', '/api/blood-center/blood-requests/1/release-holds'],
            'centre release' => ['post', '/api/blood-center/blood-requests/1/release'],
            'centre request history' => ['get', '/api/blood-center/blood-requests/1/history'],
            'centre close line' => ['post', '/api/blood-center/blood-requests/1/items/1/close'],
            'centre walk-in reference' => ['get', '/api/blood-center/blood-requests/walk-in/reference'],
            'centre walk-in duplicates' => ['post', '/api/blood-center/blood-requests/walk-in/duplicates'],
            'centre walk-in record' => ['post', '/api/blood-center/blood-requests/walk-in'],
            'hospital request history' => ['get', '/api/hospital/blood-requests/1/history'],
            'hospital close line' => ['post', '/api/hospital/blood-requests/1/items/1/close'],
            'hospital transfusion list' => ['get', '/api/hospital/transfusion-requests'],
            'hospital transfusion create' => ['post', '/api/hospital/transfusion-requests'],
            'hospital transfusion draft sourcing' => ['post', '/api/hospital/transfusion-requests/sourcing'],
            'hospital patient matches' => ['post', '/api/hospital/transfusion-requests/patient-matches'],
            'hospital transfusion track' => ['get', '/api/hospital/transfusion-requests/track/PTR-1-0001'],
            'hospital transfusion show' => ['get', '/api/hospital/transfusion-requests/1'],
            'hospital transfusion history' => ['get', '/api/hospital/transfusion-requests/1/history'],
            'hospital transfusion sourcing' => ['get', '/api/hospital/transfusion-requests/1/sourcing'],
            'hospital transfusion add allocations' => ['post', '/api/hospital/transfusion-requests/1/allocations'],
            'hospital transfusion withdraw allocation' => ['post', '/api/hospital/transfusion-requests/1/allocations/1/withdraw'],
            'hospital transfusion close line' => ['post', '/api/hospital/transfusion-requests/1/items/1/close'],
            'hospital transfusion cancel' => ['post', '/api/hospital/transfusion-requests/1/cancel'],
            'hospital inventory list' => ['get', '/api/hospital/inventory'],
            'hospital inventory summary' => ['get', '/api/hospital/inventory/summary'],
            'hospital inventory tag events' => ['get', '/api/hospital/inventory/tag-events'],
            'hospital stock thresholds' => ['get', '/api/hospital/inventory/thresholds'],
            'hospital save stock thresholds' => ['put', '/api/hospital/inventory/thresholds'],
            'hospital inventory show' => ['get', '/api/hospital/inventory/RA1-1-01'],
            'hospital inventory tag' => ['post', '/api/hospital/inventory/RA1-1-01/tag'],
            'hospital inventory crossmatch' => ['post', '/api/hospital/inventory/RA1-1-01/crossmatch'],
            'hospital inventory transfuse' => ['post', '/api/hospital/inventory/RA1-1-01/transfuse'],
            'hospital inventory release' => ['post', '/api/hospital/inventory/RA1-1-01/release'],
            'hospital inventory return' => ['post', '/api/hospital/inventory/RA1-1-01/return'],
            'hospital inventory discard' => ['post', '/api/hospital/inventory/RA1-1-01/discard'],
            'hospital request schedules' => ['get', '/api/hospital/replenishment-schedules'],
            'hospital request schedule save' => ['put', '/api/hospital/replenishment-schedules/1'],
            'hospital request schedule delete' => ['delete', '/api/hospital/replenishment-schedules/1'],
            'hospital weekly requests' => ['get', '/api/hospital/weekly-requests'],
            'hospital weekly request send' => ['post', '/api/hospital/weekly-requests'],
            'hospital weekly request status' => ['get', '/api/hospital/weekly-requests/status'],
            'hospital weekly request show' => ['get', '/api/hospital/weekly-requests/1'],
            'hospital direct distributions' => ['get', '/api/hospital/direct-distributions'],
            'hospital direct distribution record' => ['post', '/api/hospital/direct-distributions'],
            'hospital external sources' => ['get', '/api/hospital/external-blood-sources'],
            'hospital external source add' => ['post', '/api/hospital/external-blood-sources'],
            'centre billing show' => ['get', '/api/blood-center/billings/1'],
            'centre record payment' => ['post', '/api/blood-center/billings/1/payments'],
            'centre statement list' => ['get', '/api/blood-center/billings/1/statements'],
            'centre statement issue' => ['post', '/api/blood-center/billings/1/statements'],
            'centre statement pdf' => ['get', '/api/blood-center/statements/1/pdf'],
            'centre checkout' => ['post', '/api/blood-center/billings/1/checkout'],
            'centre checkout attempts' => ['get', '/api/blood-center/billings/1/attempts'],
            'centre checkout supersede' => ['post', '/api/blood-center/billings/1/attempts/1/supersede'],
            'centre checkout reverify' => ['post', '/api/blood-center/billings/1/attempts/1/reverify'],
            'centre checkout close' => ['post', '/api/blood-center/billings/1/attempts/1/close'],
            'centre receipt pdf' => ['get', '/api/blood-center/receipts/1/pdf'],
            'hospital billing' => ['get', '/api/hospital/blood-requests/1/billing'],
            'hospital statement pdf' => ['get', '/api/hospital/statements/1/pdf'],
            'hospital receipt pdf' => ['get', '/api/hospital/receipts/1/pdf'],
            'centre notifications' => ['get', '/api/blood-center/notifications'],
            'testing immunohematology' => ['post', '/api/blood-center/laboratory/donations/1/immunohematology'],
            'testing serology' => ['post', '/api/blood-center/laboratory/donations/1/serology'],
            'testing referral list' => ['get', '/api/blood-center/laboratory/referrals'],
            'testing referral update' => ['patch', '/api/blood-center/laboratory/referrals/1'],
            'daily stock report' => ['get', '/api/blood-center/inventory/stock-report'],
            'daily stock report pdf' => ['get', '/api/blood-center/inventory/stock-report/pdf'],
            'component shelf lives' => ['get', '/api/blood-center/blood-components'],
            'add component' => ['post', '/api/blood-center/blood-components'],
            'set shelf life' => ['patch', '/api/blood-center/blood-components/1'],
            'component prices' => ['get', '/api/blood-center/component-prices'],
            'save prices' => ['put', '/api/blood-center/component-prices'],
            'facility logo upload' => ['post', '/api/blood-center/facility/logo'],
            'final bag labels' => ['get', '/api/blood-center/inventory/donations/1/labels'],
            'facility logo removal' => ['delete', '/api/blood-center/facility/logo'],
            'donor email templates' => ['get', '/api/blood-center/facility/email-templates'],
            'save donor email template' => ['put', '/api/blood-center/facility/email-templates/thank_you'],
            'reset donor email template' => ['delete', '/api/blood-center/facility/email-templates/thank_you'],
            'donor export' => ['get', '/api/blood-center/donors/export'],
            'donor reminder preview' => ['get', '/api/blood-center/donors/eligibility-reminders'],
            'donor reminders' => ['post', '/api/blood-center/donors/eligibility-reminders'],
            'edit donor' => ['patch', '/api/blood-center/donors/00000000-0000-4000-8000-000000000000'],
            'donor photo' => ['post', '/api/blood-center/donors/00000000-0000-4000-8000-000000000000/photo'],
            'email donor' => ['post', '/api/blood-center/donors/00000000-0000-4000-8000-000000000000/email'],
            'collection dashboard' => ['get', '/api/blood-center/collection/dashboard'],
            'appointment list' => ['get', '/api/blood-center/appointments'],
            'appointment export' => ['get', '/api/blood-center/appointments/export'],
            'appointment options' => ['get', '/api/blood-center/appointments/options'],
            'book appointment' => ['post', '/api/blood-center/appointments'],
            'cancel appointment' => ['post', '/api/blood-center/appointments/1/cancel'],
            'mark appointment collected' => ['post', '/api/blood-center/appointments/1/collected'],
            'donation records' => ['get', '/api/blood-center/donation-records'],
            'donation records export' => ['get', '/api/blood-center/donation-records/export'],
            'delete appointment' => ['delete', '/api/blood-center/appointments/1'],
            'counter donor search' => ['post', '/api/blood-center/collection/donor-search'],
            'print questionnaire' => ['get', '/api/blood-center/donors/00000000-0000-4000-8000-000000000000/health-questionnaire/print'],
            'onsite questionnaire form' => ['get', '/api/blood-center/donors/00000000-0000-4000-8000-000000000000/health-questionnaire/form'],
            'record onsite questionnaire' => ['post', '/api/blood-center/donors/00000000-0000-4000-8000-000000000000/health-questionnaire'],
        ];
    }

    #[DataProvider('protectedRoutes')]
    public function test_protected_routes_reject_unauthenticated_callers(string $method, string $uri): void
    {
        $this->json($method, $uri)->assertUnauthorized();
    }

    public function test_the_admin_user_endpoints_reject_a_donor(): void
    {
        $donor = User::factory()->donor()->create();

        $this->actingAs($donor)->getJson('/api/users')->assertForbidden();
    }

    public function test_the_admin_user_endpoints_accept_an_admin(): void
    {
        $admin = User::factory()->withRole(RoleName::Admin)->create();

        $this->actingAs($admin)->getJson('/api/users')->assertOk();
    }

    public function test_a_user_with_no_roles_is_refused_by_the_role_middleware(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/users')->assertForbidden();
    }

    /**
     * Every blood-centre route behind a department ability, with the ability it demands.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function departmentGatedRoutes(): array
    {
        return [
            'reference data' => ['get', '/api/blood-center/reference-data', 'reference.view'],
            'inventory list' => ['get', '/api/blood-center/inventory', 'inventory.view'],
            'inventory summary' => ['get', '/api/blood-center/inventory/summary', 'inventory.view'],
            'inventory activity' => ['get', '/api/blood-center/inventory/activity', 'inventory.view'],
            'stock thresholds' => ['get', '/api/blood-center/inventory/thresholds', 'inventory.view'],
            'save stock thresholds' => ['put', '/api/blood-center/inventory/thresholds', 'inventory.thresholds'],
            'daily stock report' => ['get', '/api/blood-center/inventory/stock-report', 'inventory.stock_report'],
            'component shelf lives' => ['get', '/api/blood-center/blood-components', 'components.configure'],
            'add component' => ['post', '/api/blood-center/blood-components', 'components.configure'],
            'set shelf life' => ['patch', '/api/blood-center/blood-components/1', 'components.configure'],
            'component prices' => ['get', '/api/blood-center/component-prices', 'billing.prices'],
            'save prices' => ['put', '/api/blood-center/component-prices', 'billing.prices'],
            'record units' => ['post', '/api/blood-center/inventory', 'inventory.create'],
            'update unit' => ['patch', '/api/blood-center/inventory/RA1-1-01', 'inventory.update'],
            'discard unit' => ['post', '/api/blood-center/inventory/RA1-1-01/discard', 'inventory.discard'],
            'incoming queue' => ['get', '/api/blood-center/blood-requests', 'requests.view'],
            'queue summary' => ['get', '/api/blood-center/blood-requests/summary', 'requests.view'],
            'request review' => ['get', '/api/blood-center/blood-requests/1', 'requests.view'],
            'allocate units' => ['post', '/api/blood-center/blood-requests/1/allocate', 'requests.process'],
            'reject request' => ['post', '/api/blood-center/blood-requests/1/reject', 'requests.approve'],
            'release holds' => ['post', '/api/blood-center/blood-requests/1/release-holds', 'requests.approve'],
            'release units' => ['post', '/api/blood-center/blood-requests/1/release', 'requests.release'],
            'request history' => ['get', '/api/blood-center/blood-requests/1/history', 'requests.view'],
            'close request line' => ['post', '/api/blood-center/blood-requests/1/items/1/close', 'requests.approve'],
            'walk-in reference' => ['get', '/api/blood-center/blood-requests/walk-in/reference', 'requests.record'],
            'walk-in duplicates' => ['post', '/api/blood-center/blood-requests/walk-in/duplicates', 'requests.record'],
            'record walk-in' => ['post', '/api/blood-center/blood-requests/walk-in', 'requests.record'],
            'billing show' => ['get', '/api/blood-center/billings/1', 'billing.view'],
            'record payment' => ['post', '/api/blood-center/billings/1/payments', 'billing.record_payment'],
            'statement list' => ['get', '/api/blood-center/billings/1/statements', 'billing.view'],
            'issue statement' => ['post', '/api/blood-center/billings/1/statements', 'billing.record_payment'],
            'statement pdf' => ['get', '/api/blood-center/statements/1/pdf', 'billing.view'],
            'open checkout' => ['post', '/api/blood-center/billings/1/checkout', 'billing.record_payment'],
            'checkout attempts' => ['get', '/api/blood-center/billings/1/attempts', 'billing.record_payment'],
            'supersede checkout' => ['post', '/api/blood-center/billings/1/attempts/1/supersede', 'billing.record_payment'],
            'reverify checkout' => ['post', '/api/blood-center/billings/1/attempts/1/reverify', 'billing.record_payment'],
            'close checkout' => ['post', '/api/blood-center/billings/1/attempts/1/close', 'billing.record_payment'],
            'receipt pdf' => ['get', '/api/blood-center/receipts/1/pdf', 'billing.record_payment'],
            'donor list' => ['get', '/api/blood-center/donors', 'donors.view_contact'],
            'donor lookup' => ['get', '/api/blood-center/donors/lookup', 'appointments.verify'],
            'donor history' => ['get', '/api/blood-center/donors/00000000-0000-4000-8000-000000000000/history', 'donors.view_clinical'],
            'donor export' => ['get', '/api/blood-center/donors/export', 'donors.view'],
            'donor reminder preview' => ['get', '/api/blood-center/donors/eligibility-reminders', 'donors.manage'],
            'donor reminders' => ['post', '/api/blood-center/donors/eligibility-reminders', 'donors.manage'],
            'edit donor' => ['patch', '/api/blood-center/donors/00000000-0000-4000-8000-000000000000', 'donors.manage'],
            'donor photo' => ['post', '/api/blood-center/donors/00000000-0000-4000-8000-000000000000/photo', 'donors.manage'],
            'email donor' => ['post', '/api/blood-center/donors/00000000-0000-4000-8000-000000000000/email', 'donors.manage'],
            'collection dashboard' => ['get', '/api/blood-center/collection/dashboard', 'donations.view'],
            'appointment list' => ['get', '/api/blood-center/appointments', 'appointments.view'],
            'appointment export' => ['get', '/api/blood-center/appointments/export', 'appointments.view'],
            'appointment options' => ['get', '/api/blood-center/appointments/options', 'appointments.manage'],
            'book appointment' => ['post', '/api/blood-center/appointments', 'appointments.manage'],
            'cancel appointment' => ['post', '/api/blood-center/appointments/1/cancel', 'appointments.manage'],
            'mark appointment collected' => ['post', '/api/blood-center/appointments/1/collected', 'appointments.verify'],
            'donation records' => ['get', '/api/blood-center/donation-records', 'donations.view'],
            'donation records export' => ['get', '/api/blood-center/donation-records/export', 'donations.view'],
            'delete appointment' => ['delete', '/api/blood-center/appointments/1', 'appointments.delete'],
            'donor email templates' => ['get', '/api/blood-center/facility/email-templates', 'center.configure'],
            'open donation' => ['post', '/api/blood-center/donations', 'donations.register'],
            'record screening' => ['post', '/api/blood-center/donations/1/screening', 'donations.screen'],
            'record collection' => ['post', '/api/blood-center/donations/1/collection', 'donations.collect'],
            'close donation' => ['patch', '/api/blood-center/donations/1/status', 'donations.close'],
            'paper sheets' => ['get', '/api/blood-center/paper-sheets', 'donations.record_paper'],
            'paper sheet options' => ['get', '/api/blood-center/paper-sheets/options', 'donations.record_paper'],
            'open paper sheet' => ['post', '/api/blood-center/paper-sheets', 'donations.record_paper'],
            'paper sheet' => ['get', '/api/blood-center/paper-sheets/1', 'donations.record_paper'],
            'delete paper sheet' => ['delete', '/api/blood-center/paper-sheets/1', 'donations.record_paper'],
            'register paper donor' => ['post', '/api/blood-center/paper-sheets/donors', 'donations.record_paper'],
            'record paper donation' => ['post', '/api/blood-center/paper-sheets/1/donations', 'donations.record_paper'],
            'remove paper donation' => ['delete', '/api/blood-center/paper-sheets/1/donations/1', 'donations.record_paper'],
            'record immunohematology' => ['post', '/api/blood-center/laboratory/donations/1/immunohematology', 'lab.record_immunohematology'],
            'record serology' => ['post', '/api/blood-center/laboratory/donations/1/serology', 'lab.record_serology'],
            'referral list' => ['get', '/api/blood-center/laboratory/referrals', 'lab.referrals'],
            'staff roles catalogue' => ['get', '/api/blood-center/staff/roles', 'staff.manage'],
            'release from quarantine' => ['post', '/api/blood-center/inventory/quarantine/1/release', 'inventory.release_quarantine'],
            'correction list' => ['get', '/api/blood-center/corrections', 'corrections.request'],
            'request correction' => ['post', '/api/blood-center/donations/1/corrections', 'corrections.request'],
            'request unit correction' => ['post', '/api/blood-center/inventory/RA1-1-01/corrections', 'corrections.request'],
            'request dispatch correction' => ['post', '/api/blood-center/allocations/1/corrections', 'corrections.request'],
            'request payment correction' => ['post', '/api/blood-center/payments/1/corrections', 'corrections.request'],
            'payment list' => ['get', '/api/blood-center/billings/1/payments', 'billing.record_payment'],
            'approve correction' => ['post', '/api/blood-center/corrections/1/approve', 'corrections.approve'],
            'reject correction' => ['post', '/api/blood-center/corrections/1/reject', 'corrections.approve'],
            // Last on purpose: every plain `throttle:N,1` route shares one
            // per-user counter, so rows placed earlier push the tighter limits
            // above (reminders at 10) into a 429 before their gate is reached.
            'counter donor search' => ['post', '/api/blood-center/collection/donor-search', 'appointments.verify'],
            'print questionnaire' => ['get', '/api/blood-center/donors/00000000-0000-4000-8000-000000000000/health-questionnaire/print', 'donors.print_questionnaire'],
            'onsite questionnaire form' => ['get', '/api/blood-center/donors/00000000-0000-4000-8000-000000000000/health-questionnaire/form', 'donors.record_questionnaire'],
            'record onsite questionnaire' => ['post', '/api/blood-center/donors/00000000-0000-4000-8000-000000000000/health-questionnaire', 'donors.record_questionnaire'],
        ];
    }

    /**
     * Blood-centre staff holding the access role but no staff role must be refused everywhere.
     *
     * This is the regression that matters most: before departments existed the
     * access role alone opened all of these, so an account that slips through
     * without an assignment must fail closed rather than inherit the old
     * behaviour.
     */
    #[DataProvider('departmentGatedRoutes')]
    public function test_department_gated_routes_reject_staff_with_no_role(string $method, string $uri): void
    {
        $staff = User::factory()->bloodCenterStaff()->create();
        $staff->staff_role = null;
        $staff->save();

        $this->actingAs($staff->fresh())->json($method, $uri)->assertForbidden();
    }

    /**
     * Each role reaches exactly the gated routes whose ability it holds.
     *
     * The route table above states the ability each route demands, and the
     * matrix states what each role holds; this is the check that the two
     * agree on every pairing, not just that a supervisor gets through.
     */
    public function test_each_role_is_admitted_exactly_where_the_matrix_says(): void
    {
        foreach (StaffRole::cases() as $role) {
            $staff = User::factory()->bloodCenterStaff(null, $role)->create();
            $holds = DepartmentPermissions::forRole($role);

            foreach (self::departmentGatedRoutes() as $name => [$method, $uri, $ability]) {
                $status = $this->actingAs($staff)->json($method, $uri)->getStatusCode();

                if (in_array($ability, $holds, true)) {
                    $this->assertNotSame(403, $status, "{$role->value} holds [{$ability}] but was refused {$name}.");
                } else {
                    $this->assertSame(403, $status, "{$role->value} lacks [{$ability}] but reached {$name}.");
                }
            }
        }
    }

    #[DataProvider('departmentGatedRoutes')]
    public function test_every_department_gated_route_demands_a_declared_ability(string $method, string $uri, string $ability): void
    {
        $this->assertContains(
            $ability,
            DepartmentPermissions::all(),
            "Route [{$uri}] is gated on [{$ability}], which the matrix does not declare."
        );
    }

    /**
     * The same check for the two management levels, which hold no role.
     *
     * Only the gate is under test, so anything other than 403 is a pass — a
     * 404 or 422 past the gate is the middleware having let them through.
     */
    public function test_each_management_level_is_admitted_exactly_where_the_matrix_says(): void
    {
        foreach (ManagementLevel::cases() as $level) {
            $user = match ($level) {
                ManagementLevel::Admin => User::factory()->bloodCenterAdmin()->create(),
                ManagementLevel::Supervisor => User::factory()->bloodCenterSupervisor()->create(),
            };
            $holds = DepartmentPermissions::forLevel($level);

            foreach (self::departmentGatedRoutes() as $name => [$method, $uri, $ability]) {
                $status = $this->actingAs($user)->json($method, $uri)->getStatusCode();

                if (in_array($ability, $holds, true)) {
                    $this->assertNotSame(403, $status, "The {$level->value} holds [{$ability}] but was refused {$name}.");
                } else {
                    $this->assertSame(403, $status, "The {$level->value} lacks [{$ability}] but reached {$name}.");
                }
            }
        }
    }

    public function test_a_donor_cannot_reach_a_blood_center_route_even_with_a_staff_role_set(): void
    {
        // staff_role is a plain column, so it can be set on an account that
        // holds no blood_center access role. role: must still refuse first.
        $donor = User::factory()->donor()->create();
        $donor->staff_role = StaffRole::InventoryControlOfficer;
        $donor->save();

        $this->actingAs($donor->fresh())
            ->getJson('/api/blood-center/inventory')
            ->assertForbidden();
    }

    public function test_public_auth_routes_do_not_require_a_token(): void
    {
        $this->postJson('/api/login', [])->assertStatus(422);
        $this->postJson('/api/forgot-password', [])->assertStatus(422);
        $this->postJson('/api/reset-password', [])->assertStatus(422);
        $this->postJson('/api/donors/register', [])->assertStatus(422);
    }

    /**
     * Donor registration is the only self-service account creation left.
     *
     * Both facility types are onboarded by a Super Admin, so a public caller
     * must find no route at all rather than a validation error that suggests
     * one exists behind the right payload.
     *
     * @return array<string, array{string, string}>
     */
    public static function removedPublicFacilityRoutes(): array
    {
        return [
            'blood center register' => ['post', '/api/blood-center/register'],
            'blood center registration status' => ['get', '/api/blood-center/registration-status'],
            'blood center resubmit' => ['post', '/api/blood-center/registration/resubmit'],
            'blood bank register' => ['post', '/api/blood-bank/register'],
            'facility register' => ['post', '/api/facilities/register'],
        ];
    }

    #[DataProvider('removedPublicFacilityRoutes')]
    public function test_public_facility_registration_routes_do_not_exist(string $method, string $uri): void
    {
        $this->json($method, $uri)->assertNotFound();
    }
}
