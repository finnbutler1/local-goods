<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards scripts/build-deploy-zip.sh. A deploy.zip that carried public/hot
 * made the live site load with no CSS, because Laravel pointed every page at
 * the Vite dev server on 127.0.0.1:5173.
 */
class DeployZipScriptTest extends TestCase
{
    private function script(): string
    {
        return file_get_contents(__DIR__.'/../../scripts/build-deploy-zip.sh');
    }

    public function test_script_refuses_to_build_while_public_hot_exists_and_never_zips_it(): void
    {
        $script = $this->script();

        $this->assertStringContainsString('if [ -e public/hot ]; then', $script);
        $this->assertStringContainsString('-x "public/hot"', $script);
        $this->assertStringContainsString('grep -qx "public/hot"', $script, 'The finished zip is checked for public/hot too.');
    }

    public function test_script_builds_fresh_and_checks_manifest_and_fonts(): void
    {
        $script = $this->script();

        $this->assertMatchesRegularExpression('/rm -rf public\/build\s+npm run build/', $script);
        $this->assertStringContainsString('public/build/manifest.json is missing', $script);
        $this->assertStringContainsString('self-hosted font $font.woff2 is not in the build', $script);
        $this->assertStringContainsString('deploy.zip is missing', $script);
        $this->assertStringNotContainsString('-x "public/build', $script, 'public/build must ship.');
    }

    public function test_script_requires_the_latin_file_of_every_font_the_site_imports(): void
    {
        $script = $this->script();
        preg_match_all("#@import '@fontsource-variable/([a-z0-9-]+)/wght\.css';#", file_get_contents(__DIR__.'/../../resources/css/app.css'), $m);

        $this->assertSame(['overpass', 'atkinson-hyperlegible-next'], $m[1], 'The fonts in app.css changed: update the deploy check too.');
        preg_match('/foreach \(\[([^\]]*)\] as \$font\)/', $script, $list);
        foreach ($m[1] as $font) {
            $this->assertStringContainsString("\"{$font}-latin-wght-normal\"", $list[1] ?? '', "The deploy check must require {$font}'s latin file.");
        }
        foreach (['fraunces', 'public-sans'] as $retired) {
            $this->assertStringNotContainsString($retired, $script, "{$retired} was replaced in ADR 0025.");
        }
    }
}
