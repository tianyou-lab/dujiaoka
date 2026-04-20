<?php

namespace App\Admin\Resources\VmqQrcodes\Pages;

use App\Admin\Resources\VmqQrcodes;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListVmqQrcodes extends ListRecords
{
    protected static string $resource = VmqQrcodes::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
