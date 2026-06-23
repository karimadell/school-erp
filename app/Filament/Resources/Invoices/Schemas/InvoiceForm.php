<?php

namespace App\Filament\Resources\Invoices\Schemas;

use Filament\Forms;
use Filament\Schemas\Schema;
use App\Models\Fee;
use App\Models\SchoolClass;

class InvoiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            /*
            |--------------------------------------------------------------------------
            | Student
            |--------------------------------------------------------------------------
            */

            Forms\Components\Select::make('student_id')
                ->label('Студент')
                ->relationship('student', 'full_name')
                ->searchable()
                ->preload()

                ->createOptionForm([

                    Forms\Components\TextInput::make('full_name')
                        ->label('Имя студента')
                        ->required(),

                    Forms\Components\Select::make('class_id')
                        ->label('Класс')
                        ->options(
                            SchoolClass::query()
                                ->pluck('name_ru','id')
                        )
                        ->searchable()
                        ->required(),

                ])
                ->required(),

            /*
            |--------------------------------------------------------------------------
            | Customer
            |--------------------------------------------------------------------------
            */

            Forms\Components\TextInput::make('customer_name')
                ->label('Плательщик')
                ->maxLength(255),

            /*
            |--------------------------------------------------------------------------
            | Payment Method
            |--------------------------------------------------------------------------
            */

            Forms\Components\Select::make('payment_method')
                ->label('Способ оплаты')
                ->options([
                    'cash' => 'Наличные',
                    'bank' => 'Банковский перевод',
                ])
                ->required(),

            /*
            |--------------------------------------------------------------------------
            | Cash Account
            |--------------------------------------------------------------------------
            */

            Forms\Components\Select::make('cash_account_id')
                ->label('Касса')
                ->relationship('cashAccount','name')
                ->searchable()
                ->required(),

            /*
            |--------------------------------------------------------------------------
            | Services
            |--------------------------------------------------------------------------
            */

            Forms\Components\Repeater::make('fees')
                ->label('Услуги')
                ->relationship()
                ->schema([

                    Forms\Components\Select::make('fee_id')
                        ->label('Услуга')
                        ->options(
                            Fee::query()
                                ->where('is_active', true)
                                ->pluck('name_ru','id')
                        )
                        ->searchable()
                        ->required(),

                    Forms\Components\TextInput::make('amount')
                        ->label('Сумма')
                        ->numeric()
                        ->required(),

                ])
                ->columns(2)
                ->addActionLabel('Добавить услугу'),

            /*
            |--------------------------------------------------------------------------
            | Discount
            |--------------------------------------------------------------------------
            */

            Forms\Components\TextInput::make('discount')
                ->label('Скидка')
                ->numeric()
                ->default(0),

            /*
            |--------------------------------------------------------------------------
            | Total
            |--------------------------------------------------------------------------
            */

            Forms\Components\TextInput::make('total_amount')
                ->label('Итого')
                ->numeric()
                ->disabled(),

            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            */

            Forms\Components\Select::make('status')
                ->label('Статус')
                ->options([
                    'unpaid' => 'Не оплачен',
                    'paid' => 'Оплачен',
                ])
                ->default('unpaid'),

        ]);
    }
}