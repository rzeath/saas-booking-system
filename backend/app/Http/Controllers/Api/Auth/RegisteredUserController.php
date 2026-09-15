<?php

namespace App\Http\Controllers\Api\Auth;

use App\Actions\Auth\RegisterAdmin;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\AuthContextResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class RegisteredUserController extends Controller
{
    public function store(RegisterRequest $request, RegisterAdmin $registerAdmin): JsonResponse
    {
        $user = $registerAdmin->handle($request->validated());

        Auth::login($user);
        $request->session()->regenerate();

        return (new AuthContextResource($user))
            ->response()
            ->setStatusCode(201);
    }
}
