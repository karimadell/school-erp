<?php

namespace App\Filament\Resources\StudentGrades\Pages;

use App\Filament\Resources\StudentGrades\StudentGradeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateStudentGrade extends CreateRecord
{
    protected static string $resource = StudentGradeResource::class;
}
