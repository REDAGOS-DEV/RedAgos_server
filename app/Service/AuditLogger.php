<?php

namespace App\Service;

use App\Models\AuditLog;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Records an append-only trail of access to health data and check-in
 * credentials.
 *
 * Context carries identifiers and outcomes only. Questionnaire answers, vitals
 * and raw tokens are never written here.
 */
class AuditLogger
{
    public function __construct(private readonly Request $request) {}

    /**
     * Record an action taken against an auditable record.
     *
     * @param  array<string, mixed>  $context
     */
    public function record(?User $actor, string $action, ?Model $subject = null, array $context = []): AuditLog
    {
        return AuditLog::create([
            'actor_id' => $actor?->id,
            'facility_id' => $this->facilityFor($actor, $subject, $context),
            'action' => $action,
            'auditable_type' => $subject ? $subject::class : null,
            'auditable_id' => $subject?->getKey(),
            'context' => $context === [] ? null : $context,
            'ip_address' => $this->request->ip(),
            'user_agent' => substr((string) $this->request->userAgent(), 0, 255),
        ]);
    }

    /**
     * The facility whose trail the row belongs to, which is what the Audit
     * Log page reads by.
     *
     * Staff act for their own facility, whatever the context names: a
     * hospital submitting to a centre is the hospital's action. Someone
     * without one (a donor, the Super Admin) is filed under the facility the
     * context names. A row nobody signed in wrote (a scheduled sweep, a
     * payment webhook, a failed sign-in) falls back to its subject's own
     * facility when the context does not say.
     *
     * @param  array<string, mixed>  $context
     */
    private function facilityFor(?User $actor, ?Model $subject, array $context): ?int
    {
        if ($actor?->facility_id !== null) {
            return (int) $actor->facility_id;
        }

        if (is_numeric($context['facility_id'] ?? null)) {
            return (int) $context['facility_id'];
        }

        if ($actor !== null || $subject === null) {
            return null;
        }

        if ($subject instanceof Facility) {
            return (int) $subject->getKey();
        }

        $facilityId = $subject->getAttribute('facility_id');

        return is_numeric($facilityId) ? (int) $facilityId : null;
    }
}
