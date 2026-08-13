<?php

namespace App\Filament\Resources\AcademicCalendars\RelationManagers;

use App\Models\CalendarEvent;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('academic_calendar.events.title');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('academic_calendar.fields.name'))->required()->maxLength(255),
            Select::make('type')->label(__('academic_calendar.fields.type'))->options([
                CalendarEvent::TYPE_OFFICIAL_HOLIDAY => __('academic_calendar.types.official_holiday'),
                CalendarEvent::TYPE_SCHOOL_HOLIDAY => __('academic_calendar.types.school_holiday'),
                CalendarEvent::TYPE_SCHOOL_EVENT => __('academic_calendar.types.school_event'),
                CalendarEvent::TYPE_TEACHING_OVERRIDE => __('academic_calendar.types.teaching_override'),
                CalendarEvent::TYPE_BELL_SCHEDULE_OVERRIDE => __('academic_calendar.types.bell_schedule_override'),
            ])->required()->live(),
            DatePicker::make('start_date')->label(__('academic_calendar.fields.start_date'))->required(),
            DatePicker::make('end_date')->label(__('academic_calendar.fields.end_date'))->required()->afterOrEqual('start_date'),
            Select::make('effect')->label(__('academic_calendar.fields.effect'))->options([
                CalendarEvent::EFFECT_NON_TEACHING => __('academic_calendar.effects.non_teaching'),
                CalendarEvent::EFFECT_TEACHING_DAY => __('academic_calendar.effects.teaching_day'),
                CalendarEvent::EFFECT_SHORTENED => __('academic_calendar.effects.shortened'),
            ])->required(fn (Get $get) => $get('type') === CalendarEvent::TYPE_TEACHING_OVERRIDE)
                ->visible(fn (Get $get) => in_array($get('type'), [CalendarEvent::TYPE_TEACHING_OVERRIDE, CalendarEvent::TYPE_SCHOOL_EVENT], true)),
            Select::make('bell_schedule_id')->label(__('academic_calendar.fields.bell_schedule_id'))
                ->relationship('bellSchedule', 'name', modifyQueryUsing: fn ($query) => $query
                    ->where('academic_year_id', $this->getOwnerRecord()->academic_year_id)
                    ->where('is_active', true))
                ->searchable()
                ->preload()
                ->required(fn (Get $get) => $get('type') === CalendarEvent::TYPE_BELL_SCHEDULE_OVERRIDE)
                ->visible(fn (Get $get) => in_array($get('type'), [CalendarEvent::TYPE_BELL_SCHEDULE_OVERRIDE, CalendarEvent::TYPE_TEACHING_OVERRIDE], true)),
            TextInput::make('shift')->label(__('academic_calendar.fields.shift'))->numeric()->minValue(1)->nullable(),
            Textarea::make('notes')->label(__('academic_calendar.fields.notes'))->columnSpanFull(),
            Toggle::make('is_active')->label(__('academic_calendar.fields.is_active'))->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->label(__('academic_calendar.fields.name'))->searchable(),
                TextColumn::make('type')->label(__('academic_calendar.fields.type'))->badge()
                    ->formatStateUsing(fn ($state) => __("academic_calendar.types.{$state}")),
                TextColumn::make('start_date')->label(__('academic_calendar.fields.start_date'))->date()->sortable(),
                TextColumn::make('end_date')->label(__('academic_calendar.fields.end_date'))->date()->sortable(),
                IconColumn::make('is_active')->label(__('academic_calendar.fields.is_active'))->boolean(),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make()]);
    }
}
