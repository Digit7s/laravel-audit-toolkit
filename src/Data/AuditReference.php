<?php

namespace Digit7s\AuditToolkit\Data;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final readonly class AuditReference
{
    public function __construct(
        public string $type,
        public string $id,
    ) {
        if ($this->type === '' || $this->id === '') {
            throw new InvalidArgumentException('Audit references require a type and identifier.');
        }

        if (mb_strlen($this->type) > 255 || mb_strlen($this->id) > 255) {
            throw new InvalidArgumentException('Audit reference type and identifier may not exceed 255 characters.');
        }
    }

    public static function fromModel(Model $model): self
    {
        if ($model->getKey() === null) {
            throw new InvalidArgumentException('An audit reference requires a persisted model key.');
        }

        return new self($model->getMorphClass(), (string) $model->getKey());
    }
}
