<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ClassResource\Pages;
use App\Filament\Resources\ClassResource\RelationManagers\ClassTeachersRelationManager;
use App\Models\SchoolClass;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ClassResource extends Resource
{
    protected static ?string $model = SchoolClass::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-building-office';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return __('classes.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('classes.title');
    }

    public static function getModelLabel(): string
    {
        return __('classes.name');
    }

    public static function getPluralModelLabel(): string
    {
        return __('classes.title');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([

            Forms\Components\TextInput::make('code')
                ->label(__('classes.code')),

            Forms\Components\TextInput::make('name_ru')
                ->label(__('classes.name_ru'))
                ->required(),

            Forms\Components\TextInput::make('capacity')
                ->label(__('classes.capacity'))
                ->numeric(),

            Forms\Components\Toggle::make('is_active')
                ->label(__('classes.status'))
                ->default(true),

        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->extraAttributes(['class' => 'erp-resource-table erp-classes-table'])
            ->searchPlaceholder(__('classes.search_placeholder'))
            ->columns([

                Tables\Columns\TextColumn::make('name_ru')
                    ->label(__('classes.name'))
                    ->description(fn (SchoolClass $record): ?string => $record->code ?: null)
                    ->weight('medium')
                    ->searchable(['name_ru', 'code'])
                    ->sortable(),

                Tables\Columns\TextColumn::make('grade.stage.name')
                    ->label(__('classes.stage'))
                    ->badge()
                    ->color('gray')
                    ->visibleFrom('md')
                    ->sortable(),

                Tables\Columns\TextColumn::make('grade.name')
                    ->label(__('classes.grade'))
                    ->badge()
                    ->color('primary')
                    ->visibleFrom('sm')
                    ->sortable(),

                Tables\Columns\TextColumn::make('capacity')
                    ->label(__('classes.capacity'))
                    ->alignEnd()
                    ->visibleFrom('sm')
                    ->sortable(),

                Tables\Columns\TextColumn::make('is_active')
                    ->label(__('classes.status'))
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? __('classes.active') : __('classes.inactive'))
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),

            ])
            ->filters([
                SelectFilter::make('grade_id')
                    ->label(__('classes.grade'))
                    ->relationship('grade', 'name'),

                TernaryFilter::make('is_active')
                    ->label(__('classes.status'))
                    ->trueLabel(__('classes.active'))
                    ->falseLabel(__('classes.inactive')),
            ])
            ->filtersFormColumns(1)
            ->emptyStateHeading(__('classes.empty_heading'))
            ->emptyStateDescription(__('classes.empty_description'))
            ->recordActions([

                EditAction::make()
                    ->iconButton()
                    ->tooltip(__('filament-actions::edit.single.label')),

                // Batch 5 / Timetable Navigation (docs/TIMETABLE_ARCHITECTURE_DECISIONS.md):
                // the only discoverable link to the canonical TimetableGrid
                // page — it is a per-class sub-page and can never have its
                // own top-level navigation item. Visibility mirrors
                // TimetableGrid::canAccess()/mount() exactly, so this link
                // is never shown to a user who would immediately be
                // aborted for clicking it.
                Action::make('timetable')
                    ->label(__('timetable.view_schedule'))
                    ->icon('heroicon-o-calendar')
                    ->iconButton()
                    ->tooltip(__('timetable.view_schedule'))
                    ->url(fn ($record) => static::getUrl('timetable', ['record' => $record]))
                    ->visible(fn () => auth()->user()?->hasAnyPermission(['view timetable', 'manage timetable']) ?? false),

            ]);
    }

    public static function getRelations(): array
    {
        return [
            ClassTeachersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [

            'index' => Pages\ListClasses::route('/'),

            'create' => Pages\CreateClass::route('/create'),

            'edit' => Pages\EditClass::route('/{record}/edit'),

            // صفحة الجدول الدراسي
            'timetable' => Pages\TimetableGrid::route('/{record}/timetable'),

        ];
    }
}
