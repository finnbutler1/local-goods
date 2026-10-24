<?php

namespace App\Http\Controllers\Employee;

use App\Models\Employee;
use Illuminate\Http\Request;

trait ResolvesEmployee
{
    /**
     * The signed-in employee's own wallet. A login with no wallet linked yet
     * (for example before the demo data is seeded) gets 403. A judge sees
     * the demo employee's wallet, read-only.
     */
    private function employee(Request $request): Employee
    {
        $employee = $request->user()->isReadOnly()
            ? $this->demoEmployee()
            : $request->user()->employee()->with('employer')->first();

        abort_if($employee === null, 403, 'No wallet is linked to this login yet.');

        return $employee;
    }

    /** What a judge sees when viewing as an employee: the demo employee's wallet (all synthetic). */
    private function demoEmployee(): ?Employee
    {
        return Employee::with('employer')
            ->whereHas('user', fn ($q) => $q->where('email', config('demo.accounts.employee.email')))
            ->first() ?? Employee::with('employer')->orderBy('id')->first();
    }
}
