<?php

namespace Lithium\VideoLogs\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Lithium\VideoLogs\Models\VideoLog;

class CheckVideoLogStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('checkStatus', $this->route('videoLog'));
    }

    public function rules(): array
    {
        return [];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $videoLog = $this->route('videoLog');

            if ($videoLog instanceof VideoLog && $videoLog->status !== 'processing') {
                $validator->errors()->add('status', 'Only video logs that are processing can have their status checked.');
            }
        });
    }
}
