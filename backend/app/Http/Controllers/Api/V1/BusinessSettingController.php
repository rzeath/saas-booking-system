<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\BusinessSettings\UpdateBusinessSettings;
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
        UpdateBusinessSettings $updateBusinessSettings,
    ): BusinessSettingResource {
        $settings = $tenant->organization()->businessSetting()->firstOrFail();

        return new BusinessSettingResource($updateBusinessSettings->handle(
            $settings,
            $request->validated(),
            $request->file('logo'),
            $request->boolean('remove_logo'),
        ));
    }
}
