<?php

namespace App\Http\Controllers\Projections;

use App\Support\Projections\Assumptions;
use Illuminate\Http\Request;

/** The scenario and case picked on a projection page (?scenario=b&case=high); anything else falls back to the default. */
trait ResolvesProjection
{
    /** @return array{0: string, 1: string} */
    private function choice(Request $request, string $defaultScenario): array
    {
        $scenario = (string) $request->query('scenario', $defaultScenario);
        $case = (string) $request->query('case', 'base');

        return [
            array_key_exists($scenario, config('projections.scenarios')) ? $scenario : $defaultScenario,
            in_array($case, Assumptions::CASES, true) ? $case : 'base',
        ];
    }
}
