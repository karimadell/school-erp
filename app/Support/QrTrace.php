<?php

namespace App\Support;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Temporary diagnostic instrumentation for the Quick Registration 504 investigation.
 *
 * QrTrace::log() is a silent no-op unless QrTrace::start() has been called earlier
 * in the same request, so this never emits log entries for unrelated callers of
 * InvoiceIssuanceService / InvoicePaymentService (mass billing, manual invoices,
 * refunds, etc.).
 */
class QrTrace
{
    public static function start(?string $seed): string
    {
        $traceId = substr(hash('sha256', $seed ?? (string) microtime(true).random_int(0, PHP_INT_MAX)), 0, 12);

        Context::add('qr_trace_id', $traceId);
        Context::add('qr_started_at', microtime(true));

        return $traceId;
    }

    public static function log(string $checkpoint, array $extra = []): void
    {
        $startedAt = Context::get('qr_started_at');

        if ($startedAt === null) {
            return;
        }

        Log::info('QR_TRACE', array_merge([
            'checkpoint' => $checkpoint,
            'trace_id' => Context::get('qr_trace_id'),
            'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'tx_level' => DB::transactionLevel(),
        ], $extra));
    }
}
