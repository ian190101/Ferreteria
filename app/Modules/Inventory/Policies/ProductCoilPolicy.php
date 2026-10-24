<?php

namespace App\Modules\Inventory\Policies;

use App\Models\User;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Inventory\Services\LotAuthorization;

class ProductCoilPolicy
{
    public function __construct(private readonly LotAuthorization $authorization) {}

    public function updateLot(User $user, ProductCoil $lot): bool
    {
        return $this->authorization->canManage($user, $lot);
    }

    public function voidLot(User $user, ProductCoil $lot): bool
    {
        return $this->authorization->canManage($user, $lot);
    }
}
