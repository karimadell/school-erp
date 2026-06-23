<?php

namespace App\Filament\Teacher\Pages;

use Filament\Pages\Page;
use App\Models\SchoolClass;
use App\Models\Student;
use UnitEnum;
use BackedEnum;

class TeacherJournal extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static string|UnitEnum|null $navigationGroup = 'Преподаватель';

    protected static ?string $navigationLabel = 'Журнал';

    protected string $view = 'filament.teacher.pages.teacher-journal';

    public $classId;

    public $students = [];

    public function loadStudents()
    {
        $this->students = Student::where('class_id', $this->classId)->get();
    }
}