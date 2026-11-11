<?php

namespace Tests\Feature;

use Tests\TestCase;

class VersionEndpointTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/lg-version-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        config(['deploy.marker_directory' => $this->dir]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/.deploy-*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    public function test_it_returns_the_commit_and_build_time_from_the_markers(): void
    {
        $hash = 'f44b008a1b2c3d4e5f60718293a4b5c6d7e8f901';
        file_put_contents($this->dir.'/.deploy-zip-commit', $hash."\n");
        file_put_contents($this->dir.'/.deploy-zip-time', "2026-11-11T15:04:05Z\n");

        $this->getJson('/version')
            ->assertOk()
            ->assertExactJson(['commit_short' => 'f44b008', 'commit' => $hash, 'built_at' => '2026-11-11T15:04:05Z'])
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_it_says_unknown_when_markers_are_missing_or_malformed(): void
    {
        $this->getJson('/version')->assertOk()
            ->assertExactJson(['commit_short' => 'unknown', 'commit' => 'unknown', 'built_at' => 'unknown']);

        file_put_contents($this->dir.'/.deploy-zip-commit', 'APP_KEY=base64:not-a-real-secret');
        file_put_contents($this->dir.'/.deploy-zip-time', 'yesterday');

        $response = $this->getJson('/version')->assertOk();
        $this->assertSame('unknown', $response->json('commit'));
        $this->assertSame('unknown', $response->json('built_at'));
        $this->assertStringNotContainsString('APP_KEY', $response->getContent());
    }

    public function test_it_is_public_and_not_cacheable(): void
    {
        $this->get('/version')->assertOk()->assertHeader('Content-Type', 'application/json');
        $this->assertStringContainsString('no-store', $this->get('/version')->headers->get('Cache-Control'));
    }
}
