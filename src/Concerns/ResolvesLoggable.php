<?php

namespace Lithium\VideoLogs\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;
use Lithium\VideoLogs\Models\VideoLog;

trait ResolvesLoggable
{
    protected function loggableTypeRule(): array
    {
        // return ['required', 'string', 'max:255', Rule::in(array_keys(config('video-logs.loggable_types', [])))];
        return ['required', 'string', 'max:255'];
    }

    protected function authorizeLoggableAccess(): bool
    {
        return (bool) $this->user()?->can('create', VideoLog::class);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! config('video-logs.verify_loggable_access', true)) {
                return;
            }

            $loggable = $this->resolveLoggable();

            if (! $loggable instanceof Model) {
                $validator->errors()->add('loggable_id', 'The selected loggable is invalid.');

                return;
            }

            if (Gate::denies('view', $loggable)) {
                $validator->errors()->add('loggable_id', 'You are not allowed to attach video logs to this record.');
            }
        });
    }

    protected function resolveLoggable(): ?Model
    {
        $type = (string) $this->input('loggable_type');
        $id = $this->input('loggable_id');
        $class = $this->resolveLoggableClass($type);

        if (! $class || $id === null) {
            return null;
        }

        /** @var class-string<Model> $class */
        return $class::query()->find($id);
    }

    protected function resolveLoggableClass(string $type): ?string
    {
        $configured = config("video-logs.loggable_types.{$type}");

        if (is_string($configured) && is_subclass_of($configured, Model::class)) {
            return $configured;
        }

        if (is_subclass_of($type, Model::class)) {
            return $type;
        }

        return null;
    }
}
