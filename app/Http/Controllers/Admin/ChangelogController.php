<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChangelogEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** Platform admin only: write notes that reviewers see under "What's new". */
class ChangelogController extends Controller
{
    public function index(): View
    {
        Gate::authorize('manage-changelog');

        return view('admin.changelog', ['entries' => ChangelogEntry::latest('published_at')->latest('id')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage-changelog');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        ChangelogEntry::create([...$data, 'published_at' => now()]);

        return redirect()->route('admin.changelog.index')->with('status', 'Published. Reviewers will see it on their next visit.');
    }
}
