<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWireRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from_component_id' => 'required|integer',
            'from_pin' => 'required|integer|min:0|max:2',
            'to_component_id' => 'required|integer|different:from_component_id',
            'to_pin' => 'required|integer|min:0|max:2',
        ];
    }
}
