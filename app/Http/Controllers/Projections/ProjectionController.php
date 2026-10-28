<?php

namespace App\Http\Controllers\Projections;

use App\Http\Controllers\Controller;
use App\Support\Projections\BusinessModel;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The public impact projection (ADR 0030): what the program could mean for
 * independent merchants and the district, by scenario and case. District
 * numbers only: no Local Goods fee, revenue or profit figure ever appears
 * here. Labelled "Projection, not results" with its assumptions listed.
 */
class ProjectionController extends Controller
{
    use ResolvesProjection;

    public function __invoke(Request $request): View
    {
        [$scenario, $case] = $this->choice($request, 'a');
        $projection = BusinessModel::fromConfig()->run($scenario, $case);

        return view('projections.public', [
            'scenario' => $scenario,
            'case' => $case,
            'projection' => $projection,
            'district' => $projection->districtImpact(),
            'source' => config('impact.recirculation'),
        ]);
    }
}
