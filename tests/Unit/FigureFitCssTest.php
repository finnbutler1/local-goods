<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * A large figure must never run into its neighbour. The rule lives in one
 * place (resources/css/app.css): cells holding a .money figure are size
 * containers and every figure size is capped at a share of its cell. The
 * browser check ran with $1,234,567.89 and 12,345 at 1440, 1024 and 390px.
 */
class FigureFitCssTest extends TestCase
{
    private function css(): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');
    }

    public function test_figure_cells_are_containers_and_can_shrink(): void
    {
        $css = $this->css();

        $this->assertMatchesRegularExpression('/\.figure-cell\s*\{[^}]*container-type:\s*inline-size;[^}]*min-width:\s*0;/s', $css);
        $this->assertStringContainsString(':has(.money', $css);
    }

    public function test_every_figure_size_is_capped_to_its_cell(): void
    {
        $css = $this->css();

        foreach (['text-3xl', 'text-4xl', 'text-5xl', 'text-6xl', 'sm:text-4xl', 'sm:text-5xl', 'sm:text-6xl', 'lg:text-5xl'] as $size) {
            $this->assertMatchesRegularExpression('/\.money\[class~="'.preg_quote($size, '/').'"\]\s*\{\s*font-size:\s*min\([^;]*cqw\);/', $css, $size);
        }
    }

    public function test_views_only_use_figure_sizes_the_css_caps(): void
    {
        $capped = ['text-3xl', 'text-4xl', 'text-5xl', 'text-6xl', 'sm:text-4xl', 'sm:text-5xl', 'sm:text-6xl', 'lg:text-5xl'];
        $found = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2).'/resources/views')) as $file) {
            if (! $file->isFile()) {
                continue;
            }
            preg_match_all('/class="([^"]*\bmoney\b[^"]*)"/', file_get_contents($file->getPathname()), $m);
            foreach ($m[1] as $classes) {
                preg_match_all('/(?<![\w:\[-])((?:sm:|md:|lg:|xl:)?text-[3-9]xl)\b/', $classes, $sizes);
                foreach ($sizes[1] as $size) {
                    if (! in_array($size, $capped, true)) {
                        $found[] = basename($file->getPathname()).': '.$size;
                    }
                }
            }
        }

        $this->assertSame([], $found, 'Add a cap in app.css for each new figure size.');
    }
}
