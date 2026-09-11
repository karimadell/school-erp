<?php

namespace App\Services\MasterData;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ReconciliationBaseline
{
    public const TABLES = [
        'academic_years', 'enrollment_modes', 'stages', 'grades', 'classes',
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
        'academic_years' => ['id', 'name', 'start_date', 'end_date', 'is_active'],
        'enrollment_modes' => ['id', 'code', 'name_ru', 'is_active'],
        'stages' => ['id', 'name', 'order', 'is_active'],
        'grades' => ['id', 'stage_id', 'name', 'level'],
        'classes' => ['id', 'grade_id', 'code', 'name_ar', 'name_ru', 'capacity', 'is_active'],
        'students' => ['id', 'name', 'preferred_name', 'merged_into_student_id', 'status', 'first_name', 'last_name', 'patronymic', 'first_name_ru', 'last_name_ru', 'patronymic_ru', 'address', 'residential_address', 'registration_status', 'created_at', 'updated_at', 'deleted_at'],
        'enrollments' => ['id', 'student_id', 'academic_year_id', 'enrollment_mode_id', 'study_attendance_mode', 'stage_id', 'grade_id', 'class_id', 'academic_year', 'enrollment_date', 'enrolled_at', 'status', 'is_active', 'created_at', 'updated_at'],
        'student_listener_placements' => ['id', 'student_id', 'academic_year_id', 'stage_id', 'grade_id', 'class_id', 'source_marker', 'status', 'effective_from', 'effective_to', 'created_at', 'updated_at'],
        'staff_members' => ['id', 'display_name', 'phone', 'user_id', 'is_active', 'created_at', 'updated_at'],
        'transport_routes' => ['id', 'name', 'pricing_zone', 'is_active', 'created_at', 'updated_at'],
        'buses' => ['id', 'vehicle_code', 'name', 'plate_number', 'driver_name', 'capacity', 'student_capacity', 'passenger_capacity', 'transport_route_id', 'is_active', 'created_at', 'updated_at'],
        'student_transport_assignments' => ['id', 'enrollment_id', 'transport_route_id', 'bus_id', 'pricing_zone', 'pickup_point', 'billing_period', 'effective_from', 'effective_to', 'status', 'created_by', 'ended_by', 'change_reason', 'created_at', 'updated_at'],
        'vehicle_staff_assignments' => ['id', 'bus_id', 'user_id', 'staff_member_id', 'role', 'effective_from', 'effective_to', 'weekdays', 'created_by', 'ended_by', 'change_reason', 'created_at', 'updated_at'],
        'master_student_imports' => ['id', 'source_key', 'source_file', 'source_sheet', 'source_row', 'raw_name', 'raw_class_group', 'attendance_marker', 'resolution_status', 'resolution_evidence', 'source_data', 'student_id', 'enrollment_id', 'listener_placement_id', 'created_at', 'updated_at'],
        'staff_master_imports' => ['id', 'source_key', 'source_file', 'source_sheet', 'source_row', 'raw_name', 'position', 'raw_contact', 'raw_birth_date', 'source_data', 'staff_member_id', 'created_at', 'updated_at'],
        'invoices' => ['id', 'student_id'],
        'invoice_items' => ['id', 'invoice_id', 'subscription_id'],
        'invoice_payments' => ['id', 'invoice_id'],
        'student_service_subscriptions' => [
            'id', 'enrollment_id', 'fee_id', 'start_date', 'end_date', 'quantity',
            'status', 'negotiated_price', 'negotiated_reason', 'negotiated_by',
            'metadata', 'created_at', 'updated_at',
        ],
        'audit_logs' => ['id', 'user_id', 'action', 'model', 'model_id', 'old_values', 'new_values', 'ip', 'user_agent', 'created_at', 'updated_at'],
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
