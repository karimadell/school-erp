<?php

namespace Tests\Unit;

use App\Support\QrTrace;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * QR_TRACE diagnostic instrumentation (504 investigation, rounds 2/3).
 *
 * Two guarantees this whole instrumentation effort depends on:
 *  1. QrTrace::log() must be a genuine no-op for any request that never
 *     called QrTrace::start() — otherwise every unrelated caller of the
 *     shared Finance services (mass billing, manual invoices, refunds,
 *     ordinary subscribe() calls outside Quick Registration) would start
 *     emitting log noise the moment any of them adds a checkpoint call.
 *  2. Every QrTrace::log() call site added across the codebase must only
 *     ever pass safe, generic metadata — never student/financial data.
 *     Enforced here as a static source scan (not a runtime property test)
 *     because the whole point is to catch a call site that COULD leak
 *     data before it ever runs, not just the ones a given test happens
 *     to exercise.
 */
class QrTraceTest extends TestCase
{
    public function test_log_is_a_noop_without_an_active_trace(): void
    {
        Log::shouldReceive('info')->never();

        QrTrace::log('some_checkpoint', ['line_index' => 0]);

        $this->assertTrue(true);
    }

    public function test_log_emits_only_the_documented_safe_keys_once_a_trace_is_active(): void
    {
        QrTrace::start('some-operation-key');

        Log::shouldReceive('info')->once()->withArgs(function (string $message, array $context) {
            return $message === 'QR_TRACE'
                && array_key_exists('checkpoint', $context)
                && array_key_exists('trace_id', $context)
                && array_key_exists('elapsed_ms', $context)
                && array_key_exists('tx_level', $context)
                && $context['checkpoint'] === 'some_checkpoint'
                && $context['line_index'] === 2
                && $context['category'] === 'tuition';
        });

        QrTrace::log('some_checkpoint', ['line_index' => 2, 'category' => 'tuition']);

        Context::flush();
    }

    /**
     * Every QrTrace::log(...) call site added across the Quick Registration
     * hotspot-breakdown pass, scanned as source text — never executed, so
     * this catches every call site regardless of which code path a given
     * request happens to take. A literal array key matching any of these
     * blocked substrings would indicate a call site accidentally logging
     * student, financial, or payload data instead of the documented safe
     * metadata (line_index/category/group/billing_period/candidate_count/
     * installment_count/period_count, plus the fixed checkpoint/trace_id/
     * elapsed_ms/tx_level keys QrTrace::log() itself always adds).
     */
    public function test_no_qrtrace_call_site_passes_a_blocked_field_name(): void
    {
        $blockedKeys = [
            'name', 'phone', 'amount', 'price', 'fee_name', 'student', 'idempotency_token',
            'token', 'payment', 'total', 'balance', 'email', 'address', 'note',
        ];

        $files = [
            base_path('app/Services/Admissions/QuickStudentRegistrationService.php'),
            base_path('app/Services/Finance/InvoiceIssuanceService.php'),
            base_path('app/Services/Finance/InvoicePaymentService.php'),
            base_path('app/Services/Finance/InvoiceCalculationService.php'),
            base_path('app/Services/StudentServiceSubscriptionService.php'),
        ];

        foreach ($files as $file) {
            $source = file_get_contents($file);
            $this->assertNotFalse($source, "Could not read {$file}");

            // Every QrTrace::log('checkpoint', [ ...extra... ]) call site,
            // captured up to its own closing bracket. QrTrace::log('checkpoint')
            // with no extra array is also matched (produces an empty capture).
            preg_match_all('/QrTrace::log\([^;]*?\)(?=;)/s', $source, $matches);
            $this->assertNotEmpty($matches[0], "No QrTrace::log() call sites found in {$file} — test fixture drifted from the source.");

            foreach ($matches[0] as $callSite) {
                // Every literal array key in this call site, e.g. 'line_index' => ...
                preg_match_all("/'([a-zA-Z_]+)'\\s*=>/", $callSite, $keyMatches);
                foreach ($keyMatches[1] as $key) {
                    foreach ($blockedKeys as $blocked) {
                        $this->assertStringNotContainsStringIgnoringCase(
                            $blocked,
                            $key,
                            "QrTrace::log() call site in {$file} passes a field named '{$key}', which matches the blocked term '{$blocked}': {$callSite}"
                        );
                    }
                }
            }
        }
    }
}
