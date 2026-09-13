<?php

namespace App\Actions\Auth;

use App\Models\BusinessSetting;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RegisterAdmin
{
    /**
     * @param  array{business_name: string, admin_name: string, email: string, password: string}  $data
     */
    public function handle(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $organization = Organization::create([
                'name' => $data['business_name'],
            ]);

            $user = $organization->user()->create([
                'name' => $data['admin_name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            $organization->businessSetting()->create(
                BusinessSetting::defaults($organization->name),
            );

            return $user->load('organization');
        });
    }
}
