<?php

namespace Lithium\VideoLogs\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Lithium\VideoLogs\Concerns\ResolvesLoggable;

class StoreVideoLogRequest extends FormRequest
{
    use ResolvesLoggable;

    public function authorize(): bool
    {
        return $this->authorizeLoggableAccess();
    }

    public function rules(): array
    {
        return [
            'loggable_id' => ['required'],
            'loggable_type' => $this->loggableTypeRule(),
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'duration_seconds' => ['required', 'integer', 'min:1', 'max:'.config('video-logs.max_duration_seconds')],
            'mime_type' => [
                'nullable',
                'string',
                Rule::in(config('video-logs.allowed_mime_types', [])),
            ],
            'size_bytes' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
