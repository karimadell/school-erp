<?php

namespace App\Filament\Resources\Curricula\Pages;

use App\Filament\Resources\Curricula\CurriculumResource;
use App\Models\AcademicYear;
use App\Models\Curriculum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\DB;

class ListCurricula extends ListRecords
{
    protected static string $resource = CurriculumResource::class;

    /**
     * Item 8 / C1, Phase 2: copy-forward between academic years. Approved
     * decisions — target is always the currently active year (not a form
     * field, never user-selectable); source is unconstrained; whole-year
     * only (no row selection); reuses the existing 'manage curriculum'
     * permission (no new one); skip-if-exists, never overwrite; no audit
     * logging for this phase.
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),

            Action::make('copyFromPreviousYear')
                ->label(__('curriculum.copy_action'))
                ->visible(fn () => auth()->user()?->can('manage curriculum')
                    && AcademicYear::where('is_active', true)->exists())
                ->form([
                    Placeholder::make('target_academic_year')
                        ->label(__('curriculum.copy_target_year'))
                        ->content(fn () => AcademicYear::where('is_active', true)->value('name')),

                    Select::make('source_academic_year_id')
                        ->label(__('curriculum.copy_source_year'))
                        ->options(fn () => AcademicYear::where('is_active', false)->pluck('name', 'id'))
                        ->searchable()
                        ->required(),
                ])
                ->action(function (array $data) {
                    $this->copyFromPreviousYear((int) $data['source_academic_year_id']);
                }),
        ];
    }

    protected function copyFromPreviousYear(int $sourceAcademicYearId): void
    {
        $activeYear = AcademicYear::where('is_active', true)->first();

        if (! $activeYear) {
            Notification::make()->title(__('curriculum.copy_no_active_year'))->danger()->send();

            return;
        }

        // Defense in depth — the source Select already excludes the active
        // year from its options, but never trust client-side restrictions
        // alone (same discipline already applied to teacher-portal
        // tampering checks elsewhere in this codebase).
        if ($sourceAcademicYearId === $activeYear->id) {
            Notification::make()->title(__('curriculum.copy_source_equals_target'))->danger()->send();

            return;
        }

        $sourceCurricula = Curriculum::where('academic_year_id', $sourceAcademicYearId)->get();

        if ($sourceCurricula->isEmpty()) {
            Notification::make()->title(__('curriculum.copy_source_empty'))->danger()->send();

            return;
        }

        $created = 0;
        $skipped = 0;

        DB::transaction(function () use ($sourceCurricula, $activeYear, &$created, &$skipped) {
            foreach ($sourceCurricula as $source) {
                $copy = Curriculum::firstOrCreate(
                    [
                        'academic_year_id' => $activeYear->id,
                        'grade_id' => $source->grade_id,
                        'subject_id' => $source->subject_id,
                    ],
                    [
                        'weekly_hours' => $source->weekly_hours,
                        'type' => $source->type,
                    ]
                );

                $copy->wasRecentlyCreated ? $created++ : $skipped++;
            }
        });

        Notification::make()
            ->title(__('curriculum.copy_success', ['created' => $created, 'skipped' => $skipped]))
            ->success()
            ->send();
    }
}
