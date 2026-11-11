<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DependabotConfigTest extends TestCase
{
    public function test_dependabot_covers_composer_npm_and_actions_weekly(): void
    {
        $text = file_get_contents(dirname(__DIR__, 2).'/.github/dependabot.yml');

        foreach (['composer', 'npm', 'github-actions'] as $ecosystem) {
            $this->assertStringContainsString("package-ecosystem: $ecosystem", $text);
        }
        $this->assertSame(3, substr_count($text, 'interval: weekly'));
        $this->assertSame(3, substr_count($text, 'open-pull-requests-limit:'));
        $this->assertStringContainsString('NOT merged before the Nov 16', $text);
    }
}
