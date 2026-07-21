<?php

namespace Lithium\VideoLogs\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Lithium\VideoLogs\Models\VideoLog;

class RetryVideoLogProcessingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('retryProcessing', $this->route('videoLog'));
    }

    public function rules(): array
    {
        return [];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $videoLog = $this->route('videoLog');

            if ($videoLog instanceof VideoLog && $videoLog->status !== 'failed') {
                $validator->errors()->add('status', 'Only video logs that failed to process can be retried.');
            }
        });
    }
}
