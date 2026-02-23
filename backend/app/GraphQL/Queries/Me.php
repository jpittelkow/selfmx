<?php

namespace App\GraphQL\Queries;

use Illuminate\Support\Facades\Auth;

class Me
{
    public function __invoke($root, array $args, $context)
    {
        return Auth::guard('api-key')->user();
    }
}
