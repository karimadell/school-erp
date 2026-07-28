<?php

namespace App\Filament\Teacher\Pages;

use Filament\Pages\Page;
use Filament\Notifications\Notification;
use App\Models\AcademicYear;
use App\Models\LessonJournalEntry;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Teacher;
use Illuminate\Support\Facades\Auth;
use UnitEnum;
use BackedEnum;

/**
 * Batch 9: real lesson journal, scoped through TeacherAssignment.
 * Replaces the previous read-only student roster.
 */
class TeacherJournal extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected string $view = 'filament.teacher.pages.teacher-journal';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('teacher_portal.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('teacher_portal.nav_journal');
    }

    public $classId;
    public $subjectId;

    public $entries = [];

    public $assignedClasses = [];
    public $assignedSubjects = [];

    public ?int $editingId = null;
    public $date;
    public $lessonTitle;
    public $notes;
    public $homework;

    public function mount(): void
    {
        $teacher = $this->currentTeacher();

        if ($teacher) {
            $assignments = $teacher->currentAssignments()->get();

            $this->assignedClasses = SchoolClass::whereIn('id', $assignments->pluck('class_id'))->get();
            $this->assignedSubjects = Subject::whereIn('id', $assignments->pluck('subject_id'))->get();
        }
    }

    protected function currentTeacher(): ?Teacher
    {
        return Teacher::where('user_id', Auth::id())->first();
    }

    protected function authorizeClassSubjectAccess(): bool
    {
        $teacher = $this->currentTeacher();

        if (
            ! $teacher
            || ! $this->classId
            || ! $this->subjectId
            || ! $teacher->isAssignedToClassSubject((int) $this->classId, (int) $this->subjectId)
        ) {
            Notification::make()
                ->title(__('teacher_portal.not_assigned_to_class_subject'))
                ->danger()
                ->send();

            return false;
        }

        return true;
    }

    public function loadEntries(): void
    {
        if (! $this->authorizeClassSubjectAccess()) {
            $this->entries = [];

            return;
        }

        $teacher = $this->currentTeacher();

        $this->entries = LessonJournalEntry::where('teacher_id', $teacher->id)
            ->where('class_id', $this->classId)
            ->where('subject_id', $this->subjectId)
            ->orderByDesc('date')
            ->get();
    }

    public function startCreate(): void
    {
        $this->editingId = null;
        $this->date = null;
        $this->lessonTitle = null;
        $this->notes = null;
        $this->homework = null;
    }

    /**
     * Loading an entry for edit must verify it actually belongs to this
     * teacher and to the currently authorized class/subject — otherwise
     * a tampered entry ID could expose another teacher's lesson content.
     */
    public function editEntry(int $entryId): void
    {
        if (! $this->authorizeClassSubjectAccess()) {
            return;
        }

        $teacher = $this->currentTeacher();

        $entry = LessonJournalEntry::where('id', $entryId)
            ->where('teacher_id', $teacher->id)
            ->where('class_id', $this->classId)
            ->where('subject_id', $this->subjectId)
            ->first();

        if (! $entry) {
            Notification::make()
                ->title(__('teacher_portal.not_assigned_to_class_subject'))
                ->danger()
                ->send();

            return;
        }

        $this->editingId = $entry->id;
        $this->date = $entry->date->toDateString();
        $this->lessonTitle = $entry->title;
        $this->notes = $entry->notes;
        $this->homework = $entry->homework;
    }

    public function cancelEdit(): void
    {
        $this->startCreate();
    }

    public function saveEntry(): void
    {
        // Re-checked here, not just in loadEntries(): classId/subjectId
        // are client state and could be tampered with between load and
        // save.
        if (! $this->authorizeClassSubjectAccess()) {
            return;
        }

        $this->validate([
            'date' => 'required|date',
            'lessonTitle' => 'required|string|max:255',
            'notes' => 'nullable|string',
            'homework' => 'nullable|string',
        ]);

        $teacher = $this->currentTeacher();
        $activeYearId = AcademicYear::where('is_active', true)->value('id');

        if ($this->editingId) {
            // Ownership re-verified at save time, not just at editEntry()
            // time — editingId is client state too.
            $entry = LessonJournalEntry::where('id', $this->editingId)
                ->where('teacher_id', $teacher->id)
                ->where('class_id', $this->classId)
                ->where('subject_id', $this->subjectId)
                ->first();

            if (! $entry) {
                Notification::make()
                    ->title(__('teacher_portal.not_assigned_to_class_subject'))
                    ->danger()
                    ->send();

                return;
            }

            $entry->update([
                'date' => $this->date,
                'title' => $this->lessonTitle,
                'notes' => $this->notes,
                'homework' => $this->homework,
            ]);
        } else {
            LessonJournalEntry::create([
                'teacher_id' => $teacher->id,
                'class_id' => $this->classId,
                'subject_id' => $this->subjectId,
                'academic_year_id' => $activeYearId,
                'date' => $this->date,
                'title' => $this->lessonTitle,
                'notes' => $this->notes,
                'homework' => $this->homework,
            ]);
        }

        $this->startCreate();
        $this->loadEntries();

        Notification::make()
            ->title(__('teacher_journal.saved_success'))
            ->success()
            ->send();
    }
}
