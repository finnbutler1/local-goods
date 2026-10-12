<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Support\ViewAs;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class JudgeController extends Controller
{
    /** Switch which role the judge is viewing the app as, then open that role's home page. */
    public function viewAs(Request $request, ?string $role = null): RedirectResponse
    {
        if ($role === null) {
            $request->session()->forget(ViewAs::SESSION_KEY);

            return redirect()->route('judge.dashboard');
        }

        $role = Role::tryFrom($role);
        abort_unless(in_array($role, ViewAs::ROLES, true), 404);

        $request->session()->put(ViewAs::SESSION_KEY, $role->value);

        return redirect()->route($role->dashboardRoute());
    }
}
