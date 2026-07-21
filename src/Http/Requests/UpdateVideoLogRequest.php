<?php

namespace Lithium\VideoLogs\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVideoLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', $this->route('videoLog'));
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ];
    }
}
