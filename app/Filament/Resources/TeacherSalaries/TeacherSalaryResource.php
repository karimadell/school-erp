<?php

namespace App\Filament\Resources\TeacherSalaries;

use App\Filament\Resources\TeacherSalaries\Pages\CreateTeacherSalary;
use App\Filament\Resources\TeacherSalaries\Pages\EditTeacherSalary;
use App\Filament\Resources\TeacherSalaries\Pages\ListTeacherSalaries;

use App\Models\TeacherSalary;

use Filament\Resources\Resource;
use Filament\Schemas\Schema;

use Filament\Forms;
use Filament\Tables;

use BackedEnum;
use UnitEnum;

class TeacherSalaryResource extends Resource
{
    protected static ?string $model = TeacherSalary::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|UnitEnum|null $navigationGroup = 'Payroll';

    protected static ?string $navigationLabel = 'Teacher Salaries';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([

            Forms\Components\Select::make('teacher_id')
                ->relationship('teacher', 'name')
                ->label('Teacher')
                ->searchable()
                ->required(),

            Forms\Components\TextInput::make('base_salary')
                ->label('Base Salary')
                ->numeric()
                ->required(),

            Forms\Components\TextInput::make('bonus')
                ->label('Bonus')
                ->numeric()
                ->default(0),

            Forms\Components\TextInput::make('deductions')
                ->label('Deductions')
                ->numeric()
                ->default(0),

            Forms\Components\DatePicker::make('salary_month')
                ->label('Salary Month')
                ->required(),

            Forms\Components\TextInput::make('net_salary')
                ->label('Net Salary')
                ->numeric()
                ->disabled(),

        ]);
    }

    public static function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->columns([

                Tables\Columns\TextColumn::make('teacher.name')
                    ->label('Teacher'),

                Tables\Columns\TextColumn::make('base_salary')
                    ->money('EGP'),

                Tables\Columns\TextColumn::make('bonus')
                    ->money('EGP'),

                Tables\Columns\TextColumn::make('deductions')
                    ->money('EGP'),

                Tables\Columns\TextColumn::make('net_salary')
                    ->money('EGP'),

                Tables\Columns\TextColumn::make('salary_month')
                    ->date(),

            ])
            ->actions([
                Tables\Actions\EditAction::make(),
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