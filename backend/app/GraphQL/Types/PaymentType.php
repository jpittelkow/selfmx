<?php

namespace App\GraphQL\Types;

use App\Enums\Permission;
use Illuminate\Support\Facades\Auth;

class PaymentType
{
    public function stripeCustomerId($payment): ?string
    {
        $user = Auth::guard('api-key')->user();

        if ($user && $user->hasPermission(Permission::PAYMENTS_MANAGE->value)) {
            return $payment->stripe_customer_id;
        }

        return null;
    }
}
