<?php

namespace App\Services\Transport;

use App\Exceptions\TransportCapacityExceeded;
use App\Models\Bus;
use App\Models\StudentTransportAssignment;
use App\Models\VehicleStaffAssignment;
use Carbon\CarbonImmutable;

/** Canonical simultaneous student and transported-passenger capacity validator. */
class TransportPassengerCapacityService
{
    public function assertStudentFits(Bus $bus, CarbonImmutable $from, CarbonImmutable $to, ?int $except = null): void
    {
        $this->assertFits($bus, $from, $to, null, true, null, $except, null);
    }

    public function assertStaffFits(Bus $bus, string $role, CarbonImmutable $from, ?CarbonImmutable $to, ?array $weekdays, ?int $except = null): void
    {
        $this->assertFits($bus, $from, $to, $weekdays, false, $role, null, $except);
    }

    private function assertFits(Bus $bus, CarbonImmutable $from, ?CarbonImmutable $to, ?array $weekdays, bool $studentCandidate, ?string $role, ?int $exceptStudent, ?int $exceptStaff): void
    {
        $students = StudentTransportAssignment::query()->with('enrollment.academicYear')->where('bus_id', $bus->id)
            ->when($exceptStudent, fn ($q) => $q->whereKeyNot($exceptStudent))->get();
        $staff = VehicleStaffAssignment::query()->where('bus_id', $bus->id)
            ->when($exceptStaff, fn ($q) => $q->whereKeyNot($exceptStaff))->get();
        $boundaries = collect([$from]);
        foreach ($students as $item) {
            $boundaries->push(CarbonImmutable::instance($item->effective_from));
            $end = $item->effective_to ?? $item->enrollment->academicYear->end_date;
            $boundaries->push(CarbonImmutable::instance($end)->addDay());
        }
        foreach ($staff as $item) {
            $boundaries->push(CarbonImmutable::instance($item->effective_from));
            if ($item->effective_to) {
                $boundaries->push(CarbonImmutable::instance($item->effective_to)->addDay());
            }
        }
        if ($to) {
            $boundaries->push($to->addDay());
        }
        $dates = $boundaries->filter(fn ($date) => $date->gte($from) && (! $to || $date->lte($to)))
            ->flatMap(fn ($date) => collect(range(0, 6))->map(fn ($offset) => $date->addDays($offset)))
            ->filter(fn ($date) => ! $to || $date->lte($to))->unique(fn ($date) => $date->toDateString());

        foreach ($dates as $date) {
            if (! $this->candidateApplies($date, $from, $to, $weekdays)) {
                continue;
            }
            $studentCount = $students->filter(fn ($item) => $this->studentApplies($item, $date))->count() + ($studentCandidate ? 1 : 0);
            $studentLimit = min(14, (int) $bus->student_capacity);
            if ($studentCount > $studentLimit) {
                throw new TransportCapacityExceeded("Student capacity {$studentLimit} exceeded on {$date->toDateString()}.");
            }
            $transportedStaff = $staff->filter(fn ($item) => $item->role !== VehicleStaffAssignment::ROLE_DRIVER && $this->staffApplies($item, $date))->count();
            $candidateStaff = ! $studentCandidate && $role !== VehicleStaffAssignment::ROLE_DRIVER ? 1 : 0;
            if ($studentCount + $transportedStaff + $candidateStaff > $bus->passenger_capacity) {
                throw new TransportCapacityExceeded("Passenger capacity {$bus->passenger_capacity} exceeded on {$date->toDateString()}.");
            }
        }
    }

    private function candidateApplies(CarbonImmutable $date, CarbonImmutable $from, ?CarbonImmutable $to, ?array $weekdays): bool
    {
        return $date->gte($from) && (! $to || $date->lte($to)) && ($weekdays === null || in_array($date->dayOfWeekIso, $weekdays, true));
    }

    private function studentApplies(StudentTransportAssignment $item, CarbonImmutable $date): bool
    {
        $to = $item->effective_to ?? $item->enrollment->academicYear->end_date;

        return $date->gte($item->effective_from) && $date->lte($to);
    }

    private function staffApplies(VehicleStaffAssignment $item, CarbonImmutable $date): bool
    {
        return $date->gte($item->effective_from) && (! $item->effective_to || $date->lte($item->effective_to))
            && ($item->weekdays === null || in_array($date->dayOfWeekIso, $item->weekdays, true));
    }
}
