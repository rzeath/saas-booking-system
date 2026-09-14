<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusinessSettingResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'display_name' => $this->resource->display_name,
            'email' => $this->resource->email,
            'phone' => $this->resource->phone,
            'address' => $this->resource->address,
            'logo_path' => $this->resource->logo_path,
            'currency' => $this->resource->currency,
            'booking_prefix' => $this->resource->booking_prefix,
            'quotation_prefix' => $this->resource->quotation_prefix,
            'billing_prefix' => $this->resource->billing_prefix,
        ];
    }
}
