<?php

namespace App\Filament\Resources\BellSchedules\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PeriodsRelationManager extends RelationManager
{
    protected static string $relationship = 'periods';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('bell_schedule.periods.title');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('period_number')->label(__('bell_schedule.fields.period_number'))
                ->numeric()->integer()->minValue(1)->required(),
            TextInput::make('label')->label(__('bell_schedule.fields.label'))->maxLength(255),
            TimePicker::make('starts_at')->label(__('bell_schedule.fields.starts_at'))->seconds(false)->required(),
            TimePicker::make('ends_at')->label(__('bell_schedule.fields.ends_at'))->seconds(false)->required()->after('starts_at'),
            TextInput::make('break_after_minutes')->label(__('bell_schedule.fields.break_after_minutes'))
                ->numeric()->integer()->minValue(0)->default(0)->required(),
            Toggle::make('is_active')->label(__('bell_schedule.fields.is_active'))->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('period_number')->label(__('bell_schedule.fields.period_number'))->sortable(),
            TextColumn::make('label')->label(__('bell_schedule.fields.label')),
            TextColumn::make('starts_at')->label(__('bell_schedule.fields.starts_at'))->time('H:i'),
            TextColumn::make('ends_at')->label(__('bell_schedule.fields.ends_at'))->time('H:i'),
            TextColumn::make('break_after_minutes')->label(__('bell_schedule.fields.break_after_minutes')),
            IconColumn::make('is_active')->label(__('bell_schedule.fields.is_active'))->boolean(),
        ])->defaultSort('period_number')
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make()]);
    }
}
