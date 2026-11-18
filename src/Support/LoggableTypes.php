<?php

namespace Lithium\VideoLogs\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Lithium\VideoLogs\Models\VideoLog;

class LoggableTypes
{
    /**
     * Apply a free-text search across title, creator name, and configured
     * loggable search_columns.
     */
    public static function applySearch(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.$term.'%';

        return $query->where(function (Builder $query) use ($like) {
            $query->where('title', 'like', $like);

            static::applyCreatorSearch($query, $like);
            static::applyLoggableSearch($query, $like);
        });
    }

    /**
     * Resolve and attach loggable_name on each video log, then drop the nested
     * loggable relation from the payload.
     *
     * Only loads models for types registered in video-logs.loggable_types so
     * legacy/alias morph strings (e.g. "customer") never blow up morphTo.
     *
     * @param  EloquentCollection<int, VideoLog>|Collection<int, VideoLog>  $videoLogs
     */
    public static function appendLoggableNames(EloquentCollection|Collection $videoLogs): void
    {
        static::loadConfiguredLoggables($videoLogs);

        foreach ($videoLogs as $videoLog) {
            $loggable = $videoLog->relationLoaded('loggable')
                ? $videoLog->getRelation('loggable')
                : null;

            $videoLog->setAttribute(
                'loggable_name',
                static::resolveLabel($loggable instanceof Model ? $loggable : null),
            );
            $videoLog->unsetRelation('loggable');
            $videoLog->makeHidden(['loggable']);
        }
    }

    /**
     * @param  EloquentCollection<int, VideoLog>|Collection<int, VideoLog>  $videoLogs
     */
    protected static function loadConfiguredLoggables(EloquentCollection|Collection $videoLogs): void
    {
        $types = config('video-logs.loggable_types', []);

        if (! is_array($types) || $videoLogs->isEmpty()) {
            return;
        }

        foreach ($types as $type => $config) {
            if (! is_string($type) || ! is_subclass_of($type, Model::class)) {
                continue;
            }

            /** @var class-string<Model> $type */
            $ids = $videoLogs
                ->where('loggable_type', $type)
                ->pluck('loggable_id')
                ->unique()
                ->filter(fn ($id) => $id !== null && $id !== '')
                ->values();

            if ($ids->isEmpty()) {
                continue;
            }

            $model = new $type;
            $keyName = $model->getKeyName();
            $loggables = $type::query()
                ->whereIn($keyName, $ids)
                ->get()
                ->keyBy($keyName);

            foreach ($videoLogs->where('loggable_type', $type) as $videoLog) {
                $videoLog->setRelation(
                    'loggable',
                    $loggables->get($videoLog->loggable_id),
                );
            }
        }
    }

    public static function resolveLabel(?Model $loggable): ?string
    {
        if (! $loggable instanceof Model) {
            return null;
        }

        $config = config('video-logs.loggable_types.'.$loggable::class, []);

        if (! is_array($config)) {
            return null;
        }

        $label = $config['label'] ?? null;

        if ($label === null) {
            $searchColumns = $config['search_columns'] ?? [];
            $label = is_array($searchColumns) && $searchColumns !== []
                ? $searchColumns[0]
                : null;
        }

        if (is_callable($label)) {
            $resolved = $label($loggable);

            return $resolved !== null && $resolved !== ''
                ? (string) $resolved
                : null;
        }

        if (! is_string($label) || $label === '') {
            return null;
        }

        $value = $loggable->getAttribute($label);

        return $value !== null && $value !== ''
            ? (string) $value
            : null;
    }

    protected static function applyCreatorSearch(Builder $query, string $like): void
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('video-logs.user.model');
        $nameColumn = config('video-logs.user.name_column', 'name');

        if (! is_subclass_of($userModel, Model::class)) {
            return;
        }

        $user = new $userModel;
        $userIds = $userModel::query()
            ->where($nameColumn, 'like', $like)
            ->pluck($user->getKeyName());

        if ($userIds->isNotEmpty()) {
            $query->orWhereIn('user_id', $userIds);
        }
    }

    protected static function applyLoggableSearch(Builder $query, string $like): void
    {
        $types = config('video-logs.loggable_types', []);

        if (! is_array($types)) {
            return;
        }

        foreach ($types as $type => $config) {
            if (! is_string($type) || ! is_subclass_of($type, Model::class)) {
                continue;
            }

            if (! is_array($config)) {
                continue;
            }

            $searchColumns = $config['search_columns'] ?? [];

            if (! is_array($searchColumns) || $searchColumns === []) {
                continue;
            }

            /** @var class-string<Model> $type */
            $model = new $type;
            $ids = $type::query()
                ->where(function (Builder $loggableQuery) use ($searchColumns, $like) {
                    foreach (array_values($searchColumns) as $index => $column) {
                        if (! is_string($column) || $column === '') {
                            continue;
                        }

                        if ($index === 0) {
                            $loggableQuery->where($column, 'like', $like);
                        } else {
                            $loggableQuery->orWhere($column, 'like', $like);
                        }
                    }
                })
                ->pluck($model->getKeyName());

            if ($ids->isEmpty()) {
                continue;
            }

            $query->orWhere(function (Builder $matchQuery) use ($type, $ids) {
                $matchQuery
                    ->where('loggable_type', $type)
                    ->whereIn('loggable_id', $ids);
            });
        }
    }
}
