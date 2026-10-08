<?php

namespace Digit7s\AuditToolkit\Authentication;

use Digit7s\AuditToolkit\AuditManager;
use Digit7s\AuditToolkit\Data\AuditReference;
use Illuminate\Auth\Events\CurrentDeviceLogout;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;

final class AuthenticationAuditListener
{
    public function __construct(private readonly AuditManager $manager) {}

    public function subscribe(Dispatcher $events): void
    {
        $map = [
            'login' => Login::class,
            'logout' => Logout::class,
            'failed' => Failed::class,
            'password_reset' => PasswordReset::class,
            'email_verified' => Verified::class,
            'current_device_logout' => CurrentDeviceLogout::class,
            'other_device_logout' => OtherDeviceLogout::class,
        ];

        foreach ((array) config('audit-toolkit.authentication.events', []) as $name) {
            if (isset($map[$name])) {
                $events->listen($map[$name], [$this, 'handle']);
            }
        }
    }

    public function handle(object $event): void
    {
        $name = match ($event::class) {
            Login::class => 'login',
            Logout::class => 'logout',
            Failed::class => 'failed',
            PasswordReset::class => 'password_reset',
            Verified::class => 'email_verified',
            CurrentDeviceLogout::class => 'current_device_logout',
            OtherDeviceLogout::class => 'other_device_logout',
            default => null,
        };

        if ($name === null) {
            return;
        }

        if ($name === 'failed' && ! $this->withinFailedLoginVolumeLimit()) {
            return;
        }

        $user = property_exists($event, 'user') && $event->user instanceof Authenticatable
            ? $event->user
            : null;
        $actor = $name === 'failed' ? null : $this->reference($user);

        $this->manager->recordEvent(
            event: "auth.{$name}",
            actor: $actor,
            category: 'authentication',
            source: 'auth',
            guard: property_exists($event, 'guard') && is_string($event->guard) ? $event->guard : null,
            metadata: [
                'authentication_event' => $name,
                'known_account' => $user !== null,
            ],
            inheritContextActor: $name !== 'failed',
        );
    }

    private function reference(?Authenticatable $user): ?AuditReference
    {
        if ($user instanceof Model && $user->getKey() !== null) {
            return AuditReference::fromModel($user);
        }

        $identifier = $user?->getAuthIdentifier();

        if (is_string($identifier) || is_int($identifier)) {
            return new AuditReference($user::class, (string) $identifier);
        }

        return null;
    }

    private function withinFailedLoginVolumeLimit(): bool
    {
        $limit = (int) config('audit-toolkit.authentication.max_failed_per_request', 1);

        if ($limit < 1) {
            return false;
        }

        try {
            $request = app('request');
        } catch (\Throwable) {
            return true;
        }

        if (! $request instanceof Request) {
            return true;
        }

        $key = 'audit_toolkit_failed_auth_events';
        $count = (int) $request->attributes->get($key, 0);

        if ($count >= $limit) {
            return false;
        }

        $request->attributes->set($key, $count + 1);

        return true;
    }
}
