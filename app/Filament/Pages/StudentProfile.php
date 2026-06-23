<?php

namespace App\Filament\Pages;

use App\Models\Student;
use Filament\Pages\Page;
use UnitEnum;

class StudentProfile extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user';

    protected static string|\UnitEnum|null $navigationGroup = 'Академия';
    protected static ?string $navigationLabel = 'Профиль студента';


    protected string $view = 'filament.pages.student-profile';

    public $studentId = null;
    public $student = null;

    public function loadStudent(): void
    {
        $this->student = Student::with([
            'class',
            'grades.subject',
            'grades.quarter',
            'attendances',
        ])->find($this->studentId);
    }

    public function getAttendanceCount(string $status): int
    {
        if (! $this->student) {
            return 0;
        }

        return $this->student->attendances
            ->where('status', $status)
            ->count();
    }

    public function getSubjectAverage(int $subjectId): string
    {
        if (! $this->student) {
            return '-';
        }

        $avg = $this->student->grades
            ->where('subject_id', $subjectId)
            ->avg('score');

        return $avg !== null ? number_format($avg, 2) : '-';
    }
}