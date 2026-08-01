<?php

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;

class SaveCategoryMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'session_id'  => ['required', 'string'],
            'decisions'    => ['nullable', 'array'], // [pending_link_id => category_id|'skip']
        ];
    }

    public function sessionId(): string
    {
        return $this->validated('session_id');
    }

    public function decisions(): array
    {
        return $this->input('decisions', []);
    }
}
