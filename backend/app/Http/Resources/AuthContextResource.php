<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthContextResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'user' => [
                'id' => $this->resource->id,
                'name' => $this->resource->name,
                'email' => $this->resource->email,
            ],
            'organization' => [
                'id' => $this->resource->organization->id,
                'name' => $this->resource->organization->name,
                'status' => $this->resource->organization->status,
            ],
        ];
    }
}
