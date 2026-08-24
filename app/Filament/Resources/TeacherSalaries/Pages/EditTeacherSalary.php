<?php

namespace App\Filament\Resources\TeacherSalaries\Pages;

use App\Filament\Resources\TeacherSalaries\TeacherSalaryResource;
use App\Services\Finance\EmployeePayrollService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditTeacherSalary extends EditRecord
{
    protected static string $resource = TeacherSalaryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->visible(fn () => $this->record->status === 'draft'),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['adjustments'] = $this->record->adjustments()->get(['type', 'amount', 'reason'])->toArray();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(EmployeePayrollService::class)->updateDraft(
            $record, (string) $data['base_salary'], $data['adjustments'] ?? [], auth()->user(), $data['position'] ?? null,
        );
    }
}
