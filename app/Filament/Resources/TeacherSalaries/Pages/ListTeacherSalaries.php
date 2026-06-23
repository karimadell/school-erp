<?php

namespace App\Filament\Resources\TeacherSalaries\Pages;

use App\Filament\Resources\TeacherSalaries\TeacherSalaryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTeacherSalaries extends ListRecords
{
    protected static string $resource = TeacherSalaryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
