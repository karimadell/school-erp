<?php

namespace App\Filament\Resources\TeacherSalaries\Pages;

use App\Filament\Resources\TeacherSalaries\TeacherSalaryResource;
use App\Models\User;
use App\Services\Finance\EmployeePayrollService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTeacherSalary extends CreateRecord
{
    protected static string $resource = TeacherSalaryResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(EmployeePayrollService::class)->create(
            User::findOrFail($data['employee_user_id']), $data['salary_month'], (string) $data['base_salary'],
            $data['adjustments'] ?? [], auth()->user(), $data['position'] ?? null,
        );
    }
}
