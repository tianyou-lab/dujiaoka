<?php

namespace App\Admin\Resources\VmqQrcodes\Pages;

use App\Admin\Resources\VmqQrcodes;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditVmqQrcode extends EditRecord
{
    protected static string $resource = VmqQrcodes::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
