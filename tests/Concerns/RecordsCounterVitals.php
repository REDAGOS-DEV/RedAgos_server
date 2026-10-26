<?php

namespace Tests\Concerns;

use App\Support\OperationalDay;

/**
 * The counter's six vital signs, which it must record before accepting or
 * deferring a donor (project owner, 2026-10-24; RecordScreeningRequest).
 */
trait RecordsCounterVitals
{
    /**
     * Readings inside every range (config donation.vitals), for either sex.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function normalVitals(array $overrides = []): array
    {
        return [
            'systolic_bp' => 118,
            'diastolic_bp' => 76,
            'pulse_bpm' => 72,
            'temperature_c' => 36.6,
            'weight_kg' => 65,
            'haemoglobin_g_dl' => 14.2,
            ...$overrides,
        ];
    }

    /**
     * A counter screening payload with normal readings under it, and an
     * Until date a month out when it is a temporary deferral that names none.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function withVitals(array $payload): array
    {
        $payload = [...$this->normalVitals(), ...$payload];

        if (($payload['outcome'] ?? null) === 'temporarily_deferred' && ! array_key_exists('deferred_until', $payload)) {
            $payload['deferred_until'] = OperationalDay::today()->addDays(30)->toDateString();
        }

        return $payload;
    }
}
