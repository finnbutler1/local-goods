<?php

namespace Tests\Concerns;

use App\Enums\Role;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * Every GET page with no route parameters, opened signed out and as every
 * role, so a page added later is covered without touching the tests that
 * use this. Needs a seeded database and TestCase::signIn().
 */
trait SweepsPages
{
    /** @return list<string> Every GET page with no route parameters. */
    protected function pages(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $r) => in_array('GET', $r->methods(), true) && ! str_contains($r->uri(), '{'))
            ->map(fn (RoutingRoute $r) => '/'.ltrim($r->uri(), '/'))
            ->reject(fn (string $uri) => str_starts_with($uri, '/up') || str_starts_with($uri, '/storage'))
            ->values()->all();
    }

    /**
     * Calls $check(html, where) for every HTML page that opens (200) signed out
     * and as each role, and returns how many pages were checked.
     *
     * @param  list<Role>|null  $roles
     */
    protected function sweepPages(callable $check, ?array $roles = null): int
    {
        $checked = 0;

        foreach ($this->pages() as $uri) {
            $response = $this->get($uri);
            if ($this->isHtmlPage($response)) {
                $check($response->getContent(), "{$uri} signed out");
                $checked++;
            }
        }

        foreach ($roles ?? Role::cases() as $role) {
            $user = $this->signIn($role);

            foreach ($this->pages() as $uri) {
                $response = $this->actingAs($user)->get($uri);

                if ($this->isHtmlPage($response)) {
                    $check($response->getContent(), "{$uri} as {$role->value}");
                    $checked++;
                }
            }
        }

        return $checked;
    }

    /** JSON endpoints such as Fortify's confirmed-password-status aren't pages. */
    private function isHtmlPage(\Illuminate\Testing\TestResponse $response): bool
    {
        return $response->isOk() && str_contains((string) $response->headers->get('Content-Type'), 'text/html');
    }
}
