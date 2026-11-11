<?php

namespace App\Http\Controllers;

use App\Support\DeployInfo;
use Illuminate\Http\JsonResponse;

/** "What is deployed right now": the commit and build time, and nothing else. Public. */
class VersionController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()
            ->json(DeployInfo::read(config('deploy.marker_directory')))
            ->header('Cache-Control', 'no-store');
    }
}
