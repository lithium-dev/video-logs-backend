<?php

namespace Lithium\VideoLogs\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AbortVideoLogUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', $this->route('videoLog'));
    }

    public function rules(): array
    {
        return [
            //
        ];
    }
}
