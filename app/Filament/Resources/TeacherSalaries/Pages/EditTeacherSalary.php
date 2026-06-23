<?php

namespace App\Filament\Resources\TeacherSalaries\Pages;

use App\Filament\Resources\TeacherSalaries\TeacherSalaryResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTeacherSalary extends EditRecord
{
    protected static string $resource = TeacherSalaryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
