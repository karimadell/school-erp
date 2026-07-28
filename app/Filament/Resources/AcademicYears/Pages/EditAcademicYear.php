<?php

namespace App\Filament\Resources\AcademicYears\Pages;

use App\Filament\Resources\AcademicYears\AcademicYearResource;
use App\Models\AcademicYearUnlock;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditAcademicYear extends EditRecord
{
    protected static string $resource = AcademicYearResource::class;

    /**
     * Item 2: temporary, whole-year unlock — approved policy: dedicated
     * permission (checked via the 'unlock' Policy ability, not
     * 'manage academic years'), required reason, required future expiry,
     * acting user recorded, no permanent option. Only offered for a
     * non-active year — the active year is never locked in the first
     * place.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('unlock')
                ->label(__('academic_years.unlock'))
                ->visible(fn () => ! $this->getRecord()->is_active && auth()->user()?->can('unlock', $this->getRecord()))
                ->form([
                    Textarea::make('reason')
                        ->label(__('academic_years.unlock_reason'))
                        ->required(),
                    DateTimePicker::make('expires_at')
                        ->label(__('academic_years.unlock_expires_at'))
                        ->required()
                        ->after(now())
                        ->native(false),
                ])
                ->action(function (array $data) {
                    AcademicYearUnlock::create([
                        'academic_year_id' => $this->getRecord()->id,
                        'reason' => $data['reason'],
                        'unlocked_by' => auth()->id(),
                        'expires_at' => $data['expires_at'],
                    ]);

                    Notification::make()
                        ->title(__('academic_years.unlock_success'))
                        ->success()
                        ->send();
                }),
            DeleteAction::make(),
        ];
    }
}
