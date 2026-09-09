<?php

namespace App\Filament\Resources\Payees\Pages;

use App\Filament\Resources\Payees\PayeeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPayee extends EditRecord
{
    protected static string $resource = PayeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
