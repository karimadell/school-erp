<?php

namespace App\Filament\Resources\TimetableVersions\Pages;

use App\Filament\Resources\TimetableVersions\TimetableVersionResource;
use App\Models\TimetableVersion;
use App\Services\TimetableVersionService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditTimetableVersion extends EditRecord
{
    protected static string $resource = TimetableVersionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('publish')
                ->label(__('timetable_version.actions.publish'))
                ->requiresConfirmation()
                ->visible(fn () => $this->getRecord()->status === TimetableVersion::STATUS_DRAFT
                    && (auth()->user()?->can('manage timetable') ?? false))
                ->action(function (TimetableVersionService $service): void {
                    $service->publish($this->getRecord(), auth()->user());
                    Notification::make()->title(__('timetable_version.messages.published'))->success()->send();
                    $this->redirect(TimetableVersionResource::getUrl('index'));
                }),
        ];
    }
}
