<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * How the Audit Log page names each audited action: the module it belongs
 * to, the kind of action it is (the coloured chip), and a short past-tense
 * label for the Details column.
 *
 * Every action AuditLogger is handed should be listed here; AuditActionsTest
 * scans the code for literal action names and fails on one that is missing.
 * A missing one still renders, from its own name (see describe()), so a row
 * is never lost, only less well worded.
 */
final class AuditActions
{
    /**
     * The modules, in the order the filter lists them.
     *
     * @var array<string, string>
     */
    public const MODULES = [
        'account' => 'Account',
        'staff' => 'Staff',
        'settings' => 'Settings',
        'donors' => 'Donors',
        'appointments' => 'Appointments',
        'collection' => 'Collection',
        'testing' => 'Testing',
        'processing' => 'Processing',
        'inventory' => 'Inventory',
        'requests' => 'Requests',
        'billing' => 'Billing',
        'corrections' => 'Corrections',
        'hospital' => 'Hospital',
        'other' => 'Other',
    ];

    /**
     * The kinds of action, in the order the filter lists them.
     *
     * @var array<string, string>
     */
    public const TYPES = [
        'sign_in' => 'Sign-in',
        'sign_out' => 'Sign-out',
        'failed_sign_in' => 'Failed sign-in',
        'create' => 'Create',
        'edit' => 'Edit',
        'delete' => 'Delete',
        'cancel' => 'Cancel',
        'view' => 'View',
        'export' => 'Export',
        'print' => 'Print',
        'approve' => 'Approve',
        'reject' => 'Reject',
        'issue' => 'Issue',
        'send' => 'Send',
    ];

    /**
     * Other words people search a kind of action by.
     *
     * @var array<string, string>
     */
    private const SYNONYMS = [
        'sign_in' => 'login log in',
        'sign_out' => 'logout log out',
        'failed_sign_in' => 'login log in failed',
    ];

    /**
     * action => [module, type, label].
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    private const ACTIONS = [
        'auth.signed_in' => ['account', 'sign_in', 'Signed in'],
        'auth.signed_out' => ['account', 'sign_out', 'Signed out'],
        'auth.sign_in_failed' => ['account', 'failed_sign_in', 'Sign-in refused'],

        'staff.created' => ['staff', 'create', 'Added a staff account'],
        'staff.deleted' => ['staff', 'delete', 'Removed a staff account'],
        'staff.restored' => ['staff', 'edit', 'Restored a staff account'],
        'staff.role_changed' => ['staff', 'edit', 'Changed staff roles'],
        'staff.privileges_changed' => ['staff', 'edit', 'Changed privileges'],
        'staff.management_level_changed' => ['staff', 'edit', 'Changed access level'],
        'staff.status_changed' => ['staff', 'edit', 'Changed account status'],

        'center.logo_updated' => ['settings', 'edit', 'Updated the centre logo'],
        'center.logo_removed' => ['settings', 'delete', 'Removed the centre logo'],
        'center.seal_updated' => ['settings', 'edit', 'Updated the left report logo'],
        'center.seal_removed' => ['settings', 'delete', 'Removed the left report logo'],
        'center.component_added' => ['settings', 'create', 'Added a blood component'],
        'center.component_configured' => ['settings', 'edit', 'Changed a component\'s shelf life'],
        'center.deferral_code_added' => ['settings', 'create', 'Added a deferral code'],
        'center.deferral_code_changed' => ['settings', 'edit', 'Changed a deferral code'],
        'facility.email_template_saved' => ['settings', 'edit', 'Saved an email template'],
        'facility.booking_schedule_updated' => ['settings', 'edit', 'Changed the booking schedule'],
        'facility.email_template_reset' => ['settings', 'edit', 'Reset an email template'],
        'facility.xendit_account_set' => ['settings', 'edit', 'Changed the online payment account'],
        'facility.created' => ['settings', 'create', 'Facility created'],
        'facility.approved' => ['settings', 'approve', 'Facility approved'],
        'facility.rejected' => ['settings', 'reject', 'Facility rejected'],

        'donor.viewed' => ['donors', 'view', 'Opened a donor record'],
        'donor.looked_up' => ['donors', 'view', 'Looked up a donor'],
        'donor.searched_at_counter' => ['donors', 'view', 'Searched donors at the counter'],
        'donor.identity_image_viewed' => ['donors', 'view', 'Viewed a donor\'s ID image'],
        'donor.qr_code.viewed' => ['donors', 'view', 'Viewed a check-in QR code'],
        'donor.updated' => ['donors', 'edit', 'Updated a donor record'],
        'donor.photo_updated' => ['donors', 'edit', 'Updated a donor photo'],
        'donor.registered_at_counter' => ['donors', 'create', 'Registered a walk-in donor'],
        'donor.registered_from_paper' => ['donors', 'create', 'Registered a donor from a paper record'],
        'donor.deferred' => ['donors', 'create', 'Deferred a donor'],
        'donor.deferral_lifted' => ['donors', 'edit', 'Lifted a deferral'],
        'donor.identity_verified' => ['donors', 'approve', 'Verified a donor\'s ID'],
        'donor.identity_rejected' => ['donors', 'reject', 'Rejected a donor\'s ID'],
        'donor.blood_type_verified' => ['donors', 'approve', 'Confirmed a donor\'s blood type'],
        'donor.qr_code.issued' => ['donors', 'create', 'Issued a check-in QR code'],
        'donor.sign_in_issued' => ['donors', 'create', 'Issued a sign-in slip'],
        'donor.emailed' => ['donors', 'send', 'Emailed a donor'],
        'donors.eligibility_reminders_sent' => ['donors', 'send', 'Sent eligibility reminders'],
        'donors.exported' => ['donors', 'export', 'Exported the donor list'],
        'eligibility.screening.created' => ['donors', 'create', 'Answered the health questionnaire'],
        'eligibility.screening.recorded_at_counter' => ['donors', 'create', 'Recorded a health questionnaire at the counter'],

        'appointment.booked_by_staff' => ['appointments', 'create', 'Booked an appointment'],
        'appointment.marked_collected' => ['appointments', 'edit', 'Marked an appointment completed'],
        'appointment.cancelled_by_staff' => ['appointments', 'cancel', 'Cancelled an appointment'],
        'appointment.deleted' => ['appointments', 'delete', 'Deleted an appointment'],
        'appointments.exported' => ['appointments', 'export', 'Exported appointments'],

        'collection.checked_in' => ['collection', 'edit', 'Checked in a donor'],
        'collection.no_show' => ['collection', 'edit', 'Marked a no-show'],
        'collection.qr_verified' => ['collection', 'view', 'Scanned a check-in QR code'],
        'collection.qr_rejected' => ['collection', 'reject', 'Refused a check-in QR code'],
        'collection.questionnaire_viewed' => ['collection', 'view', 'Viewed a health questionnaire'],
        'collection.questionnaire_printed' => ['collection', 'print', 'Printed a health questionnaire'],
        'collection.donation_opened' => ['collection', 'create', 'Opened a donation'],
        'collection.donation_registered' => ['collection', 'edit', 'Moved a donation back to registration'],
        'collection.donation_screening' => ['collection', 'edit', 'Sent a donation to screening'],
        'collection.donation_collected' => ['collection', 'edit', 'Marked a donation collected'],
        'collection.donation_tested' => ['collection', 'edit', 'Marked a donation tested'],
        'collection.donation_completed' => ['collection', 'edit', 'Completed a donation'],
        'collection.donation_rejected' => ['collection', 'cancel', 'Closed a donation'],
        'collection.screening_recorded' => ['collection', 'create', 'Recorded a screening'],
        'collection.recorded' => ['collection', 'create', 'Recorded a collection'],
        'collection.corrected' => ['collection', 'edit', 'Corrected a collection'],
        'donations.exported' => ['collection', 'export', 'Exported donations'],
        'paper.sheet_created' => ['collection', 'create', 'Added a paper records sheet'],
        'paper.sheet_deleted' => ['collection', 'delete', 'Deleted a paper records sheet'],
        'paper.donation_recorded' => ['collection', 'create', 'Typed in a paper donation'],
        'paper.donation_removed' => ['collection', 'delete', 'Removed a paper donation'],

        'laboratory.serology_recorded' => ['testing', 'create', 'Recorded serology results'],
        'laboratory.serology_verified' => ['testing', 'approve', 'Verified a serology result'],
        'laboratory.serology_returned' => ['testing', 'reject', 'Returned a serology result'],
        'laboratory.serology_exported' => ['testing', 'export', 'Exported serology results'],
        'laboratory.immunohematology_recorded' => ['testing', 'create', 'Recorded blood typing'],
        'referral.opened' => ['testing', 'create', 'Opened a counselling referral'],
        'referral.status_changed' => ['testing', 'edit', 'Changed a referral\'s status'],
        'referral.contact_requested' => ['testing', 'send', 'Sent a contact request'],
        'referral.list_viewed' => ['testing', 'view', 'Viewed counselling referrals'],

        'laboratory.components_declared' => ['processing', 'create', 'Recorded a component breakdown'],
        'laboratory.donation_tested' => ['processing', 'edit', 'Marked a donation tested'],
        'laboratory.donation_completed' => ['processing', 'approve', 'Completed a donation'],
        'laboratory.donation_rejected' => ['processing', 'reject', 'Rejected a donation'],
        'laboratory.processed_exported' => ['processing', 'export', 'Exported processed donations'],
        // Final labels moved from Issuance to Processing (2026-10-22); the
        // inventory action below stays for the entries written before.
        'laboratory.labels_printed' => ['processing', 'print', 'Printed final labels'],
        'inventory.labels_printed' => ['processing', 'print', 'Printed bag labels'],

        'inventory.recorded' => ['inventory', 'create', 'Stocked a unit'],
        'inventory.updated' => ['inventory', 'edit', 'Updated a unit'],
        'inventory.reinstated' => ['inventory', 'edit', 'Reinstated a unit'],
        'inventory.released_from_quarantine' => ['inventory', 'approve', 'Released a unit from quarantine'],
        'inventory.discarded' => ['inventory', 'delete', 'Discarded a unit'],
        'inventory.expired' => ['inventory', 'edit', 'Unit expired'],
        'inventory.expiry_swept' => ['inventory', 'edit', 'Ran the expiry check'],
        'inventory.stock_report_downloaded' => ['inventory', 'export', 'Downloaded the daily stock report'],
        'stock_threshold.saved' => ['inventory', 'edit', 'Saved a stock threshold'],
        'stock_threshold.cleared' => ['inventory', 'delete', 'Cleared a stock threshold'],
        'stock_threshold.alerted' => ['inventory', 'send', 'Low-stock alert sent'],
        'stock_threshold.recovered' => ['inventory', 'edit', 'Stock back above minimum'],

        'request.submitted' => ['requests', 'create', 'Submitted a blood request'],
        'request.walk_in_recorded' => ['requests', 'create', 'Recorded a walk-in request'],
        'request.allocation_created' => ['requests', 'create', 'Created a request allocation'],
        'request.allocated' => ['requests', 'issue', 'Reserved units for a request'],
        'request.released' => ['requests', 'issue', 'Released a request'],
        'request.rejected' => ['requests', 'reject', 'Rejected a blood request'],
        'request.cancelled' => ['requests', 'cancel', 'Cancelled a blood request'],
        'request.line_closed' => ['requests', 'edit', 'Closed a request line'],
        'request.receipt_confirmed' => ['requests', 'approve', 'Confirmed receipt'],
        'request.referral_slip_printed' => ['requests', 'print', 'Printed a referral slip'],
        'request.resettled' => ['requests', 'edit', 'Re-settled a request'],
        'request.transfusion_created' => ['requests', 'create', 'Created a transfusion request'],
        'request.transfusion_allocations_added' => ['requests', 'edit', 'Added to a transfusion request'],
        'request.transfusion_line_closed' => ['requests', 'edit', 'Closed a transfusion request line'],
        'request.transfusion_cancelled' => ['requests', 'cancel', 'Cancelled a transfusion request'],
        'request.transfusion_resettled' => ['requests', 'edit', 'Re-settled a transfusion request'],
        'weekly_request.submitted' => ['requests', 'create', 'Submitted a weekly request'],
        'blood_requests.exported' => ['requests', 'export', 'Exported blood requests'],
        'allocation.reserved' => ['requests', 'issue', 'Reserved a unit'],
        'allocation.issued' => ['requests', 'issue', 'Issued a unit'],
        'allocation.released_to_stock' => ['requests', 'edit', 'Returned a unit to stock'],
        'allocation.expired_hold_released' => ['requests', 'edit', 'Released an expired hold'],
        'allocation.dispatch_corrected' => ['requests', 'edit', 'Corrected a dispatch'],
        'direct_distribution.received' => ['requests', 'create', 'Received a direct distribution'],
        'external_blood_source.created' => ['requests', 'create', 'Added an external blood source'],

        'billing.raised' => ['billing', 'create', 'Raised a bill'],
        'billing.updated' => ['billing', 'edit', 'Updated a bill'],
        'billing.subsidised' => ['billing', 'approve', 'Applied a subsidy'],
        'billing.subsidy_removed' => ['billing', 'edit', 'Removed a subsidy'],
        'billing.program_created' => ['billing', 'create', 'Scheduled a billing programme'],
        'billing.program_updated' => ['billing', 'edit', 'Updated a billing programme'],
        'billing.statement_issued' => ['billing', 'create', 'Issued a statement'],
        'billing.statement_downloaded' => ['billing', 'export', 'Downloaded a statement'],
        'billing.receipt_downloaded' => ['billing', 'export', 'Downloaded a receipt'],
        'billing.payment_recorded' => ['billing', 'create', 'Recorded a payment'],
        'billing.payment_confirmed' => ['billing', 'approve', 'Online payment confirmed'],
        'billing.payment_corrected' => ['billing', 'edit', 'Corrected a payment'],
        'billing.payment_voided' => ['billing', 'cancel', 'Voided a payment'],
        'billing.weekly_settled' => ['billing', 'edit', 'Settled a weekly statement'],
        'billing.checkout_started' => ['billing', 'create', 'Started an online checkout'],
        'billing.checkout_reverified' => ['billing', 'edit', 'Re-checked an online checkout'],
        'billing.checkout_closed' => ['billing', 'edit', 'Closed an online checkout'],
        'billing.checkout_superseded' => ['billing', 'cancel', 'Replaced an online checkout'],
        'billing.shift_opened' => ['billing', 'create', 'Opened a cash shift'],
        'billing.shift_closed' => ['billing', 'edit', 'Closed a cash shift'],
        'billing.shift_report_downloaded' => ['billing', 'export', 'Downloaded a shift report'],
        'billing.component_priced' => ['billing', 'edit', 'Changed a component price'],

        'correction.requested' => ['corrections', 'create', 'Filed a correction'],
        'correction.approved' => ['corrections', 'approve', 'Approved a correction'],
        'correction.rejected' => ['corrections', 'reject', 'Rejected a correction'],
        'correction.applied' => ['corrections', 'edit', 'Applied a correction'],

        'hospital_inventory.received' => ['hospital', 'create', 'Received a unit'],
        'hospital_inventory.stocked' => ['hospital', 'create', 'Stocked a unit'],
        'hospital_inventory.tagged' => ['hospital', 'edit', 'Tagged a unit'],
        'hospital_inventory.untagged' => ['hospital', 'edit', 'Untagged a unit'],
        'hospital_inventory.crossmatched' => ['hospital', 'edit', 'Crossmatched a unit'],
        'hospital_inventory.transfused' => ['hospital', 'issue', 'Transfused a unit'],
        'hospital_inventory.returned' => ['hospital', 'edit', 'Returned a unit'],
        'hospital_inventory.discarded' => ['hospital', 'delete', 'Discarded a unit'],
        'hospital_inventory.expired' => ['hospital', 'edit', 'Unit expired'],
        'hospital_inventory.expiry_swept' => ['hospital', 'edit', 'Ran the expiry check'],
        'hospital_inventory.tag_sweep' => ['hospital', 'edit', 'Ran the tag expiry check'],
        'hospital_inventory.report_generated' => ['hospital', 'create', 'Generated an inventory report'],
        'hospital_inventory.report_downloaded' => ['hospital', 'export', 'Downloaded an inventory report'],
        'hospital_staff.created' => ['hospital', 'create', 'Added a staff account'],
        'hospital_staff.deleted' => ['hospital', 'delete', 'Removed a staff account'],
        'hospital_staff.restored' => ['hospital', 'edit', 'Restored a staff account'],
        'hospital_staff.role_changed' => ['hospital', 'edit', 'Changed a staff role'],
        'replenishment_schedule.saved' => ['hospital', 'edit', 'Saved a replenishment schedule'],
        'replenishment_schedule.deleted' => ['hospital', 'delete', 'Deleted a replenishment schedule'],
    ];

    /**
     * Name an action, listed or not.
     *
     * @return array{module: string, module_label: string, type: string, type_label: string, label: string}
     */
    public static function describe(string $action): array
    {
        [$module, $type, $label] = self::ACTIONS[$action] ?? self::derive($action);

        return [
            'module' => $module,
            'module_label' => self::MODULES[$module],
            'type' => $type,
            'type_label' => self::TYPES[$type],
            'label' => $label,
        ];
    }

    /**
     * Whether an action is listed.
     */
    public static function has(string $action): bool
    {
        return isset(self::ACTIONS[$action]);
    }

    /**
     * The listed actions in a module.
     *
     * @return array<int, string>
     */
    public static function inModule(string $module): array
    {
        return array_keys(array_filter(self::ACTIONS, fn (array $entry): bool => $entry[0] === $module));
    }

    /**
     * The listed actions of a kind.
     *
     * @return array<int, string>
     */
    public static function ofType(string $type): array
    {
        return array_keys(array_filter(self::ACTIONS, fn (array $entry): bool => $entry[1] === $type));
    }

    /**
     * The listed actions whose label, module or kind mentions a search term,
     * so "sign in", "export" or "billing" find their rows.
     *
     * @return array<int, string>
     */
    public static function matching(string $term): array
    {
        $needle = self::normalise($term);

        if ($needle === '') {
            return [];
        }

        return array_keys(array_filter(self::ACTIONS, fn (array $entry): bool => str_contains(
            self::normalise($entry[2].' '.self::MODULES[$entry[0]].' '.self::TYPES[$entry[1]].' '.(self::SYNONYMS[$entry[1]] ?? '')),
            $needle
        )));
    }

    /**
     * Name an action nobody listed from its own key: "donor.thing_done" reads
     * "Thing done" under Donors.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private static function derive(string $action): array
    {
        $prefix = Str::before($action, '.');
        $module = match ($prefix) {
            'auth' => 'account',
            'staff' => 'staff',
            'center', 'facility' => 'settings',
            'donor', 'donors', 'eligibility' => 'donors',
            'appointment', 'appointments' => 'appointments',
            'collection', 'paper' => 'collection',
            'laboratory', 'referral' => 'testing',
            'inventory', 'stock_threshold' => 'inventory',
            'request', 'weekly_request', 'blood_requests', 'allocation' => 'requests',
            'billing' => 'billing',
            'correction' => 'corrections',
            'hospital_inventory', 'hospital_staff', 'replenishment_schedule' => 'hospital',
            default => 'other',
        };

        $verb = Str::afterLast($action, '.');
        $type = match (true) {
            Str::contains($verb, ['viewed', 'looked_up', 'searched']) => 'view',
            Str::contains($verb, ['exported', 'downloaded']) => 'export',
            Str::contains($verb, 'printed') => 'print',
            Str::contains($verb, ['deleted', 'removed', 'discarded']) => 'delete',
            Str::contains($verb, ['cancelled', 'voided']) => 'cancel',
            Str::contains($verb, ['approved', 'verified', 'confirmed']) => 'approve',
            Str::contains($verb, ['rejected', 'returned']) => 'reject',
            Str::contains($verb, ['created', 'recorded', 'added', 'opened', 'submitted']) => 'create',
            default => 'edit',
        };

        return [$module, $type, Str::ucfirst(str_replace(['_', '.'], ' ', $verb))];
    }

    private static function normalise(string $text): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($text)) ?? '');
    }
}
