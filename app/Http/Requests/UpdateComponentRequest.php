<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateComponentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pos_x' => 'nullable|integer|between:0,1160',
            'pos_y' => 'nullable|integer|between:0,680',
            'initial_value' => 'nullable|boolean',
            'label' => 'nullable|string|max:32',
        ];
    }
}
