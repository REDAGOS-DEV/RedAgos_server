<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Numbers for financial documents, and the transaction that issues them.
 *
 * Statements (SOA-) and receipts (AR-) are numbered per issuing facility from
 * facility_document_sequences, taken under a row lock as the last lock of the
 * issuing transaction. The unique constraints on the numbered tables are the
 * backstop; if one fires, the whole transaction is rolled back and run again,
 * a bounded number of times, and then refused rather than left to fail with a
 * server error.
 *
 * DB::transaction's own retry covers deadlocks, not unique violations, which
 * is why this loop exists.
 */
final class DocumentNumbering
{
    /**
     * How many times a transaction that collided on a document number is run.
     */
    private const ATTEMPTS = 3;

    /**
     * The tables whose unique numbers this retries on.
     */
    private const NUMBERED_TABLES = ['billing_revisions', 'payment_receipts', 'facility_document_sequences'];

    /**
     * Run a transaction that issues a document number, retrying it when the number collided.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $work
     * @return TResult
     */
    public static function transaction(callable $work): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction($work);
            } catch (QueryException $exception) {
                if (! self::isCollision($exception)) {
                    throw $exception;
                }

                if ($attempt >= self::ATTEMPTS) {
                    throw new HttpResponseException(response()->json([
                        'message' => 'Another document took that number at the same moment. Please try again.',
                        'code' => 'document_number_conflict',
                    ], 409));
                }
            }
        }
    }

    /**
     * Determine whether a failed query was a unique-number collision on a numbered document table.
     *
     * Narrower than any integrity violation: SQLite reports a trigger's
     * refusal with the same SQLSTATE, and that must never be retried.
     */
    public static function isCollision(QueryException $exception): bool
    {
        $state = $exception->errorInfo[0] ?? null;
        $message = $exception->getMessage();

        $unique = $state === '23505'
            || ($state === '23000' && str_contains($message, 'UNIQUE'));

        if (! $unique) {
            return false;
        }

        foreach (self::NUMBERED_TABLES as $table) {
            if (str_contains($message, $table)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Render a document number: prefix, issuing facility and a zero-padded sequence.
     */
    public static function format(string $prefix, int $facilityId, int $sequence): string
    {
        return sprintf('%s-%d-%06d', $prefix, $facilityId, $sequence);
    }
}
