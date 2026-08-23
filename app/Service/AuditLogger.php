<?php

namespace App\Service;

use App\Models\AuditLog;
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
            'action' => $action,
            'auditable_type' => $subject ? $subject::class : null,
            'auditable_id' => $subject?->getKey(),
            'context' => $context === [] ? null : $context,
            'ip_address' => $this->request->ip(),
            'user_agent' => substr((string) $this->request->userAgent(), 0, 255),
        ]);
    }
}
