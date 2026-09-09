<?php

namespace App\Filament\Resources\Payees;

use App\Filament\Resources\Payees\Pages\CreatePayee;
use App\Filament\Resources\Payees\Pages\EditPayee;
use App\Filament\Resources\Payees\Pages\ListPayees;
use App\Filament\Resources\Payees\Schemas\PayeeForm;
use App\Filament\Resources\Payees\Tables\PayeesTable;
use App\Models\Payee;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

// Admin-manageable payees/recipients. Authorization is delegated entirely
// to PayeePolicy (registered in AuthServiceProvider), gated on
// 'manage expenses' — the same permission the Expense resource itself uses.
class PayeeResource extends Resource
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $model = Payee::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static \UnitEnum|string|null $navigationGroup = 'Финансы';

    protected static ?string $navigationLabel = 'Контрагенты';

    protected static ?int $navigationSort = 42;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return PayeeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PayeesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayees::route('/'),
            'create' => CreatePayee::route('/create'),
            'edit' => EditPayee::route('/{record}/edit'),
        ];
    }
}
