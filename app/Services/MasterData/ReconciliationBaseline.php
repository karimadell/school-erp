<?php

namespace App\Services\MasterData;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ReconciliationBaseline
{
    public const TABLES = [
        'students', 'enrollments', 'student_listener_placements', 'staff_members',
        'transport_routes', 'buses', 'student_transport_assignments',
        'vehicle_staff_assignments', 'master_student_imports', 'staff_master_imports',
        'invoices', 'invoice_items', 'invoice_payments', 'student_service_subscriptions',
        'audit_logs',
    ];

    public const FINANCE_TABLES = [
        'invoices', 'invoice_items', 'invoice_payments', 'student_service_subscriptions',
    ];

    private const REQUIRED_COLUMNS = [
        'students' => ['id'],
        'enrollments' => ['id', 'student_id'],
        'student_listener_placements' => ['id', 'student_id'],
        'staff_members' => ['id'],
        'transport_routes' => ['id'],
        'buses' => ['id', 'transport_route_id'],
        'student_transport_assignments' => ['id', 'enrollment_id', 'transport_route_id', 'bus_id'],
        'vehicle_staff_assignments' => ['id', 'staff_member_id', 'bus_id'],
        'master_student_imports' => ['id', 'student_id'],
        'staff_master_imports' => ['id', 'staff_member_id'],
        'invoices' => ['id', 'student_id'],
        'invoice_items' => ['id', 'invoice_id', 'subscription_id'],
        'invoice_payments' => ['id', 'invoice_id'],
        'student_service_subscriptions' => [
            'id', 'enrollment_id', 'fee_id', 'start_date', 'end_date', 'quantity',
            'status', 'negotiated_price', 'negotiated_reason', 'negotiated_by',
            'metadata', 'created_at', 'updated_at',
        ],
        'audit_logs' => ['id'],
    ];

    private bool $schemaAudited = false;

    public function __construct(private ?ReconciliationPerformance $performance = null) {}

    public function capture(): array
    {
        $this->assertSchema();
        $tables = [];
        $raw = [];
        foreach (self::TABLES as $table) {
            $rows = $this->measure("fingerprint:{$table}", fn () => DB::table($table)
                ->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
            $raw[$table] = $rows;
            $tables[$table] = ['count' => count($rows), 'fingerprint' => $this->hash($rows)];
        }

        $enrollmentStudents = collect($raw['enrollments'])->pluck('student_id', 'id');
        $protectedIds = collect($raw['invoices'])->pluck('student_id')
            ->merge(collect($raw['student_service_subscriptions'])
                ->pluck('enrollment_id')->map(fn ($id) => $enrollmentStudents->get($id)))
            ->filter()->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
        $protected = collect($raw['students'])->whereIn('id', $protectedIds)->values()->all();

        return [
            'tables' => $tables,
            'protected_student_ids' => $protectedIds,
            'protected_student_fingerprint' => $this->hash($protected),
        ];
    }

    public function assertSchema(): void
    {
        if ($this->schemaAudited) {
            return;
        }

        foreach (self::REQUIRED_COLUMNS as $table => $required) {
            if (! Schema::hasTable($table)) {
                throw new \RuntimeException("Guarded table does not exist: {$table}");
            }
            $columns = Schema::getColumnListing($table);
            $missing = array_values(array_diff($required, $columns));
            if ($missing !== []) {
                throw new \RuntimeException("Guarded table {$table} is missing required columns: ".implode(', ', $missing));
            }
        }

        $this->schemaAudited = true;
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

    private function measure(string $stage, callable $callback): mixed
    {
        return $this->performance?->measure($stage, $callback) ?? $callback();
    }
}
