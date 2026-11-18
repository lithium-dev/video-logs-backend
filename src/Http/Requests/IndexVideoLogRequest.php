<?php

namespace Lithium\VideoLogs\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Lithium\VideoLogs\Concerns\ResolvesLoggable;
use Lithium\VideoLogs\Models\VideoLog;

class IndexVideoLogRequest extends FormRequest
{
    use ResolvesLoggable;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('viewAny', VideoLog::class);
    }

    public function rules(): array
    {
        return [
            'loggable_id' => ['required_with:loggable_type'],
            'loggable_type' => ['required_with:loggable_id', 'nullable', 'string', 'max:255'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
