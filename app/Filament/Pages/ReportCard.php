<?php

namespace App\Filament\Pages;

use App\Models\Student;
use App\Models\Subject;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use UnitEnum;

class ReportCard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Табель';

    protected static string|UnitEnum|null $navigationGroup = 'Учебный процесс';

    protected string $view = 'filament.pages.report-card';

    public $studentId = null;
    public $student = null;
    public $subjects = [];

    public function mount(): void
    {
        $this->subjects = Subject::all();
    }

    public function loadStudent(): void
    {
        $this->student = Student::with([
            'class',
            'grades.subject',
            'grades.quarter',
        ])->find($this->studentId);
    }

    public function getQuarterScore($subjectId, $quarterId)
    {
        if (! $this->student) {
            return '-';
        }

        return $this->student->grades
            ->where('subject_id', $subjectId)
            ->where('quarter_id', $quarterId)
            ->first()?->score ?? '-';
    }

    public function getYearScore($subjectId)
    {
        if (! $this->student) {
            return '-';
        }

        return $this->student->yearGrade($subjectId);
    }

    public function downloadPdf()
    {
        if (! $this->student) {
            return null;
        }

        // Not implemented yet — blocked on Section 4's grading policy (see
        // docs/OPEN_POLICY_DECISIONS.md). The PDF view this used to render
        // called Student::quarterGrade(), a method that doesn't exist, and
        // crashed on every click.
        Notification::make()
            ->title(__('report_cards.not_available_yet'))
            ->warning()
            ->send();

        return null;
    }
}