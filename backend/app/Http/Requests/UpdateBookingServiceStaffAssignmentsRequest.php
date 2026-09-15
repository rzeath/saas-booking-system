<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBookingServiceStaffAssignmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'staff_ids' => ['present', 'array', 'max:100'],
            'staff_ids.*' => ['required', 'integer', 'distinct'],
        ];
    }
}
