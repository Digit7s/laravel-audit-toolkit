<?php

namespace Digit7s\AuditToolkit\Context;

use Digit7s\AuditToolkit\Contracts\AuditContextResolver;
use Digit7s\AuditToolkit\Data\AuditContext;
use Digit7s\AuditToolkit\Data\AuditReference;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Throwable;

final class DefaultAuditContextResolver implements AuditContextResolver
{
    public function resolve(): AuditContext
    {
        [$actor, $guard] = $this->resolveActor();
        $request = $this->request();

        $metadata = [];

        if ((bool) config('audit-toolkit.context.include_ip', false) && $request) {
            $metadata['ip_address'] = $request->ip();
        }

        if ((bool) config('audit-toolkit.context.include_user_agent', false) && $request) {
            $metadata['user_agent'] = $request->userAgent();
        }

        $correlationId = $this->validUuid($this->header($request, 'correlation_id'));

        if ($correlationId === null && $request && (bool) config('audit-toolkit.context.generate_correlation_id', true)) {
            $correlationId = $request->attributes->get('audit_toolkit_correlation_id');

            if (! is_string($correlationId) || ! Str::isUuid($correlationId)) {
                $correlationId = (string) Str::uuid();
                $request->attributes->set('audit_toolkit_correlation_id', $correlationId);
            }
        }

        $source = app()->runningInConsole() ? 'console' : 'http';

        return new AuditContext(
            actor: $actor,
            guard: $guard,
            source: $source,
            originalSource: $source,
            correlationId: $correlationId,
            requestId: $this->validUuid($this->header($request, 'request_id')),
            metadata: $metadata,
        );
    }

    /**
     * @return array{0: AuditReference|null, 1: string|null}
     */
    private function resolveActor(): array
    {
        $guards = config('audit-toolkit.context.guard_priority', []);
        $guards = array_values(array_filter(is_array($guards) ? $guards : [], 'is_string'));

        if ($guards === []) {
            $configuredGuards = config('auth.guards', []);
            $guards = array_keys(is_array($configuredGuards) ? $configuredGuards : []);
        }

        $active = [];

        foreach ($guards as $guard) {
            try {
                $auth = Auth::guard($guard);
                $user = $auth->user();

                if ($user instanceof Authenticatable) {
                    $active[] = [$guard, $user];
                }
            } catch (Throwable) {
                // A package must remain usable in console and non-authenticated contexts.
            }
        }

        if (count($active) !== 1) {
            return [null, null];
        }

        [$guard, $user] = $active[0];

        try {
            return [AuditReference::fromModel($user), $guard];
        } catch (Throwable) {
            return [null, $guard];
        }
    }

    private function request(): ?Request
    {
        try {
            $request = app('request');

            return $request instanceof Request ? $request : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function header(?Request $request, string $configKey): ?string
    {
        if (! $request) {
            return null;
        }

        $header = (string) config("audit-toolkit.context.{$configKey}_header", '');

        $value = $header === '' ? null : $request->header($header);

        return is_string($value) ? $value : null;
    }

    private function validUuid(?string $value): ?string
    {
        return $value !== null && Str::isUuid($value) ? $value : null;
    }
}
