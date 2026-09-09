<?php

namespace App\Filament\Resources\Expenses\Tables;

use App\Models\CashTransaction;
use App\Models\Expense;
use App\Services\Finance\ExpenseService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class ExpensesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference_number')
                    ->label(__('expenses.reference_number'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('title')
                    ->label(__('expenses.title'))
                    ->searchable(),
                TextColumn::make('expenseCategory.name')
                    ->label(__('expenses.category'))
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('payee.name')
                    ->label(__('expenses.payee'))
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('amount')
                    ->label(__('expenses.amount'))
                    ->numeric(2)
                    ->sortable()
                    ->summarize(Sum::make()->label(__('expenses.total'))->numeric(2)),
                TextColumn::make('expense_date')
                    ->label(__('expenses.expense_date'))
                    ->date()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('expenses.status'))
                    ->badge()
                    ->size(TextSize::Small)
                    ->formatStateUsing(fn (string $state) => __('expenses.status_'.$state))
                    ->color(fn (string $state) => match ($state) {
                        Expense::STATUS_DRAFT => 'gray',
                        Expense::STATUS_APPROVED => 'warning',
                        Expense::STATUS_PAID => 'success',
                        Expense::STATUS_VOID => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('cashAccount.name')
                    ->label(__('expenses.cash_account'))
                    ->toggleable(),
                TextColumn::make('payment_method')
                    ->label(__('expenses.payment_method'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('creator.name')
                    ->label(__('expenses.created_by'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('expenses.status'))
                    ->options([
                        Expense::STATUS_DRAFT => __('expenses.status_draft'),
                        Expense::STATUS_APPROVED => __('expenses.status_approved'),
                        Expense::STATUS_PAID => __('expenses.status_paid'),
                        Expense::STATUS_VOID => __('expenses.status_void'),
                    ]),
                SelectFilter::make('expense_category_id')
                    ->label(__('expenses.category'))
                    ->relationship('expenseCategory', 'name'),
                SelectFilter::make('payee_id')
                    ->label(__('expenses.payee'))
                    ->relationship('payee', 'name'),
                SelectFilter::make('cash_account_id')
                    ->label(__('expenses.cash_account'))
                    ->relationship('cashAccount', 'name'),
                SelectFilter::make('payment_method')
                    ->label(__('expenses.payment_method'))
                    ->options([
                        CashTransaction::METHOD_CASH => __('expenses.method_cash'),
                        CashTransaction::METHOD_CARD => __('expenses.method_card'),
                        CashTransaction::METHOD_BANK => __('expenses.method_bank'),
                        CashTransaction::METHOD_TRANSFER => __('expenses.method_transfer'),
                    ]),
                Filter::make('expense_date')
                    ->schema([
                        DatePicker::make('from')->label(__('expenses.date_from')),
                        DatePicker::make('until')->label(__('expenses.date_until')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('expense_date', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('expense_date', '<=', $date));
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('approve')
                    ->label(__('expenses.action_approve'))
                    ->icon('heroicon-o-check-circle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (Expense $record) => auth()->user()?->can('approve', $record) ?? false)
                    ->action(function (Expense $record) {
                        app(ExpenseService::class)->approve($record, auth()->user());
                        Notification::make()->title(__('expenses.approved_notification'))->success()->send();
                    }),
                Action::make('pay')
                    ->label(__('expenses.action_pay'))
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Expense $record) => auth()->user()?->can('pay', $record) ?? false)
                    ->action(function (Expense $record) {
                        app(ExpenseService::class)->pay($record, auth()->user());
                        Notification::make()->title(__('expenses.paid_notification'))->success()->send();
                    }),
                Action::make('void')
                    ->label(__('expenses.action_void'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->schema([
                        Textarea::make('void_reason')
                            ->label(__('expenses.void_reason'))
                            ->required(),
                    ])
                    ->visible(fn (Expense $record) => auth()->user()?->can('void', $record) ?? false)
                    ->action(function (Expense $record, array $data) {
                        try {
                            app(ExpenseService::class)->void($record, auth()->user(), $data['void_reason']);
                            Notification::make()->title(__('expenses.voided_notification'))->success()->send();
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->title(collect($exception->errors())->flatten()->first())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->toolbarActions([]);
    }
}
