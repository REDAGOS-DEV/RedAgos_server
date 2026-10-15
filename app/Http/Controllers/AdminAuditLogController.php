<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The platform audit trail, read by admins holding admin.audit.view
 * (2026-10-15).
 *
 * Read only: the trail is append-only and nothing here can change it. Each
 * entry carries what AuditLogger wrote (identifiers and outcomes, never health
 * data), with the actor's name resolved for the reader.
 */
class AdminAuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'category' => ['sometimes', 'nullable', 'string', 'max:60', 'regex:/^[a-z_]+$/'],
            'action' => ['sometimes', 'nullable', 'string', 'max:60'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:5', 'max:100'],
        ]);

        $query = AuditLog::query()
            ->with('actor:id,first_name,last_name,email')
            ->when($filters['category'] ?? null, fn (Builder $q, string $category) => $q->where('action', 'like', $category.'.%'))
            ->when($filters['action'] ?? null, fn (Builder $q, string $action) => $q->where('action', $action))
            ->when($filters['from'] ?? null, fn (Builder $q, string $from) => $q->where('created_at', '>=', $from.' 00:00:00'))
            ->when($filters['to'] ?? null, fn (Builder $q, string $to) => $q->where('created_at', '<=', $to.' 23:59:59'))
            ->when($filters['search'] ?? null, function (Builder $q, string $search): void {
                $term = '%'.trim($search).'%';
                $q->whereHas('actor', fn (Builder $actor) => $actor
                    ->where('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('email', 'like', $term));
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $page = $query->paginate((int) ($filters['per_page'] ?? 25))
            ->through(fn (AuditLog $log): array => $this->format($log));

        return response()->json([
            ...$page->toArray(),
            // The categories that occur at all, for the filter: the part of each
            // action before its first dot ("facility" in facility.created).
            'categories' => AuditLog::query()
                ->select('action')
                ->distinct()
                ->pluck('action')
                ->map(fn (string $action): string => Str::before($action, '.'))
                ->unique()
                ->sort()
                ->values(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function format(AuditLog $log): array
    {
        return [
            'id' => $log->id,
            'action' => $log->action,
            'category' => Str::before($log->action, '.'),
            'actor' => $log->actor ? [
                'id' => $log->actor->id,
                'full_name' => trim($log->actor->first_name.' '.$log->actor->last_name),
                'email' => $log->actor->email,
            ] : null,
            'subject_type' => $log->auditable_type ? class_basename($log->auditable_type) : null,
            'subject_id' => $log->auditable_id,
            'context' => $log->context,
            'ip_address' => $log->ip_address,
            'user_agent' => $log->user_agent,
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }
}
