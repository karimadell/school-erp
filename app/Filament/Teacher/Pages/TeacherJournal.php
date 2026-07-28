<?php

namespace App\Filament\Teacher\Pages;

use Filament\Pages\Page;
use Filament\Notifications\Notification;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Support\Facades\Auth;
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

    public $assignedClasses = [];

    public function mount(): void
    {
        $teacher = $this->currentTeacher();

        if ($teacher) {
            $this->assignedClasses = SchoolClass::whereIn(
                'id',
                $teacher->currentAssignments()->pluck('class_id')
            )->get();
        }
    }

    protected function currentTeacher(): ?Teacher
    {
        return Teacher::where('user_id', Auth::id())->first();
    }

    public function loadStudents()
    {
        $teacher = $this->currentTeacher();

        if (! $teacher || ! $this->classId || ! $teacher->isAssignedToClass((int) $this->classId)) {
            $this->students = [];

            Notification::make()
                ->title('Вы не назначены на этот класс')
                ->danger()
                ->send();

            return;
        }

        $this->students = Student::where('class_id', $this->classId)->get();
    }
}
