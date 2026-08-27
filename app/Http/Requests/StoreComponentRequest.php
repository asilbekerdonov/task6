<?php

namespace App\Http\Requests;

use App\Models\CircuitComponent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreComponentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(CircuitComponent::TYPES)],
            'pos_x' => 'required|integer|between:0,1160',
            'pos_y' => 'required|integer|between:0,680',
            'label' => 'nullable|string|max:32',
        ];
    }
}
