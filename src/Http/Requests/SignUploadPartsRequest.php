<?php

namespace Lithium\VideoLogs\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SignUploadPartsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', $this->route('videoLog'));
    }

    public function rules(): array
    {
        return [
            'part_numbers' => ['required', 'array', 'min:1'],
            'part_numbers.*' => ['required', 'integer', 'min:1', 'max:10000'],
        ];
    }
}
