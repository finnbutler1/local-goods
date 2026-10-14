<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Platform admin home: every login with its role and status. Revoked
 * accounts are hidden unless "Show revoked" is on (?revoked=1).
 */
class AccountController extends Controller
{
    public function __invoke(Request $request): View
    {
        $showRevoked = $request->boolean('revoked');

        return view('dashboards.admin', [
            'users' => User::orderBy('id')->when(! $showRevoked, fn ($q) => $q->whereNull('revoked_at'))->get(),
            'showRevoked' => $showRevoked,
            'revokedCount' => User::whereNotNull('revoked_at')->count(),
        ]);
    }
}
