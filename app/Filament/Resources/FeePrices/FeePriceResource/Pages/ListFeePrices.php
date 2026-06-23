<?php

namespace App\Filament\Resources\FeePrices\FeePriceResource\Pages;

use App\Filament\Resources\FeePrices\FeePriceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFeePrices extends ListRecords
{
    protected static string $resource = FeePriceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}