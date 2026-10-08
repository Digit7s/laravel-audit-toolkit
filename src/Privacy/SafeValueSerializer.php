<?php

namespace Digit7s\AuditToolkit\Privacy;

use BackedEnum;
use DateTimeInterface;
use Digit7s\AuditToolkit\Exceptions\UnsafeAuditValueException;
use JsonSerializable;
use SplObjectStorage;
use Throwable;

final class SafeValueSerializer
{
    public function serialize(mixed $value): mixed
    {
        return $this->normalize($value, new SplObjectStorage, 0);
    }

    private function normalize(mixed $value, SplObjectStorage $activeObjects, int $depth): mixed
    {
        if ($depth > 32) {
            throw new UnsafeAuditValueException('Audit value nesting exceeds the supported depth.');
        }

        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if ($value instanceof Throwable) {
            return [
                'type' => $value::class,
                'message' => '[UNSERIALIZED_EXCEPTION]',
            ];
        }

        if (is_array($value)) {
            $serialized = [];

            foreach ($value as $key => $item) {
                $serialized[$key] = $this->normalize($item, $activeObjects, $depth + 1);
            }

            return $serialized;
        }

        if ($value instanceof JsonSerializable) {
            if ($activeObjects->contains($value)) {
                throw new UnsafeAuditValueException('Recursive JsonSerializable audit values are not supported.');
            }

            $activeObjects->attach($value);

            try {
                return $this->normalize($value->jsonSerialize(), $activeObjects, $depth + 1);
            } catch (Throwable $exception) {
                if ($exception instanceof UnsafeAuditValueException) {
                    throw $exception;
                }

                throw new UnsafeAuditValueException('JsonSerializable audit value could not be normalized.', 0, $exception);
            } finally {
                $activeObjects->detach($value);
            }
        }

        throw new UnsafeAuditValueException(sprintf(
            'Unsupported audit value object [%s].',
            $value::class,
        ));
    }
}
