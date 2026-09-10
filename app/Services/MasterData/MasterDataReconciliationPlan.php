<?php

namespace App\Services\MasterData;

final readonly class MasterDataReconciliationPlan
{
    public string $planHash;

    public function __construct(
        public array $sourceHashes,
        public array $students,
        public array $staff,
        public array $catalog,
        public array $studentTransport,
        public array $staffTransport,
        public array $capacity,
        public array $baseline,
    ) {
        $this->planHash = hash('sha256', json_encode($this->canonical([
            'source_hashes' => $sourceHashes, 'students' => $students, 'staff' => $staff,
            'catalog' => $catalog, 'student_transport' => $studentTransport,
            'staff_transport' => $staffTransport, 'capacity' => $capacity,
            'baseline' => $baseline,
        ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    public function summary(): array
    {
        return [
            'plan_hash' => $this->planHash,
            'source_hashes' => $this->sourceHashes,
            'master_students' => count($this->students),
            'review_required_students' => count(array_filter($this->students, fn ($row) => $row['action'] === 'REVIEW_REQUIRED')),
            'new_students' => count(array_filter($this->students, fn ($row) => str_starts_with($row['action'], 'CREATE_'))),
            'new_enrollments' => count(array_filter($this->students, fn ($row) => in_array($row['action'], ['CREATE_ENROLLED', 'ENROLL_EXISTING'], true))),
            'new_listeners' => count(array_filter($this->students, fn ($row) => in_array($row['action'], ['CREATE_LISTENER', 'PLACE_EXISTING_LISTENER'], true))),
            'new_student_metadata' => count(array_filter($this->students, fn ($row) => ! $row['already_imported'])),
            'master_staff' => count($this->staff['staff']),
            'review_required_staff' => 0,
            'new_staff' => count(array_filter($this->staff['staff'], fn ($row) => $row['action'] === 'CREATE_STAFF_MEMBER' && ! $row['already_imported'])),
            'new_staff_metadata' => count(array_filter($this->staff['staff'], fn ($row) => ! $row['already_imported'])),
            'new_routes' => count(array_filter($this->catalog, fn ($row) => ! $row['route_exists'])),
            'new_buses' => count(array_filter($this->catalog, fn ($row) => ! $row['vehicle_exists'])),
            'new_student_assignments' => count(array_filter($this->studentTransport['current'], fn ($row) => ! $row['assignment_exists'])),
            'new_staff_assignments' => count(array_filter($this->staffTransport, fn ($row) => ! $row['assignment_exists'])),
            'capacity' => $this->capacity,
            'baseline' => $this->baseline,
        ];
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonical($item);
        }

        return $value;
    }
}
