<?php

namespace App\Services\Sandbox;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;

/**
 * Who the sandbox guard may sign in (ADR 0045): only a user read from the
 * sandbox's own file, with a sandbox email, while a sandbox is active. With
 * no sandbox it finds nobody, so a sandbox session can never be turned into
 * a live login, even if its user id matched a live account. Passwords are
 * never accepted: the role bar is the only way in.
 */
class SandboxUserProvider extends EloquentUserProvider
{
    public function retrieveById($identifier): ?Authenticatable
    {
        // With no sandbox, never query anything (the default connection would be the live one).
        return Sandbox::active() ? $this->accept(parent::retrieveById($identifier)) : null;
    }

    public function retrieveByToken($identifier, $token): ?Authenticatable
    {
        return Sandbox::active() ? $this->accept(parent::retrieveByToken($identifier, $token)) : null;
    }

    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        return null;
    }

    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        return false;
    }

    public static function isSandboxUser(?Authenticatable $user): bool
    {
        return $user !== null
            && $user->getConnectionName() === Sandbox::CONNECTION
            && Str::endsWith((string) $user->email, '@'.config('sandbox.email_domain'));
    }

    private function accept(?Authenticatable $user): ?Authenticatable
    {
        return Sandbox::active() && self::isSandboxUser($user) ? $user : null;
    }
}
