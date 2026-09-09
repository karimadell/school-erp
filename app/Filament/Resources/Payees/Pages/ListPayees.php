<?php

namespace App\Filament\Resources\Payees\Pages;

use App\Filament\Resources\Payees\PayeeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPayees extends ListRecords
{
    protected static string $resource = PayeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
