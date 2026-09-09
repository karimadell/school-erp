<?php

namespace App\Services\MasterData;

use App\Services\Transport\RealStaffTransportAssignmentBootstrapService;
use App\Services\Transport\RealStudentTransportAssignmentBootstrapService;

/** Composes the complete dependency-aware, zero-write master-data plan. */
class MasterDataReconciliationPreviewService
{
    public function __construct(
        private MasterStudentImportService $students,
        private MasterStaffImportService $staff,
        private RealStudentTransportAssignmentBootstrapService $studentTransport,
        private RealStaffTransportAssignmentBootstrapService $staffTransport,
    ) {}

    public function preview(string $studentPath, string $staffPath, array $transportPaths): array
    {
        $students = $this->students->preview($studentPath);
        $staff = $this->staff->preview($staffPath, $transportPaths);
        $studentTransport = $this->studentTransport->preview($transportPaths, $studentPath);
        $staffTransport = $this->staffTransport->preview($transportPaths, $staffPath);

        return [
            'mode' => 'PREVIEW',
            'students' => $students,
            'staff' => $staff,
            'transport_catalog' => $staff['transport_catalog'],
            'student_transport' => $studentTransport,
            'staff_transport' => $staffTransport,
            'finance_linked_students_affected' => collect($students['rows'])->where('finance_linked', true)->values()->all(),
            'capacity_valid' => $staffTransport['capacity_valid'],
            'apply_order' => [
                'master_students', 'master_staff', 'transport_catalog',
                'student_transport_assignments', 'vehicle_staff_assignments',
            ],
        ];
    }
}
