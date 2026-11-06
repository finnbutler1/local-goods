<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * infra/cloudfront/canonical-host.js, the CloudFront Function that sends
 * www and the old cloudfront.net address to uselocalgoods.com (ADR 0037).
 * Runs the real file with node, on events shaped like CloudFront's.
 */
class CanonicalHostFunctionTest extends TestCase
{
    private const FUNCTION = __DIR__.'/../../infra/cloudfront/canonical-host.js';

    /** @return array<string, mixed> What the function returns for this request. */
    private function handle(string $host, string $uri, array $querystring = []): array
    {
        exec('command -v node', $found, $missing);
        if ($missing !== 0) {
            $this->markTestSkipped('node is not installed.');
        }

        $event = ['request' => ['method' => 'GET', 'uri' => $uri, 'querystring' => (object) $querystring, 'headers' => ['host' => ['value' => $host]]]];
        $script = 'const src = require("fs").readFileSync(process.argv[1], "utf8");'
            .'const handler = new Function(src + "; return handler;")();'
            .'process.stdout.write(JSON.stringify(handler(JSON.parse(process.argv[2]))));';

        exec('node -e '.escapeshellarg($script).' '.escapeshellarg(self::FUNCTION).' '.escapeshellarg(json_encode($event)).' 2>&1', $out, $status);
        $this->assertSame(0, $status, implode("\n", $out));

        return json_decode(implode("\n", $out), true);
    }

    public static function otherHosts(): array
    {
        return ['www' => ['www.uselocalgoods.com'], 'old CloudFront address' => ['d4ghrpm72uczx.cloudfront.net'], 'upper case' => ['WWW.UseLocalGoods.com']];
    }

    #[DataProvider('otherHosts')]
    public function test_other_hosts_move_to_the_bare_domain_keeping_path_and_query(string $host): void
    {
        $response = $this->handle($host, '/judge-access');
        $this->assertSame(301, $response['statusCode']);
        $this->assertSame('https://uselocalgoods.com/judge-access', $response['headers']['location']['value']);

        $response = $this->handle($host, '/employer', [
            'checkout' => ['value' => 'returned'],
            'purchase' => ['value' => 'MPG-1'],
            'tag' => ['value' => 'a', 'multiValue' => [['value' => 'a'], ['value' => 'b']]],
            'flag' => ['value' => ''],
        ]);
        $this->assertSame('https://uselocalgoods.com/employer?checkout=returned&purchase=MPG-1&tag=a&tag=b&flag', $response['headers']['location']['value']);
    }

    public static function neverRedirected(): array
    {
        $cases = [];
        foreach (['uselocalgoods.com', 'www.uselocalgoods.com', 'd4ghrpm72uczx.cloudfront.net'] as $host) {
            foreach (['/stripe/webhook', '/up'] as $uri) {
                $cases["{$uri} on {$host}"] = [$host, $uri];
            }
        }

        return $cases + ['any page on the bare domain' => ['uselocalgoods.com', '/login']];
    }

    #[DataProvider('neverRedirected')]
    public function test_the_webhook_the_health_check_and_the_bare_domain_pass_through(string $host, string $uri): void
    {
        $response = $this->handle($host, $uri);

        $this->assertArrayNotHasKey('statusCode', $response);
        $this->assertSame($uri, $response['uri']);
    }
}
