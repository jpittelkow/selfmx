<?php

namespace App\GraphQL\Mutations;

use App\Models\User;
use GraphQL\Error\Error;
use Illuminate\Support\Facades\Auth;

class UpdateProfile
{
    public function __invoke($root, array $args, $context): array
    {
        $user = Auth::guard('api-key')->user();
        $input = $args['input'];
        $emailChanged = false;

        if (isset($input['name'])) {
            $user->name = $input['name'];
        }

        if (isset($input['email']) && $input['email'] !== $user->email) {
            $exists = User::where('email', $input['email'])
                ->where('id', '!=', $user->id)
                ->exists();
            if ($exists) {
                throw new Error('The email has already been taken.',
                    extensions: ['code' => 'VALIDATION_ERROR', 'field' => 'email']);
            }
            $user->email = $input['email'];
            $user->email_verified_at = null;
            $emailChanged = true;
        }

        if (array_key_exists('avatar', $input)) {
            $avatar = $input['avatar'];
            if ($avatar !== null && $avatar !== '' && !filter_var($avatar, FILTER_VALIDATE_URL)) {
                throw new Error('The avatar must be a valid URL.',
                    extensions: ['code' => 'VALIDATION_ERROR', 'field' => 'avatar']);
            }
            $user->avatar = $avatar;
        }

        $user->save();

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }

        return [
            'user' => $user,
            'emailVerificationSent' => $emailChanged,
        ];
    }
}
