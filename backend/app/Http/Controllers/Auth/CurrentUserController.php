<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuthContextResource;
use App\Support\Tenancy\TenantContext;

class CurrentUserController extends Controller
{
    public function __invoke(TenantContext $tenant): AuthContextResource
    {
        return new AuthContextResource($tenant->user()->loadMissing('organization'));
    }
}
