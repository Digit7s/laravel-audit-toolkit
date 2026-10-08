<?php

namespace Digit7s\AuditToolkit\Support;

use Illuminate\Database\Eloquent\Model;

final class AuditChangeSet
{
    /**
     * @param  array<string>  $allowedFields
     * @return array{old: array<string, mixed>, new: array<string, mixed>}
     */
    public function forUpdate(Model $model, array $allowedFields): array
    {
        $old = [];
        $new = [];
        $dirty = $model->getDirty();

        foreach ($allowedFields as $field) {
            if (! array_key_exists($field, $dirty)) {
                continue;
            }

            $old[$field] = $model->getOriginal($field);
            $new[$field] = $model->getAttribute($field);
        }

        return ['old' => $old, 'new' => $new];
    }

    /**
     * @param  array<string>  $allowedFields
     * @return array<string, mixed>
     */
    public function current(Model $model, array $allowedFields): array
    {
        $values = [];

        foreach ($allowedFields as $field) {
            if (! array_key_exists($field, $model->getAttributes())) {
                continue;
            }

            $values[$field] = $model->getAttribute($field);
        }

        return $values;
    }

    /**
     * @param  array<string>  $allowedFields
     * @return array{old: array<string, mixed>, new: array<string, mixed>}
     */
    public function forCreate(Model $model, array $allowedFields): array
    {
        return ['old' => [], 'new' => $this->current($model, $allowedFields)];
    }
}
