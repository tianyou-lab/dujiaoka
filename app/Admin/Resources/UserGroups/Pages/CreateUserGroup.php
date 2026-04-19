<?php

namespace App\Admin\Resources\UserGroups\Pages;

use App\Admin\Resources\UserGroups;
use Filament\Resources\Pages\CreateRecord;

class CreateUserGroup extends CreateRecord
{
    protected static string $resource = UserGroups::class;
}
