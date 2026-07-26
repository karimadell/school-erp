<?php

namespace App\Filament\Resources\Attendances\Pages;

use App\Filament\Resources\Attendances\AttendanceResource;
use App\Models\Attendance;
use Filament\Resources\Pages\CreateRecord;

class CreateAttendance extends CreateRecord
{
    protected static string $resource = AttendanceResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['attendance_key'] = Attendance::buildAttendanceKey(
            $data['type'],
            (int) $data['enrollment_id'],
            $data['date'],
            $data['period_id'] ?? null,
        );

        return $data;
    }
}
