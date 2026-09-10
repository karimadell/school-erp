<?php

namespace App\Services\MasterData;

use App\Events\MasterDataReconciliationPhase;
use App\Models\Bus;
use App\Models\StudentTransportAssignment;
use App\Models\TransportRoute;
use App\Models\User;
use App\Models\VehicleStaffAssignment;
use App\Services\Transport\RealStaffTransportAssignmentBootstrapService;
use App\Services\Transport\RealStudentTransportAssignmentBootstrapService;
use App\Services\Transport\RealTransportCatalogBootstrapService;
use App\Support\RouteNameNormalizer;
use Illuminate\Support\Facades\DB;

final class MasterDataReconciliationApplyService
{
    private const LOCK_TABLES = [
        'students', 'enrollments', 'student_listener_placements', 'staff_members',
        'transport_routes', 'buses', 'student_transport_assignments',
        'vehicle_staff_assignments', 'master_student_imports', 'staff_master_imports',
        'invoices', 'invoice_items', 'invoice_payments', 'student_service_subscriptions',
    ];

    public function __construct(
        private MasterStudentImportService $students,
        private MasterStaffImportService $staff,
        private RealTransportCatalogBootstrapService $catalog,
        private RealStudentTransportAssignmentBootstrapService $studentTransport,
        private RealStaffTransportAssignmentBootstrapService $staffTransport,
        private ReconciliationBaseline $baseline,
        private WorkbookLoader $workbooks,
    ) {}

    public function apply(User $actor, MasterDataReconciliationPlan $plan): array
    {
        $started = hrtime(true);
        $transactionStarted = 0;
        $this->workbooks->forbidFurtherPhysicalLoads();
        $result = DB::transaction(function () use ($actor, $plan, &$transactionStarted): array {
            $transactionStarted = hrtime(true);
            $this->lockBaseline();
            $this->baseline->assertSame($plan->baseline, $this->baseline->capture());
            event(new MasterDataReconciliationPhase('guards_passed'));

            $student = $this->students->persistPlan($actor, collect($plan->students));
            event(new MasterDataReconciliationPhase('students_persisted'));

            $staff = $this->staff->persistPlan($actor, $this->collections($plan->staff));
            event(new MasterDataReconciliationPhase('staff_persisted'));

            $catalog = $this->catalog->persistPlan(collect($plan->catalog));
            $this->assertCapacity($plan, false);
            $studentTransport = $this->studentTransport->persistPlan($actor, $this->collections($plan->studentTransport));
            $staffTransport = $this->staffTransport->persistPlan($actor, collect($plan->staffTransport));
            event(new MasterDataReconciliationPhase('transport_persisted'));

            $this->assertCapacity($plan, true);
            $current = $this->baseline->financeSlice($this->baseline->capture());
            $expected = $this->baseline->financeSlice($plan->baseline);
            if ($current !== $expected) {
                throw new \RuntimeException('Finance or protected Student invariant changed during reconciliation persistence.');
            }
            event(new MasterDataReconciliationPhase('final_assertions_passed'));

            return compact('student', 'staff', 'catalog', 'studentTransport', 'staffTransport');
        });
        $finished = hrtime(true);

        return $result + [
            'plan_hash' => $plan->planHash,
            'transaction_seconds' => round(($finished - $transactionStarted) / 1e9, 6),
            'total_apply_seconds' => round(($finished - $started) / 1e9, 6),
        ];
    }

    private function lockBaseline(): void
    {
        if (DB::getDriverName() === 'sqlite' && app()->environment('testing')) {
            return;
        }
        if (DB::getDriverName() !== 'pgsql') {
            throw new \RuntimeException('Atomic master-data reconciliation APPLY requires PostgreSQL table locking.');
        }
        foreach (self::LOCK_TABLES as $table) {
            DB::statement("LOCK TABLE {$table} IN SHARE ROW EXCLUSIVE MODE");
        }
    }

    private function assertCapacity(MasterDataReconciliationPlan $plan, bool $persisted): void
    {
        foreach ($plan->capacity as $routeName => $expected) {
            $route = TransportRoute::query()->get()->sole(fn ($route) => RouteNameNormalizer::key($route->name) === RouteNameNormalizer::key($routeName));
            $bus = Bus::query()->where('transport_route_id', $route->id)->sole();
            $students = StudentTransportAssignment::query()->where('bus_id', $bus->id)->where('status', 'active')->whereNull('effective_to')->count();
            $staff = VehicleStaffAssignment::query()->where('bus_id', $bus->id)->whereNull('effective_to')->count();
            if ($persisted && ($students !== $expected['students'] || $staff !== $expected['staff'])) {
                throw new \RuntimeException("Capacity invariant failed for {$routeName}.");
            }
            if ($expected['students'] > $bus->student_capacity || $expected['passengers'] > $bus->passenger_capacity) {
                throw new \RuntimeException("Immutable capacity plan exceeds bus capacity for {$routeName}.");
            }
        }
    }

    private function collections(array $value): array
    {
        return array_map(fn ($item) => is_array($item) && array_is_list($item) ? collect($item) : $item, $value);
    }
}
