<?php

namespace Tests\Unit;

use App\Services\Eligibility\EvidenceUrl;
use PHPUnit\Framework\TestCase;

class EvidenceUrlTest extends TestCase
{
    public function test_ad_click_and_search_tracking_ids_are_removed(): void
    {
        $this->assertSame('https://example.test/find', EvidenceUrl::withoutTracking(
            'https://example.test/find?utm_source=example&utm_medium=cpc&gad_source=1&gad_campaignid=123&gbraid=example-id&gclid=example-click-id'
        ));
        $this->assertSame('https://example.test/stores', EvidenceUrl::withoutTracking('https://example.test/stores?srsltid=example-id'));
        $this->assertSame('https://example.test/?page=2#map', EvidenceUrl::withoutTracking('https://example.test/?wbraid=example&page=2&fbclid=example&msclkid=example#map'));
    }

    public function test_links_without_tracking_are_unchanged(): void
    {
        foreach ([
            'https://en.wikipedia.org/wiki/Dunkin%27_Donuts',
            'https://cava.com/locations#DC',
            'https://example.test/biz/i-example/0291915ec6ad3b87.html',
            'https://example.test/search?q=coffee&city=dc',
        ] as $url) {
            $this->assertSame($url, EvidenceUrl::withoutTracking($url));
        }
    }
}
