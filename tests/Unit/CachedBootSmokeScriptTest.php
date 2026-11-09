<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/** The smoke check caches config, so it must never run outside CI. */
class CachedBootSmokeScriptTest extends TestCase
{
    public function test_the_script_refuses_to_run_outside_ci(): void
    {
        $result = Process::path(base_path())->env(['CI' => 'false'])->run(['bash', 'scripts/ci-cached-boot-smoke.sh']);

        $this->assertSame(1, $result->exitCode());
        $this->assertStringContainsString('only runs in CI', $result->errorOutput());
    }

    public function test_the_workflow_runs_it(): void
    {
        $this->assertStringContainsString('bash scripts/ci-cached-boot-smoke.sh', (string) file_get_contents(base_path('.github/workflows/ci.yml')));
    }
}
