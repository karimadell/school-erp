<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Expense;
use Filament\Resources\Pages\EditRecord;

// No DeleteAction: financially significant Expense records are never
// hard-deleted (see Expense::booted()'s deleting guard and
// ExpensePolicy::delete(), both of which also block it — this omission is
// belt-and-suspenders, not the only safeguard).
class EditExpense extends EditRecord
{
    protected static string $resource = ExpenseResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return array_merge($data, Expense::attachmentMetadataFrom($data['attachment_path'] ?? null));
    }
}
