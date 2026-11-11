<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Tests\TestCase;

class SecurityTxtTest extends TestCase
{
    public function test_security_txt_has_the_required_fields(): void
    {
        $path = public_path('.well-known/security.txt');
        $this->assertFileExists($path);
        $text = file_get_contents($path);

        $this->assertStringContainsString('Contact: mailto:butlerf@cua.edu', $text);
        $this->assertStringContainsString('Preferred-Languages: en', $text);
        $this->assertStringContainsString('Policy: https://github.com/finnbutler1/local-goods/blob/main/SECURITY.md', $text);
        $this->assertSame(1, preg_match('/^Expires: (\S+)$/m', $text, $m));

        $expires = CarbonImmutable::parse($m[1]);
        $this->assertTrue($expires->isFuture(), 'security.txt has expired: renew its Expires date.');
        $this->assertTrue($expires->lessThanOrEqualTo(now()->addYear()->addDay()), 'Expires should be at most about a year out.');
    }

    public function test_security_md_covers_the_basics(): void
    {
        $md = file_get_contents(base_path('SECURITY.md'));

        foreach (['butlerf@cua.edu', 'test mode', 'No bug bounty', '5 business days', 'In scope'] as $needle) {
            $this->assertStringContainsString($needle, $md);
        }
    }
}
