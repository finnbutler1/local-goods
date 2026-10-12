<?php

namespace App\Http\Controllers;

use App\Support\ColorContrast;
use Illuminate\View\View;

/**
 * Every design token and component on one page. Local only for now; once
 * login exists it should also be open to admins.
 */
class StyleguideController extends Controller
{
    public function __invoke(): View
    {
        abort_unless(app()->environment('local'), 404);

        $tokens = ColorContrast::tokens();
        $pairs = collect(ColorContrast::PAIRS)
            ->map(fn ($p) => ['text' => $p[0], 'background' => $p[1], 'ratio' => ColorContrast::ratio($tokens[$p[0]], $tokens[$p[1]])])
            ->groupBy('background');

        return view('styleguide', ['tokens' => $tokens, 'pairs' => $pairs]);
    }
}
