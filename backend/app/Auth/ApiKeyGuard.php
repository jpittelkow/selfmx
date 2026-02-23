<?php

namespace App\Auth;

use App\Services\ApiKeyService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;

class ApiKeyGuard implements Guard
{
    private ?Authenticatable $user = null;
    private bool $attempted = false;

    public function __construct(
        private ApiKeyService $apiKeyService,
        private Request $request
    ) {}

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return !$this->check();
    }

    public function id(): int|string|null
    {
        return $this->user()?->getAuthIdentifier();
    }

    public function user(): ?Authenticatable
    {
        if ($this->user !== null) {
            return $this->user;
        }

        if ($this->attempted) {
            return null;
        }

        $this->attempted = true;

        $bearer = $this->request->bearerToken();

        if (!$bearer || !str_starts_with($bearer, 'sk_')) {
            return null;
        }

        $token = $this->apiKeyService->validate($bearer);

        if ($token) {
            $this->user = $token->user;
            $this->request->attributes->set('api_token', $token);
        }

        return $this->user;
    }

    public function validate(array $credentials = []): bool
    {
        $token = $credentials['api_key'] ?? null;

        if (!$token) {
            return false;
        }

        return $this->apiKeyService->validate($token) !== null;
    }

    public function hasUser(): bool
    {
        return $this->user !== null;
    }

    public function setUser(Authenticatable $user): static
    {
        $this->user = $user;
        return $this;
    }
}
