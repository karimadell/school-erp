<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Expense;
use App\Services\Finance\ExpenseService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateExpense extends CreateRecord
{
    protected static string $resource = ExpenseResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return array_merge($data, Expense::attachmentMetadataFrom($data['attachment_path'] ?? null));
    }

    // Routes creation through ExpenseService::create() rather than the
    // stock handleRecordCreation() (a plain, unwrapped $record->save()).
    // This panel does not enable page-level databaseTransactions(), so
    // without this override a default-paid expense's insert and its
    // ledger posting would not be atomic.
    protected function handleRecordCreation(array $data): Model
    {
        return app(ExpenseService::class)->create($data, auth()->user());
    }
}
