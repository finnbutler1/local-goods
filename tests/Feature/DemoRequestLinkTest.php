<?php

namespace Tests\Feature;

use App\Support\DemoRequestLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoRequestLinkTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED = 'mailto:butlerf@cua.edu?subject=Local%20Goods%20demo%20request'
        .'&body=Organization%3A%0D%0AYour%20name%3A%0D%0ARole%3A%0D%0ABest%20time%20to%20talk%3A%0D%0A%0D%0AAnything%20else%20we%20should%20know%3A';

    public function test_the_link_is_an_encoded_mailto(): void
    {
        $this->assertSame(self::EXPECTED, DemoRequestLink::url());
    }

    public function test_the_address_comes_from_config(): void
    {
        config(['localgoods.demo_email' => 'someone@example.com']);

        $this->assertStringStartsWith('mailto:someone@example.com?subject=', DemoRequestLink::url());
    }

    public function test_every_request_a_demo_button_uses_the_mailto(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        preg_match_all('#<a\s[^>]*href="([^"]*)"[^>]*>\s*(Request a demo[^<]*?)\s*</a>#', $html, $m);

        $this->assertCount(3, $m[1]);
        foreach ($m[1] as $href) {
            $this->assertSame(self::EXPECTED, html_entity_decode($href));
        }
        $this->assertStringNotContainsString('href="#request-demo"', $html);
    }

    public function test_final_section_shows_the_address_as_plain_text(): void
    {
        $html = $this->get('/')->getContent();
        $section = substr($html, strpos($html, 'id="request-demo"'));

        $this->assertStringContainsString('Or email', $section);
        $this->assertMatchesRegularExpression('#Or email\s*<span[^>]*>\s*butlerf@cua\.edu\s*</span>#', $section);
    }
}
