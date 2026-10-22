<?php

namespace App\Service;

use App\Enums\ManagementLevel;
use App\Enums\StaffPrivilege;
use App\Enums\StaffRole;
use App\Models\AuditLog;
use App\Models\BloodComponent;
use App\Models\BloodType;
use App\Models\BloodUnit;
use App\Models\CorrectionRequest;
use App\Models\CounsellingReferral;
use App\Models\Donation;
use App\Models\DonationAppointment;
use App\Models\DonorDeferral;
use App\Models\Facility;
use App\Models\FacilityBloodComponent;
use App\Models\PaperDonationSheet;
use App\Models\StockThreshold;
use App\Models\User;
use App\Repository\AuditLogRepository;
use App\Support\AuditActions;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

/**
 * The Audit Log page (project owner, 2026-10-21): the Blood Center Admin's
 * read of what the centre's staff did, from audit_logs.
 *
 * Read-only, and nothing here writes to the trail: viewing it is not itself
 * an event worth a row.
 *
 * The Admin holds no department page, so the rows say who did what to which
 * record and never what the record holds. A donor is named by their DONOR-
 * code only, and the Details column is built from a short list of context
 * keys (references, counts, the before and after of a staff change), never
 * from outcomes, results, reasons or amounts.
 */
class AuditLogService
{
    /** @var array<int, string>|null */
    private ?array $bloodTypes = null;

    /** @var array<int, string>|null */
    private ?array $components = null;

    public function __construct(
        private readonly AuditLogRepository $auditLogs
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function list(User $admin, array $filters, int $perPage): array
    {
        $facility = $this->requireFacility($admin);
        $page = $this->auditLogs->paginate($facility->id, $filters, $perPage);

        $users = $this->auditLogs->usersByIds(
            $page->getCollection()
                ->where('auditable_type', User::class)
                ->pluck('auditable_id')
                ->map(fn (string $id): int => (int) $id)
                ->unique()
                ->values()
                ->all()
        );

        // A statement, or a hold the sweep let go, is known by its request.
        $requests = $this->auditLogs->requestReferences(
            $page->getCollection()
                ->map(fn (AuditLog $log): mixed => is_array($log->context) ? ($log->context['request_id'] ?? null) : null)
                ->filter(fn (mixed $id): bool => is_numeric($id))
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->values()
                ->all()
        );

        return [
            ...$page->through(fn (AuditLog $log): array => $this->format($log, $users, $requests))->toArray(),
            // The filter choices, in the server's words. Hospital rows are
            // never a centre's, and "Other" holds nothing a filter could name.
            'modules' => $this->options(array_diff_key(AuditActions::MODULES, array_flip(['hospital', 'other']))),
            'types' => $this->options(AuditActions::TYPES),
        ];
    }

    /**
     * One row.
     *
     * @param  Collection<int, User>  $users
     * @param  array<int, string>  $requests
     * @return array<string, mixed>
     */
    private function format(AuditLog $log, Collection $users, array $requests): array
    {
        $described = AuditActions::describe($log->action);
        $at = CarbonImmutable::instance($log->created_at)->setTimezone((string) config('blood_center.timezone', 'Asia/Manila'));
        $context = is_array($log->context) ? $log->context : [];
        $subject = $log->auditable_type === User::class ? $users->get((int) $log->auditable_id) : null;

        if (! isset($context['reference_number']) && is_numeric($context['request_id'] ?? null)
            && isset($requests[(int) $context['request_id']])) {
            $context['reference_number'] = $requests[(int) $context['request_id']];
        }

        $record = $this->record($log, $context, $subject);

        return [
            'id' => $log->id,
            'at' => $log->created_at?->toISOString(),
            'date' => $at->toDateString(),
            'time' => $at->format('H:i'),
            'user' => $this->who($log),
            'action' => $log->action,
            'module' => $described['module'],
            'module_label' => $described['module_label'],
            'type' => $described['type'],
            'type_label' => $described['type_label'],
            'record' => $record,
            'details' => $this->details($log, $described, $context, $subject, $record),
        ];
    }

    /**
     * Who the row is by: a colleague, or nobody signed in.
     *
     * @return array{name: string, role: string|null, system: bool}
     */
    private function who(AuditLog $log): array
    {
        $actor = $log->actor;

        if ($actor === null) {
            return $log->action === 'auth.sign_in_failed'
                ? ['name' => 'Unknown', 'role' => 'Not signed in', 'system' => true]
                : ['name' => 'System', 'role' => 'Automatic', 'system' => true];
        }

        return [
            'name' => $this->nameOf($actor),
            'role' => $actor->management_level?->label()
                ?? $actor->staff_role?->label()
                ?? ($actor->custom_role ?: null),
            'system' => false,
        ];
    }

    /**
     * The record the row is about, as the rest of the portal prints it.
     *
     * @param  array<string, mixed>  $context
     */
    private function record(AuditLog $log, array $context, ?User $subject): ?string
    {
        $id = $log->auditable_id;

        // A bag is known by the number printed on it, whichever request it
        // went out on.
        if ($log->auditable_type === BloodUnit::class && $id !== null) {
            return $id;
        }

        foreach (['reference_number', 'document_number', 'receipt_number'] as $key) {
            if (is_string($context[$key] ?? null) && $context[$key] !== '') {
                return $context[$key];
            }
        }

        if ($id === null) {
            return null;
        }

        return match ($log->auditable_type) {
            User::class => $this->accountRecord($subject, (int) $id),
            Donation::class => $this->code('DON', $id),
            DonationAppointment::class => $this->code('APT', $id),
            DonorDeferral::class => $this->code('DEF', $id),
            PaperDonationSheet::class => $this->code('PS', $id),
            CorrectionRequest::class => is_numeric($context['donation_id'] ?? null)
                ? $this->code('DON', (string) $context['donation_id'])
                : $this->fallbackRecord($log->auditable_type, $id),
            // Not by its donation: only a reactive donation is referred, so
            // the pairing would tell the Admin a result.
            CounsellingReferral::class => $this->fallbackRecord($log->auditable_type, $id),
            StockThreshold::class, FacilityBloodComponent::class, BloodComponent::class => $this->componentRecord($context)
                ?? $this->fallbackRecord($log->auditable_type, $id),
            // The centre itself: an export, a setting.
            Facility::class => null,
            default => $this->fallbackRecord((string) $log->auditable_type, $id),
        };
    }

    /**
     * A colleague by their employee ID (or name, without one); a donor by
     * their DONOR- code, never their name.
     */
    private function accountRecord(?User $user, int $id): string
    {
        if ($user?->facility_id !== null) {
            return $user->employee_id ?: $this->nameOf($user);
        }

        return $this->code('DONOR', (string) $id);
    }

    /**
     * "A+ Packed RBC" for a stock threshold, "Packed RBC" for a component.
     *
     * @param  array<string, mixed>  $context
     */
    private function componentRecord(array $context): ?string
    {
        $component = is_numeric($context['component_id'] ?? null)
            ? $this->componentNames()[(int) $context['component_id']] ?? null
            : null;

        if ($component === null) {
            return null;
        }

        $bloodType = is_numeric($context['blood_type_id'] ?? null)
            ? $this->bloodTypeCodes()[(int) $context['blood_type_id']] ?? null
            : null;

        return trim(($bloodType ?? '').' '.$component);
    }

    /**
     * The Details column: the action's label, then what the row's context may
     * tell an Admin.
     *
     * @param  array{module: string, label: string}  $described
     * @param  array<string, mixed>  $context
     */
    private function details(AuditLog $log, array $described, array $context, ?User $subject, ?string $record): string
    {
        $parts = [];

        // Whose account, on the rows about a colleague's.
        if ($subject !== null && $subject->facility_id !== null && in_array($described['module'], ['account', 'staff'], true)
            && $log->actor_id !== $subject->id) {
            $parts[] = $this->nameOf($subject);
        }

        if ($log->action === 'auth.sign_in_failed') {
            $parts[] = $this->refusal((string) ($context['reason'] ?? ''));
        }

        if (($context['all_devices'] ?? false) === true) {
            $parts[] = 'every device';
        }

        if (in_array($log->action, ['staff.role_changed', 'staff.privileges_changed', 'staff.management_level_changed', 'staff.status_changed'], true)) {
            $parts[] = $this->staffChange($log->action, $context['from'] ?? null).' → '.$this->staffChange($log->action, $context['to'] ?? null);
        } elseif (is_scalar($context['from'] ?? null) && is_scalar($context['to'] ?? null)) {
            $parts[] = $this->humanise((string) $context['from']).' → '.$this->humanise((string) $context['to']);
        }

        foreach (['reference_number', 'document_number', 'receipt_number'] as $key) {
            if (is_string($context[$key] ?? null) && $context[$key] !== '' && $context[$key] !== $record) {
                $parts[] = $context[$key];
            }
        }

        if (is_string($context['subject'] ?? null)) {
            $parts[] = $this->humanise($context['subject']);
        }

        if (is_string($context['template'] ?? null)) {
            $parts[] = $this->humanise($context['template']).' email';
        }

        if (is_array($context['fields'] ?? null) && $context['fields'] !== []) {
            $parts[] = implode(', ', array_map(fn (mixed $field): string => $this->humanise((string) $field), $context['fields']));
        }

        $units = match (true) {
            is_array($context['units'] ?? null) => count($context['units']),
            is_int($context['units'] ?? null) => $context['units'],
            is_int($context['quantity'] ?? null) => $context['quantity'],
            default => null,
        };

        if ($units !== null) {
            $parts[] = $units.' '.Str::plural('unit', $units);
        }

        if (is_int($context['rows'] ?? null)) {
            $parts[] = number_format($context['rows']).' '.Str::plural('row', $context['rows']);
        }

        if (($context['via'] ?? null) === 'qr') {
            $parts[] = 'by QR code';
        }

        return implode(' · ', [$described['label'], ...$parts]);
    }

    /**
     * One side of a staff change, as the staff form words it.
     */
    private function staffChange(string $action, mixed $value): string
    {
        if ($value === null || $value === []) {
            return 'none';
        }

        $values = is_array($value) ? $value : [$value];

        return implode(', ', array_map(fn (mixed $one): string => match ($action) {
            // A custom role is stored by its own name.
            'staff.role_changed' => StaffRole::tryFrom((string) $one)?->label() ?? (string) $one,
            'staff.privileges_changed' => StaffPrivilege::tryFrom((string) $one)?->label() ?? $this->humanise((string) $one),
            'staff.management_level_changed' => ManagementLevel::tryFrom((string) $one)?->label() ?? $this->humanise((string) $one),
            default => $this->humanise((string) $one),
        }, $values));
    }

    /**
     * Why a sign-in was refused, from AuthService's codes.
     */
    private function refusal(string $reason): string
    {
        return match ($reason) {
            'wrong_password' => 'wrong password',
            'account_suspended' => 'account suspended',
            'account_deactivated' => 'account deactivated',
            'email_not_verified' => 'email not verified',
            'role_mismatch' => 'wrong portal',
            'facility_not_activated' => 'facility not activated',
            'admin_sign_in_required' => 'admin used the main sign-in',
            'no_portal_access' => 'no portal access',
            default => 'refused',
        };
    }

    /**
     * @param  array<string, string>  $labels
     * @return array<int, array{value: string, label: string}>
     */
    private function options(array $labels): array
    {
        return array_map(
            fn (string $value, string $label): array => ['value' => $value, 'label' => $label],
            array_keys($labels),
            $labels
        );
    }

    private function nameOf(User $user): string
    {
        return $user->signatureName();
    }

    private function code(string $prefix, string $id): string
    {
        return $prefix.'-'.str_pad($id, 6, '0', STR_PAD_LEFT);
    }

    private function fallbackRecord(string $type, string $id): string
    {
        return Str::headline(class_basename($type)).' #'.$id;
    }

    private function humanise(string $value): string
    {
        return Str::ucfirst(str_replace('_', ' ', $value));
    }

    /**
     * @return array<int, string>
     */
    private function bloodTypeCodes(): array
    {
        return $this->bloodTypes ??= BloodType::query()->pluck('code', 'id')->all();
    }

    /**
     * @return array<int, string>
     */
    private function componentNames(): array
    {
        return $this->components ??= BloodComponent::query()->pluck('name', 'id')->all();
    }

    private function requireFacility(User $staff): Facility
    {
        $staff->loadMissing('facility');

        return $staff->facility ?? throw new HttpResponseException(response()->json([
            'message' => 'This account is not linked to a facility.',
            'code' => 'facility_missing',
        ], 404));
    }
}
