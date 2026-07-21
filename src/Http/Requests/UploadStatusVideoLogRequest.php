<?php

namespace Lithium\VideoLogs\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadStatusVideoLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('view', $this->route('videoLog'));
    }

    public function rules(): array
    {
        return [
            //
        ];
    }
}
