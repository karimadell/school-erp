<?php

namespace App\Services\Transport;

use App\Models\AuditLog;
use App\Models\Bus;
use App\Models\StaffMember;
use App\Models\User;
use App\Models\VehicleStaffAssignment;
use App\Support\TransportPermissions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VehicleStaffAssignmentService
{
    public function __construct(private TransportPassengerCapacityService $capacity) {}

    public function assign(Bus $bus, User|StaffMember $staff, string $role, string $effectiveFrom, ?string $effectiveTo, ?array $weekdays, User $actor, ?string $reason = null): VehicleStaffAssignment
    {
        abort_unless($actor->can(TransportPermissions::MANAGE_ASSIGNMENTS), 403);

        return DB::transaction(function () use ($bus, $staff, $role, $effectiveFrom, $effectiveTo, $weekdays, $actor, $reason) {
            $bus = Bus::query()->lockForUpdate()->findOrFail($bus->id);
            if (! $bus->is_active || ! $this->isActive($staff)) {
                throw ValidationException::withMessages(['identity' => 'Транспорт и сотрудник должны быть активны.']);
            }
            if (! in_array($role, VehicleStaffAssignment::ROLES, true)) {
                throw ValidationException::withMessages(['role' => 'Недопустимая транспортная роль.']);
            }
            $from = CarbonImmutable::parse($effectiveFrom)->startOfDay();
            $to = filled($effectiveTo) ? CarbonImmutable::parse($effectiveTo)->startOfDay() : null;
            if ($to && $to->lt($from)) {
                throw ValidationException::withMessages(['effective_to' => 'Дата окончания не может быть раньше даты начала.']);
            }
            $weekdays = $this->normalizeWeekdays($weekdays);
            $this->capacity->assertStaffFits($bus, $role, $from, $to, $weekdays);

            if ($role === VehicleStaffAssignment::ROLE_SUPERVISOR) {
                $conflict = VehicleStaffAssignment::where('bus_id', $bus->id)->where('role', $role)
                    ->whereDate('effective_from', '<=', $to ?? '9999-12-31')->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from))
                    ->get()->contains(fn ($existing) => $this->weekdaysOverlap($existing->weekdays, $weekdays));
                if ($conflict) {
                    throw ValidationException::withMessages(['role' => 'На выбранные дни уже назначен ответственный сопровождающий.']);
                }
            }

            $assignment = VehicleStaffAssignment::create([
                'bus_id' => $bus->id,
                'user_id' => $staff instanceof User ? $staff->id : null,
                'staff_member_id' => $staff instanceof StaffMember ? $staff->id : null,
                'role' => $role,
                'effective_from' => $from, 'effective_to' => $to, 'weekdays' => $weekdays,
                'created_by' => $actor->id, 'change_reason' => $reason,
            ]);
            $this->audit($actor, 'vehicle_staff_assigned', $assignment, null, $assignment->toArray());

            return $assignment->fresh();
        });
    }

    public function end(VehicleStaffAssignment $assignment, string $effectiveTo, User $actor, ?string $reason = null): VehicleStaffAssignment
    {
        abort_unless($actor->can(TransportPermissions::MANAGE_ASSIGNMENTS), 403);

        return DB::transaction(function () use ($assignment, $effectiveTo, $actor, $reason) {
            $assignment = VehicleStaffAssignment::query()->lockForUpdate()->findOrFail($assignment->id);
            if ($assignment->effective_to !== null) {
                throw ValidationException::withMessages(['assignment' => 'Назначение сотрудника уже завершено.']);
            }
            $to = CarbonImmutable::parse($effectiveTo)->startOfDay();
            if ($to->lt($assignment->effective_from)) {
                throw ValidationException::withMessages(['effective_to' => 'Дата окончания не может быть раньше даты начала.']);
            }
            $old = $assignment->toArray();
            $assignment->update(['effective_to' => $to, 'ended_by' => $actor->id, 'change_reason' => $reason]);
            $this->audit($actor, 'vehicle_staff_assignment_ended', $assignment, $old, $assignment->fresh()->toArray());

            return $assignment->fresh();
        });
    }

    public function change(VehicleStaffAssignment $assignment, Bus $bus, User|StaffMember $staff, string $role, string $effectiveFrom, ?string $effectiveTo, ?array $weekdays, User $actor, ?string $reason = null): VehicleStaffAssignment
    {
        return DB::transaction(function () use ($assignment, $bus, $staff, $role, $effectiveFrom, $effectiveTo, $weekdays, $actor, $reason) {
            $assignment = VehicleStaffAssignment::query()->lockForUpdate()->findOrFail($assignment->id);
            Bus::query()->whereIn('id', array_unique([$assignment->bus_id, $bus->id]))->orderBy('id')->lockForUpdate()->get();
            $from = CarbonImmutable::parse($effectiveFrom)->startOfDay();
            $this->end($assignment, $from->subDay()->toDateString(), $actor, $reason);
            $new = $this->assign($bus, $staff, $role, $from->toDateString(), $effectiveTo, $weekdays, $actor, $reason);
            AuditLog::create(['user_id' => $actor->id, 'action' => 'vehicle_staff_changed', 'model' => VehicleStaffAssignment::class, 'model_id' => $new->id, 'old_values' => ['assignment_id' => $assignment->id], 'new_values' => ['assignment_id' => $new->id, 'effective_from' => $from->toDateString(), 'reason' => $reason]]);

            return $new;
        });
    }

    public function activeForDate(Bus $bus, string $date): Collection
    {
        $date = CarbonImmutable::parse($date)->startOfDay();
        $weekday = $date->dayOfWeekIso;

        return VehicleStaffAssignment::where('bus_id', $bus->id)->whereDate('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))->get()
            ->filter(fn ($assignment) => $assignment->weekdays === null || in_array($weekday, $assignment->weekdays, true))->values();
    }

    private function normalizeWeekdays(?array $weekdays): ?array
    {
        if ($weekdays === null || $weekdays === []) {
            return null;
        }
        $normalized = collect($weekdays)->map(fn ($day) => (int) $day)->unique()->sort()->values()->all();
        if (collect($normalized)->contains(fn ($day) => $day < 1 || $day > 7)) {
            throw ValidationException::withMessages(['weekdays' => 'Дни недели должны быть числами от 1 до 7.']);
        }

        return $normalized;
    }

    private function isActive(User|StaffMember $staff): bool
    {
        return $staff instanceof User ? $staff->isActive() : $staff->is_active;
    }

    private function weekdaysOverlap(?array $left, ?array $right): bool
    {
        return $left === null || $right === null || array_intersect($left, $right) !== [];
    }

    private function audit(User $actor, string $action, VehicleStaffAssignment $assignment, ?array $old, array $new): void
    {
        AuditLog::create(['user_id' => $actor->id, 'action' => $action, 'model' => VehicleStaffAssignment::class, 'model_id' => $assignment->id, 'old_values' => $old, 'new_values' => $new]);
    }
}
