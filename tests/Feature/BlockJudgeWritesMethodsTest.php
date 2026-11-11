<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Http\Middleware\BlockJudgeWrites;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * A judge may send GET, HEAD and OPTIONS and nothing else. Symfony calls QUERY
 * and TRACE "safe", and newer Laravel routes QUERY, so the middleware must not
 * lean on isMethodSafe(). The middleware is called directly so this holds
 * whatever routes exist and whichever framework version is installed.
 */
class BlockJudgeWritesMethodsTest extends TestCase
{
    use RefreshDatabase;

    private function run_(string $method, ?string $routeName, ?User $user): Response
    {
        $request = Request::create('/anything', $method);
        $request->setUserResolver(fn () => $user);
        if ($routeName !== null) {
            $route = new Route([$method], '/anything', fn () => 'ok');
            $route->name($routeName);
            $request->setRouteResolver(fn () => $route);
        }

        return (new BlockJudgeWrites)->handle($request, fn () => new Response('passed'));
    }

    private function judge(): User
    {
        return User::factory()->role(Role::Judge)->create();
    }

    public function test_a_judge_may_read(): void
    {
        $judge = $this->judge();
        foreach (['GET', 'HEAD', 'OPTIONS'] as $method) {
            $this->assertSame('passed', $this->run_($method, null, $judge)->getContent(), $method);
        }
    }

    public function test_a_judge_is_refused_query_trace_and_the_write_methods(): void
    {
        $judge = $this->judge();
        foreach (['QUERY', 'TRACE', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            try {
                $this->run_($method, 'some.other.route', $judge);
                $this->fail("$method from a judge was not refused.");
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode(), $method);
            }
        }
    }

    public function test_a_judge_is_refused_query_even_without_a_route(): void
    {
        $this->expectException(HttpException::class);
        $this->run_('QUERY', null, $this->judge());
    }

    public function test_the_judges_allowed_actions_still_pass(): void
    {
        $judge = $this->judge();
        foreach (['feedback.store', 'logout', 'judge-access.store'] as $name) {
            $this->assertSame('passed', $this->run_('POST', $name, $judge)->getContent(), $name);
        }
        $this->assertContains('judge-access.store', BlockJudgeWrites::ALLOWED_ROUTES);
    }

    public function test_other_people_are_not_affected(): void
    {
        $admin = User::factory()->role(Role::PlatformAdmin)->create();
        $this->assertSame('passed', $this->run_('POST', 'some.route', $admin)->getContent());
        $this->assertSame('passed', $this->run_('POST', 'some.route', null)->getContent());
    }
}
