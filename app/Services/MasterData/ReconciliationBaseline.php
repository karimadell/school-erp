<?php

namespace App\Services\MasterData;

use Illuminate\Support\Facades\DB;

final class ReconciliationBaseline
{
    public const TABLES = [
        'students', 'enrollments', 'student_listener_placements', 'staff_members',
        'transport_routes', 'buses', 'student_transport_assignments',
        'vehicle_staff_assignments', 'master_student_imports', 'staff_master_imports',
        'invoices', 'invoice_items', 'invoice_payments', 'student_service_subscriptions',
    ];

    public const FINANCE_TABLES = [
        'invoices', 'invoice_items', 'invoice_payments', 'student_service_subscriptions',
    ];

    public function capture(): array
    {
        $tables = [];
        foreach (self::TABLES as $table) {
            $rows = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
            $tables[$table] = ['count' => count($rows), 'fingerprint' => $this->hash($rows)];
        }

        $protectedIds = DB::table('invoices')->pluck('student_id')
            ->merge(DB::table('student_service_subscriptions')->pluck('student_id'))
            ->filter()->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
        $protected = DB::table('students')->whereIn('id', $protectedIds)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        return [
            'tables' => $tables,
            'protected_student_ids' => $protectedIds,
            'protected_student_fingerprint' => $this->hash($protected),
        ];
    }

    public function assertSame(array $expected, array $actual): void
    {
        if ($expected !== $actual) {
            throw new \RuntimeException('Reconciliation plan is stale; the guarded database baseline changed.');
        }
    }

    public function financeSlice(array $baseline): array
    {
        return [
            'tables' => array_intersect_key($baseline['tables'], array_flip(self::FINANCE_TABLES)),
            'protected_student_ids' => $baseline['protected_student_ids'],
            'protected_student_fingerprint' => $baseline['protected_student_fingerprint'],
        ];
    }

    private function hash(array $rows): string
    {
        return hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }
}
