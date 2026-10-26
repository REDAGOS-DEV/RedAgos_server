<?php

namespace Tests\Concerns;

use App\Models\CashSession;
use App\Models\User;
use App\Service\CashSessionService;

/**
 * A cash shift for a cashier, for tests that take payments at the counter.
 *
 * With cash shifts on (blood_center.cash_shifts), a counter payment goes into
 * the recorder's open shift and is refused without one
 * (BillingService::recordPayment()). Opened through the service, not the
 * route, so it does not change who the test is acting as. The test turns
 * shifts on first; the service refuses to open one while they are off.
 */
trait OpensCashShifts
{
    /**
     * The cashier's open shift, opened with the given float in centavos if they have none.
     */
    protected function shiftFor(User $cashier, int $floatCentavos = 0): CashSession
    {
        $open = CashSession::query()->where('cashier_id', $cashier->id)->open()->first();

        if ($open !== null) {
            return $open;
        }

        $id = app(CashSessionService::class)->open($cashier, $floatCentavos, null)['session']['id'];

        return CashSession::query()->findOrFail($id);
    }
}
