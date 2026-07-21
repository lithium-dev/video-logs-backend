<?php

namespace Lithium\VideoLogs\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FinalizeVideoLogUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', $this->route('videoLog'));
    }

    public function rules(): array
    {
        return [
            'parts' => ['sometimes', 'array', 'min:1'],
            'parts.*.part_number' => ['required_with:parts', 'integer', 'min:1'],
            'parts.*.etag' => ['required_with:parts', 'string', 'max:255'],
        ];
    }
}
