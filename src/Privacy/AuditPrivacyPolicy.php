<?php

namespace Digit7s\AuditToolkit\Privacy;

final class AuditPrivacyPolicy
{
    public function __construct(
        private readonly SafeValueSerializer $serializer,
    ) {}

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string>  $allowedKeys
     * @return array<string, mixed>
     */
    public function sanitizeValues(array $values, array $allowedKeys): array
    {
        return $this->sanitizeAllowed($values, $allowedKeys);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string>  $allowedKeys
     * @return array<string, mixed>
     */
    public function sanitizeMetadata(array $metadata, array $allowedKeys): array
    {
        return $this->sanitizeAllowed($metadata, $allowedKeys);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string>  $allowedKeys
     * @return array<string, mixed>
     */
    private function sanitizeAllowed(array $values, array $allowedKeys, string $prefix = ''): array
    {
        $allowedKeys = array_values(array_filter(array_map('strval', $allowedKeys)));
        $normalized = $this->serializer->serialize($values);

        return is_array($normalized)
            ? $this->sanitizeMap($normalized, $allowedKeys, $prefix)
            : [];
    }

    private function sanitizeMap(array $values, array $allowedKeys, string $prefix = ''): array
    {
        $result = [];

        foreach ($values as $key => $value) {
            $originalKey = $key;
            $key = (string) $key;
            $path = $prefix === ''
                ? $key
                : (is_int($originalKey) ? $prefix : "{$prefix}.{$key}");

            if ($this->isSensitiveKey($key)) {
                continue;
            }

            $exactlyAllowed = $this->isExactlyAllowed($path, $allowedKeys);
            $hasAllowedDescendant = $this->hasAllowedDescendant($path, $allowedKeys);

            if (! $exactlyAllowed && ! $hasAllowedDescendant) {
                continue;
            }

            if (is_array($value)) {
                $result[$key] = $exactlyAllowed
                    ? $this->sanitizeBroad($value)
                    : $this->sanitizeMap($value, $allowedKeys, $path);

                continue;
            }

            if ($exactlyAllowed) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function sanitizeBroad(array $values): array
    {
        $result = [];

        foreach ($values as $key => $value) {
            $key = (string) $key;

            if ($this->isSensitiveKey($key)) {
                continue;
            }

            $result[$key] = is_array($value)
                ? $this->sanitizeBroad($value)
                : $value;
        }

        return $result;
    }

    private function isExactlyAllowed(string $path, array $allowedKeys): bool
    {
        return in_array('*', $allowedKeys, true) || in_array($path, $allowedKeys, true);
    }

    private function hasAllowedDescendant(string $path, array $allowedKeys): bool
    {
        foreach ($allowedKeys as $allowedKey) {
            if (str_starts_with($allowedKey, "{$path}.")) {
                return true;
            }
        }

        return false;
    }

    private function isSensitiveKey(string $key): bool
    {
        return preg_match('/(?:password|passcode|secret|token|api[_-]?key|authorization|cookie|credential|private[_-]?key|credit[_-]?card|cvv|ssn|request[_-]?body|response[_-]?body)/i', $key) === 1;
    }
}
