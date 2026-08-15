<?php

namespace App\Filament\Resources\TeacherSalaries;

use App\Filament\Resources\TeacherSalaries\Pages\CreateTeacherSalary;
use App\Filament\Resources\TeacherSalaries\Pages\EditTeacherSalary;
use App\Filament\Resources\TeacherSalaries\Pages\ListTeacherSalaries;

use App\Models\TeacherSalary;
use App\Models\Teacher;

use Filament\Resources\Resource;
use Filament\Schemas\Schema;

use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Tables;

use BackedEnum;
use UnitEnum;

class TeacherSalaryResource extends Resource
{
    protected static ?string $model = TeacherSalary::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('teacher_salary.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('teacher_salary.navigation');
    }

    public static function getModelLabel(): string
    {
        return __('teacher_salary.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('teacher_salary.models');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([

            Forms\Components\Select::make('teacher_id')
                ->relationship('teacher', 'last_name')
                ->getOptionLabelFromRecordUsing(fn (Teacher $record) => $record->full_name)
                ->label(__('teacher_salary.teacher'))
                ->searchable(['first_name', 'last_name'])
                ->preload()
                ->required(),

            Forms\Components\TextInput::make('base_salary')
                ->label(__('teacher_salary.base_salary'))
                ->numeric()
                ->required(),

            Forms\Components\TextInput::make('bonus')
                ->label(__('teacher_salary.bonus'))
                ->numeric()
                ->default(0),

            Forms\Components\TextInput::make('deductions')
                ->label(__('teacher_salary.deductions'))
                ->numeric()
                ->default(0),

            Forms\Components\DatePicker::make('salary_month')
                ->label(__('teacher_salary.month'))
                ->required(),

            Forms\Components\TextInput::make('net_salary')
                ->label(__('teacher_salary.net_salary'))
                ->numeric()
                ->disabled(),

        ]);
    }

    public static function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->columns([

                Tables\Columns\TextColumn::make('teacher.full_name')
                    ->label(__('teacher_salary.teacher'))
                    ->searchable(['teacher.first_name', 'teacher.last_name']),

                Tables\Columns\TextColumn::make('base_salary')
                    ->label(__('teacher_salary.base_salary'))
                    ->money('EGP')
                    ->alignEnd()
                    ->visibleFrom('lg'),

                Tables\Columns\TextColumn::make('bonus')
                    ->label(__('teacher_salary.bonus'))
                    ->money('EGP')
                    ->alignEnd()
                    ->visibleFrom('lg'),

                Tables\Columns\TextColumn::make('deductions')
                    ->label(__('teacher_salary.deductions'))
                    ->money('EGP')
                    ->alignEnd()
                    ->visibleFrom('lg'),

                Tables\Columns\TextColumn::make('net_salary')
                    ->label(__('teacher_salary.net_salary'))
                    ->money('EGP')
                    ->alignEnd()
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('salary_month')
                    ->label(__('teacher_salary.month'))
                    ->date(),

            ])
            ->filters([
                Tables\Filters\SelectFilter::make('teacher_id')
                    ->label(__('teacher_salary.filters.teacher'))
                    ->relationship('teacher', 'last_name')
                    ->getOptionLabelFromRecordUsing(fn (Teacher $record) => $record->full_name)
                    ->searchable()
                    ->preload(),
            ])
            ->emptyStateHeading(__('teacher_salary.empty_heading'))
            ->emptyStateDescription(__('teacher_salary.empty_description'))
            ->actions([
                EditAction::make()
                    ->iconButton()
                    ->tooltip(__('filament-actions::edit.single.label')),
            ]);
    }

    public static function getPages(): array
    {
        return [

            'index' => ListTeacherSalaries::route('/'),

            'create' => CreateTeacherSalary::route('/create'),

            'edit' => EditTeacherSalary::route('/{record}/edit'),

        ];
    }
}
