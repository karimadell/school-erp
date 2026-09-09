<?php

namespace App\Filament\Resources\Expenses\Schemas;

use App\Models\CashTransaction;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ExpenseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('expenses.section_details'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('title')
                            ->label(__('expenses.title'))
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Select::make('expense_category_id')
                            ->label(__('expenses.category'))
                            ->relationship('expenseCategory', 'name', fn ($query) => $query->where('is_active', true))
                            ->searchable()
                            ->preload()
                            ->createOptionForm([
                                TextInput::make('name')
                                    ->label(__('expenses.category_name'))
                                    ->required()
                                    ->unique(ExpenseCategory::class, 'name'),
                            ]),
                        Select::make('payee_id')
                            ->label(__('expenses.payee'))
                            ->relationship('payee', 'name', fn ($query) => $query->where('is_active', true))
                            ->searchable()
                            ->preload()
                            ->createOptionForm([
                                TextInput::make('name')
                                    ->label(__('expenses.payee_name'))
                                    ->required(),
                                TextInput::make('phone')
                                    ->label(__('expenses.payee_phone')),
                            ]),
                        TextInput::make('amount')
                            ->label(__('expenses.amount'))
                            ->required()
                            ->numeric()
                            ->minValue(0.01),
                        TextInput::make('currency')
                            ->label(__('expenses.currency'))
                            ->required()
                            ->maxLength(3)
                            ->default('EGP'),
                        DatePicker::make('expense_date')
                            ->label(__('expenses.expense_date'))
                            ->required()
                            ->default(now()),
                        Select::make('cash_account_id')
                            ->label(__('expenses.cash_account'))
                            ->relationship('cashAccount', 'name', fn ($query) => $query->where('is_active', true))
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('payment_method')
                            ->label(__('expenses.payment_method'))
                            ->options([
                                CashTransaction::METHOD_CASH => __('expenses.method_cash'),
                                CashTransaction::METHOD_CARD => __('expenses.method_card'),
                                CashTransaction::METHOD_BANK => __('expenses.method_bank'),
                                CashTransaction::METHOD_TRANSFER => __('expenses.method_transfer'),
                            ]),
                        TextInput::make('external_reference')
                            ->label(__('expenses.external_reference'))
                            ->maxLength(255),
                        Select::make('status')
                            ->label(__('expenses.status'))
                            ->options([
                                Expense::STATUS_DRAFT => __('expenses.status_draft'),
                                Expense::STATUS_PAID => __('expenses.status_paid'),
                            ])
                            ->default(Expense::STATUS_PAID)
                            ->required()
                            ->helperText(__('expenses.status_help'))
                            ->visibleOn('create'),
                    ]),
                Section::make(__('expenses.section_notes'))
                    ->schema([
                        Textarea::make('description')
                            ->label(__('expenses.description'))
                            ->columnSpanFull(),
                        Textarea::make('notes')
                            ->label(__('expenses.notes'))
                            ->columnSpanFull(),
                        FileUpload::make('attachment_path')
                            ->label(__('expenses.attachment'))
                            ->disk(config('filesystems.uploads.private'))
                            ->directory('expenses')
                            ->visibility('private')
                            ->preserveFilenames()
                            ->downloadable()
                            ->openable()
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
