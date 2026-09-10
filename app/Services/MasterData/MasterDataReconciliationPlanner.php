<?php

namespace App\Services\MasterData;

use App\Services\Transport\RealStaffTransportAssignmentBootstrapService;
use App\Services\Transport\RealStudentTransportAssignmentBootstrapService;
use App\Services\Transport\RealTransportCatalogBootstrapService;
use App\Support\RouteNameNormalizer;
use Illuminate\Validation\ValidationException;

final class MasterDataReconciliationPlanner
{
    public function __construct(
        private MasterStudentImportService $students,
        private MasterStaffImportService $staff,
        private RealTransportCatalogBootstrapService $catalog,
        private RealStudentTransportAssignmentBootstrapService $studentTransport,
        private RealStaffTransportAssignmentBootstrapService $staffTransport,
        private ReconciliationBaseline $baseline,
        private ReconciliationPerformance $performance,
    ) {}

    public function plan(string $studentPath, string $staffPath, array $transportPaths): MasterDataReconciliationPlan
    {
        $this->performance->measure('schema_audit', fn () => $this->baseline->assertSchema());
        $paths = [$studentPath, $staffPath, ...$transportPaths];
        if (count($paths) !== 7 || count(array_unique(array_map(fn ($path) => realpath($path), $paths))) !== 7) {
            throw ValidationException::withMessages(['sources' => 'Exactly seven distinct authoritative workbooks are required.']);
        }
        $hashes = $this->performance->measure('source_hashes', function () use ($paths): array {
            $hashes = [];
            foreach ($paths as $path) {
                if (! is_file($path)) {
                    throw ValidationException::withMessages(['sources' => "Source workbook not found: {$path}"]);
                }
                $hashes[basename($path)] = hash_file('sha256', $path);
            }
            ksort($hashes, SORT_STRING);

            return $hashes;
        });

        $studentPlan = $this->performance->measure('student_planning', fn () => $this->students->preparePlan($studentPath)->values());
        $students = $studentPlan->all();
        $preparedStaff = $this->performance->measure('staff_planning', fn () => $this->staff->preparePlan($staffPath, $transportPaths));
        $staff = $this->arrays($preparedStaff);
        $catalog = $this->performance->measure('transport_catalog_planning', fn () => $this->catalog->preparePlan()->values()->all());
        $studentTransport = $this->performance->measure('student_assignment_planning', fn () => $this->arrays($this->studentTransport->preparePlan($transportPaths, $studentPath, $studentPlan)));
        $staffTransport = $this->performance->measure('staff_assignment_planning', fn () => $this->staffTransport->preparePlan($transportPaths, $staffPath, $preparedStaff)->values()->all());

        $capacity = $this->performance->measure('capacity_validation', fn () => collect($staffTransport)->groupBy(fn ($row) => RouteNameNormalizer::canonicalName($row['route']))
            ->map(function ($rows, $route): array {
                $students = ['Арабия' => 13, 'Бествэй' => 12, 'Бритиш' => 12, 'Каусер' => 14, 'Эль Ахья' => 12][$route];

                return ['students' => $students, 'staff' => count($rows), 'passengers' => $students + count($rows), 'capacity' => 15];
            })->sortKeys()->all());

        $markers = collect($students)->countBy(fn ($row) => $row['attendance_marker'] ?? 'STANDARD');
        if (count($students) !== 134 || $markers->get('STANDARD', 0) !== 118 || $markers->get('ДО', 0) !== 12 || $markers->get('БЗ', 0) !== 4
            || collect($students)->where('action', 'REVIEW_REQUIRED')->isNotEmpty()
            || count($staff['staff']) !== 26 || count($staff['links']) !== 11 || count($catalog) !== 5
            || count($studentTransport['current']) !== 63 || count($studentTransport['history']) !== 1 || count($staffTransport) !== 11
            || collect($capacity)->contains(fn ($row) => $row['students'] > 14 || $row['passengers'] > 15)) {
            throw ValidationException::withMessages(['plan' => 'The complete reconciliation plan failed its approved cardinality/capacity gate.']);
        }

        $baseline = $this->performance->measure('baseline_fingerprinting', fn () => $this->baseline->capture());

        return $this->performance->measure('plan_serialization_hash', fn () => new MasterDataReconciliationPlan($hashes, $students, $staff, $catalog, $studentTransport, $staffTransport, $capacity, $baseline));
    }

    private function arrays(mixed $value): mixed
    {
        if ($value instanceof \Illuminate\Support\Collection) {
            return $value->map(fn ($item) => $this->arrays($item))->values()->all();
        }
        if (is_array($value)) {
            return array_map(fn ($item) => $this->arrays($item), $value);
        }

        return $value;
    }
}
