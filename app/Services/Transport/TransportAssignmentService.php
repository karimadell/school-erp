<?php

namespace App\Services\Transport;

use App\Exceptions\TransportCapacityExceeded;
use App\Models\AuditLog;
use App\Models\Bus;
use App\Models\Enrollment;
use App\Models\StudentTransportAssignment;
use App\Models\TransportRoute;
use App\Models\User;
use App\Support\TransportPermissions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransportAssignmentService
{
    public function __construct(private TransportPassengerCapacityService $capacity) {}

    public function assign(Enrollment $enrollment, TransportRoute $route, Bus $bus, array $data, User $actor): StudentTransportAssignment
    {
        abort_unless($actor->can(TransportPermissions::MANAGE_ASSIGNMENTS), 403);

        try {
            return DB::transaction(fn () => $this->assignWithinTransaction($enrollment, $route, $bus, $data, $actor));
        } catch (TransportCapacityExceeded $e) {
            AuditLog::create([
                'user_id' => $actor->id, 'action' => 'student_transport_capacity_rejected',
                'model' => StudentTransportAssignment::class, 'model_id' => null,
                'new_values' => ['enrollment_id' => $enrollment->id, 'transport_route_id' => $route->id, 'bus_id' => $bus->id, 'effective_from' => $data['effective_from'] ?? null, 'effective_to' => $data['effective_to'] ?? null],
            ]);
            throw $e;
        }
    }

    /** Assign without opening a savepoint. The caller must own the transaction. */
    public function assignWithinTransaction(Enrollment $enrollment, TransportRoute $route, Bus $bus, array $data, User $actor): StudentTransportAssignment
    {
        abort_unless(DB::transactionLevel() > 0, 500, 'An active transaction is required.');
        $enrollment = Enrollment::query()->lockForUpdate()->findOrFail($enrollment->id);
        $bus = Bus::query()->lockForUpdate()->findOrFail($bus->id);
        $route = TransportRoute::query()->lockForUpdate()->findOrFail($route->id);
        [$from, $to, $capacityTo] = $this->validate($enrollment, $route, $bus, $data);
        $this->assertEnrollmentAvailable($enrollment->id, $from, $to);
        $this->capacity->assertStudentFits($bus, $from, $capacityTo);

        $assignment = StudentTransportAssignment::create([
            'enrollment_id' => $enrollment->id,
            'transport_route_id' => $route->id,
            'bus_id' => $bus->id,
            'pricing_zone' => $data['pricing_zone'] ?? $route->pricing_zone,
            'pickup_point' => $data['pickup_point'] ?? null,
            'billing_period' => $data['billing_period'] ?? null,
            'effective_from' => $from,
            'effective_to' => $to,
            'status' => StudentTransportAssignment::STATUS_ACTIVE,
            'created_by' => $actor->id,
            'change_reason' => $data['change_reason'] ?? null,
        ]);
        $this->audit($actor, 'student_transport_assigned', $assignment, null, $assignment->toArray());

        return $assignment->fresh();
    }

    public function end(StudentTransportAssignment $assignment, string $effectiveTo, User $actor, ?string $reason = null): StudentTransportAssignment
    {
        abort_unless($actor->can(TransportPermissions::MANAGE_ASSIGNMENTS), 403);

        return DB::transaction(function () use ($assignment, $effectiveTo, $actor, $reason) {
            $assignment = StudentTransportAssignment::query()->lockForUpdate()->findOrFail($assignment->id);
            if ($assignment->status !== StudentTransportAssignment::STATUS_ACTIVE || $assignment->effective_to !== null) {
                throw ValidationException::withMessages(['assignment' => 'Транспортное назначение уже завершено.']);
            }
            $to = CarbonImmutable::parse($effectiveTo)->startOfDay();
            if ($to->lt($assignment->effective_from)) {
                throw ValidationException::withMessages(['effective_to' => 'Дата окончания не может быть раньше даты начала.']);
            }
            $old = $assignment->toArray();
            $assignment->update(['effective_to' => $to, 'status' => StudentTransportAssignment::STATUS_ENDED, 'ended_by' => $actor->id, 'change_reason' => $reason]);
            $this->audit($actor, 'student_transport_assignment_ended', $assignment, $old, $assignment->fresh()->toArray());

            return $assignment->fresh();
        });
    }

    public function transfer(StudentTransportAssignment $assignment, TransportRoute $route, Bus $bus, string $effectiveFrom, array $data, User $actor): StudentTransportAssignment
    {
        abort_unless($actor->can(TransportPermissions::MANAGE_ASSIGNMENTS), 403);

        try {
            return DB::transaction(function () use ($assignment, $route, $bus, $effectiveFrom, $data, $actor) {
                $enrollment = Enrollment::query()->lockForUpdate()->findOrFail($assignment->enrollment_id);
                $assignment = StudentTransportAssignment::query()->lockForUpdate()->findOrFail($assignment->id);
                if ($assignment->status !== StudentTransportAssignment::STATUS_ACTIVE || $assignment->effective_to !== null) {
                    throw ValidationException::withMessages(['assignment' => 'Перевести можно только текущее назначение.']);
                }
                Bus::query()->whereIn('id', array_values(array_unique([$assignment->bus_id, $bus->id])))->orderBy('id')->lockForUpdate()->get();
                $destination = Bus::findOrFail($bus->id);
                $route = TransportRoute::query()->lockForUpdate()->findOrFail($route->id);
                $from = CarbonImmutable::parse($effectiveFrom)->startOfDay();
                if ($from->lte($assignment->effective_from) || ($assignment->effective_to && $from->gt($assignment->effective_to))) {
                    throw ValidationException::withMessages(['effective_from' => 'Дата перевода должна быть позже начала и внутри текущего назначения.']);
                }
                [, $to, $capacityTo] = $this->validate($enrollment, $route, $destination, array_merge($data, ['effective_from' => $from, 'effective_to' => $data['effective_to'] ?? null]));
                $this->assertEnrollmentAvailable($enrollment->id, $from, $to, $assignment->id);
                $this->capacity->assertStudentFits($destination, $from, $capacityTo, $assignment->id);

                $old = $assignment->toArray();
                $assignment->update(['effective_to' => $from->subDay(), 'status' => StudentTransportAssignment::STATUS_ENDED, 'ended_by' => $actor->id, 'change_reason' => $data['change_reason'] ?? null]);
                $new = StudentTransportAssignment::create([
                    'enrollment_id' => $enrollment->id, 'transport_route_id' => $route->id, 'bus_id' => $destination->id,
                    'pricing_zone' => $data['pricing_zone'] ?? $route->pricing_zone, 'pickup_point' => $data['pickup_point'] ?? $assignment->pickup_point,
                    'billing_period' => $data['billing_period'] ?? $assignment->billing_period, 'effective_from' => $from, 'effective_to' => $to,
                    'status' => StudentTransportAssignment::STATUS_ACTIVE, 'created_by' => $actor->id, 'change_reason' => $data['change_reason'] ?? null,
                ]);
                $this->audit($actor, 'student_transport_transferred', $new, $old, ['ended_assignment_id' => $assignment->id, 'new_assignment' => $new->toArray()]);

                return $new->fresh();
            });
        } catch (TransportCapacityExceeded $e) {
            AuditLog::create(['user_id' => $actor->id, 'action' => 'student_transport_capacity_rejected', 'model' => StudentTransportAssignment::class, 'model_id' => $assignment->id, 'old_values' => $assignment->toArray(), 'new_values' => ['transport_route_id' => $route->id, 'bus_id' => $bus->id, 'effective_from' => $effectiveFrom]]);
            throw $e;
        }
    }

    private function validate(Enrollment $enrollment, TransportRoute $route, Bus $bus, array $data): array
    {
        if (! $bus->is_active) {
            throw ValidationException::withMessages(['bus_id' => 'Нельзя назначить неактивный транспорт.']);
        }
        if (! $route->is_active) {
            throw ValidationException::withMessages(['transport_route_id' => 'Нельзя назначить неактивный маршрут.']);
        }
        $year = $enrollment->academicYear()->firstOrFail();
        $from = CarbonImmutable::parse($data['effective_from'])->startOfDay();
        $to = filled($data['effective_to'] ?? null) ? CarbonImmutable::parse($data['effective_to'])->startOfDay() : null;
        if ($to && $to->lt($from)) {
            throw ValidationException::withMessages(['effective_to' => 'Дата окончания не может быть раньше даты начала.']);
        }
        if ($from->lt($year->start_date) || $from->gt($year->end_date) || ($to && $to->gt($year->end_date))) {
            throw ValidationException::withMessages(['effective_from' => 'Назначение должно находиться внутри учебного года enrollment.']);
        }

        return [$from, $to, $to ?? CarbonImmutable::instance($year->end_date)];
    }

    private function assertEnrollmentAvailable(int $enrollmentId, CarbonImmutable $from, ?CarbonImmutable $to, ?int $except = null): void
    {
        $exists = StudentTransportAssignment::where('enrollment_id', $enrollmentId)->when($except, fn ($q) => $q->where('id', '!=', $except))
            ->whereDate('effective_from', '<=', $to ?? '9999-12-31')->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from))->exists();
        if ($exists) {
            throw ValidationException::withMessages(['enrollment_id' => 'У enrollment уже есть пересекающееся транспортное назначение.']);
        }
    }

    private function audit(User $actor, string $action, StudentTransportAssignment $assignment, ?array $old, array $new): void
    {
        AuditLog::create(['user_id' => $actor->id, 'action' => $action, 'model' => StudentTransportAssignment::class, 'model_id' => $assignment->id, 'old_values' => $old, 'new_values' => $new]);
    }
}
