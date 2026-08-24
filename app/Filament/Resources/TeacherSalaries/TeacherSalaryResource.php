<?php

namespace App\Filament\Resources\TeacherSalaries;

use App\Filament\Resources\TeacherSalaries\Pages\CreateTeacherSalary;
use App\Filament\Resources\TeacherSalaries\Pages\EditTeacherSalary;
use App\Filament\Resources\TeacherSalaries\Pages\ListTeacherSalaries;
use App\Models\CashAccount;
use App\Models\PayrollAdjustment;
use App\Models\TeacherSalary;
use App\Models\User;
use App\Services\Finance\EmployeePayrollService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
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
            Forms\Components\Select::make('employee_user_id')
                ->label(__('teacher_salary.employee'))
                ->options(fn () => User::query()->where('is_active', true)->whereHas('roles')->orderBy('name')->pluck('name', 'id'))
                ->searchable()->preload()->required()->disabledOn('edit'),
            Forms\Components\TextInput::make('position')->label(__('teacher_salary.position'))->maxLength(255),
            Forms\Components\DatePicker::make('salary_month')->label(__('teacher_salary.month'))->required()->native(false)->disabledOn('edit'),
            Forms\Components\TextInput::make('base_salary')->label(__('teacher_salary.base_salary'))->numeric()->minValue(0)->required()
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Get $get, Set $set) => self::updateNetSalaryPreview($get, $set)),
            Forms\Components\Repeater::make('adjustments')
                ->label(__('teacher_salary.adjustments'))
                ->live()
                ->afterStateUpdated(fn (Get $get, Set $set) => self::updateNetSalaryPreview($get, $set))
                ->schema([
                    Forms\Components\Select::make('type')->label(__('teacher_salary.adjustment_type'))->options([
                        PayrollAdjustment::TYPE_BONUS => __('teacher_salary.bonus'),
                        PayrollAdjustment::TYPE_ALLOWANCE => __('teacher_salary.allowance'),
                        PayrollAdjustment::TYPE_DEDUCTION => __('teacher_salary.deduction'),
                    ])->required(),
                    Forms\Components\TextInput::make('amount')->label(__('teacher_salary.amount'))->numeric()->minValue(0.01)->required(),
                    Forms\Components\TextInput::make('reason')->label(__('teacher_salary.reason'))->required()->maxLength(255),
                ])->columns(3)->defaultItems(0)->addActionLabel(__('teacher_salary.add_adjustment')),
            Forms\Components\TextInput::make('net_salary')->label(__('teacher_salary.net_salary'))->disabled()->dehydrated(false),
        ]);
    }

    /**
     * UI-only preview: recomputes the same base + bonuses + allowances -
     * deductions formula as TeacherSalary::calculateNet() so the "Net
     * payable" field reflects unsaved edits immediately. Purely cosmetic —
     * net_salary is dehydrated(false), so this never reaches the request;
     * EmployeePayrollService/TeacherSalary::calculateNet() remain the sole
     * source of truth for the persisted amount.
     */
    protected static function updateNetSalaryPreview(Get $get, Set $set): void
    {
        $totals = [
            PayrollAdjustment::TYPE_BONUS => '0.00',
            PayrollAdjustment::TYPE_ALLOWANCE => '0.00',
            PayrollAdjustment::TYPE_DEDUCTION => '0.00',
        ];

        foreach ((array) ($get('adjustments') ?? []) as $line) {
            $type = $line['type'] ?? null;

            if (! array_key_exists($type, $totals)) {
                continue;
            }

            $totals[$type] = bcadd($totals[$type], self::normalizeAmount($line['amount'] ?? null), 2);
        }

        $net = bcsub(
            bcadd(bcadd(self::normalizeAmount($get('base_salary')), $totals[PayrollAdjustment::TYPE_BONUS], 2), $totals[PayrollAdjustment::TYPE_ALLOWANCE], 2),
            $totals[PayrollAdjustment::TYPE_DEDUCTION],
            2,
        );

        $set('net_salary', $net);
    }

    private static function normalizeAmount(mixed $value): string
    {
        $value = (string) ($value ?? '0');

        return preg_match('/^\d+(?:\.\d+)?$/', $value) ? bcadd($value, '0', 2) : '0.00';
    }

    public static function table(Tables\Table $table): Tables\Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['employee.roles', 'teacher', 'adjustments']))
            ->columns([
                Tables\Columns\TextColumn::make('employee_display_name')->label(__('teacher_salary.employee'))->searchable(['employee_name'])->sortable(),
                Tables\Columns\TextColumn::make('position')->label(__('teacher_salary.position'))->toggleable(),
                Tables\Columns\TextColumn::make('base_salary')->label(__('teacher_salary.base_salary'))->money('EGP')->alignEnd(),
                Tables\Columns\TextColumn::make('bonus')->label(__('teacher_salary.bonuses'))->money('EGP')->alignEnd(),
                Tables\Columns\TextColumn::make('allowances')->label(__('teacher_salary.allowances'))->money('EGP')->alignEnd(),
                Tables\Columns\TextColumn::make('deductions')->label(__('teacher_salary.deductions'))->money('EGP')->alignEnd(),
                Tables\Columns\TextColumn::make('net_salary')->label(__('teacher_salary.net_salary'))->money('EGP')->weight('semibold')->alignEnd(),
                Tables\Columns\TextColumn::make('salary_month')->label(__('teacher_salary.month'))->date('m.Y')->sortable(),
                Tables\Columns\TextColumn::make('status')->label(__('teacher_salary.status'))->badge()->formatStateUsing(fn (string $state) => __('teacher_salary.statuses.'.$state)),
            ])->filters([
                Tables\Filters\SelectFilter::make('salary_month')->label(__('teacher_salary.filters.month'))
                    ->options(fn () => TeacherSalary::query()->select('salary_month')->distinct()->orderByDesc('salary_month')->pluck('salary_month', 'salary_month')->mapWithKeys(fn ($month) => [$month => \Carbon\Carbon::parse($month)->format('m.Y')])),
                Tables\Filters\SelectFilter::make('employee_user_id')->label(__('teacher_salary.filters.employee'))->relationship('employee', 'name')->searchable()->preload(),
                Tables\Filters\SelectFilter::make('position')->label(__('teacher_salary.filters.position'))
                    ->options(fn () => TeacherSalary::query()->whereNotNull('position')->distinct()->orderBy('position')->pluck('position', 'position')),
                Tables\Filters\SelectFilter::make('status')->label(__('teacher_salary.filters.status'))->options([
                    TeacherSalary::STATUS_DRAFT => __('teacher_salary.statuses.draft'),
                    TeacherSalary::STATUS_APPROVED => __('teacher_salary.statuses.approved'),
                    TeacherSalary::STATUS_PAID => __('teacher_salary.statuses.paid'),
                ]),
            ])->emptyStateHeading(__('teacher_salary.empty_heading'))->emptyStateDescription(__('teacher_salary.empty_description'))
            ->actions([
                Action::make('approve')->label(__('teacher_salary.approve'))->icon('heroicon-o-check-circle')
                    ->visible(fn (TeacherSalary $record) => $record->status === TeacherSalary::STATUS_DRAFT && auth()->user()?->can('approve payroll'))
                    ->requiresConfirmation()->action(function (TeacherSalary $record): void {
                        abort_unless(auth()->user()?->can('approve payroll'), 403);
                        app(EmployeePayrollService::class)->approve($record, auth()->user());
                        Notification::make()->title(__('teacher_salary.approved'))->success()->send();
                    }),
                Action::make('pay')->label(__('teacher_salary.pay'))->icon('heroicon-o-banknotes')
                    ->visible(fn (TeacherSalary $record) => $record->status === TeacherSalary::STATUS_APPROVED && auth()->user()?->can('pay payroll'))
                    ->form([
                        Forms\Components\Select::make('cash_account_id')->label(__('teacher_salary.cash_account'))->options(fn () => CashAccount::where('is_active', true)->pluck('name', 'id'))->required(),
                        Forms\Components\Select::make('payment_method')->label(__('teacher_salary.payment_method'))->options([
                            'cash' => __('teacher_salary.methods.cash'), 'card' => __('teacher_salary.methods.card'),
                            'bank' => __('teacher_salary.methods.bank'), 'transfer' => __('teacher_salary.methods.transfer'),
                        ])->required(),
                    ])->action(function (TeacherSalary $record, array $data): void {
                        abort_unless(auth()->user()?->can('pay payroll'), 403);
                        app(EmployeePayrollService::class)->pay($record, CashAccount::findOrFail($data['cash_account_id']), $data['payment_method'], auth()->user());
                        Notification::make()->title(__('teacher_salary.paid'))->success()->send();
                    }),
                Action::make('print')->label(__('teacher_salary.print'))->icon('heroicon-o-printer')->url(fn (TeacherSalary $record) => route('dashboard.teacher-salaries.print', $record))->openUrlInNewTab(),
                Action::make('pdf')->label(__('teacher_salary.pdf'))->icon('heroicon-o-document-arrow-down')->url(fn (TeacherSalary $record) => route('dashboard.teacher-salaries.pdf', $record)),
                EditAction::make()->visible(fn (TeacherSalary $record) => $record->status === TeacherSalary::STATUS_DRAFT && auth()->user()?->can('manage payroll')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListTeacherSalaries::route('/'), 'create' => CreateTeacherSalary::route('/create'), 'edit' => EditTeacherSalary::route('/{record}/edit')];
    }
}
