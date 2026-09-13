<?php

namespace App\Support\Tenancy;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Factory as AuthFactory;

class TenantContext
{
    public function __construct(private readonly AuthFactory $auth) {}

    public function user(): User
    {
        $user = $this->auth->guard()->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }

    public function organizationId(): int
    {
        return (int) $this->user()->organization_id;
    }

    public function organization(): Organization
    {
        return $this->user()->organization;
    }
}
