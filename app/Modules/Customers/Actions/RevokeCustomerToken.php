<?php

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Models\CustomerToken;

class RevokeCustomerToken
{
    public function __invoke(CustomerToken $token): void
    {
        $token->delete();
    }
}
