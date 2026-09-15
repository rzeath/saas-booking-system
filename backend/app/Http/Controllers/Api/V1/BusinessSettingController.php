<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateBusinessSettingRequest;
use App\Http\Resources\BusinessSettingResource;
use App\Support\Tenancy\TenantContext;

class BusinessSettingController extends Controller
{
    public function show(TenantContext $tenant): BusinessSettingResource
    {
        return new BusinessSettingResource(
            $tenant->organization()->businessSetting()->firstOrFail(),
        );
    }

    public function update(
        UpdateBusinessSettingRequest $request,
        TenantContext $tenant,
    ): BusinessSettingResource {
        $settings = $tenant->organization()->businessSetting()->firstOrFail();
        $settings->update($request->validated());

        return new BusinessSettingResource($settings->refresh());
    }
}
