<?php

namespace App\Http\Controllers\Employer;

use App\Enums\Occasion;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Merchant\RedemptionsExportController;
use App\Models\CreditAllocation;
use App\Models\Employer;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The employer's payroll report: a CSV of the credits actually given to each
 * employee in a date range (both days included, DC time), with a total per
 * employee and a grand total, so HR can add them to payroll. Reads only.
 *
 * Only credit allocations count: each is exactly one `allowance` ledger entry
 * on the employee's account. Credits bought and not yet given, refunds and
 * returned credit never appear. Employees by initials and number, as the
 * dashboard shows them (ADR 0014). Judges get the demo employer's report;
 * inside a sandbox it reads only that sandbox's file (ADR 0045).
 */
class PayrollReportController extends Controller
{
    public const HEADERS = ['Employee', 'Employee ID', 'Date given (DC time)', 'Occasion', 'Amount'];

    /** The dashboard form's error bag. */
    public const ERRORS = 'payroll';

    public function __invoke(Request $request): StreamedResponse|RedirectResponse
    {
        $zone = config('fraud.timezone');
        $now = now()->setTimezone($zone);
        $input = [
            'from' => $request->query('from', $now->copy()->startOfMonth()->format('Y-m-d')),
            'to' => $request->query('to', $now->copy()->endOfMonth()->format('Y-m-d')),
        ];

        $validator = Validator::make($input, [
            'from' => ['required', 'string', 'date_format:Y-m-d'],
            'to' => ['required', 'string', 'date_format:Y-m-d', 'after_or_equal:from'],
        ], [
            'from.date_format' => 'Enter the first day as a date.',
            'to.date_format' => 'Enter the last day as a date.',
            'to.after_or_equal' => 'The last day must be on or after the first day.',
        ]);

        if ($validator->fails()) {
            return redirect()->to(route('employer.dashboard').'#payroll')->withErrors($validator, self::ERRORS)->withInput($input);
        }

        $from = Carbon::createFromFormat('Y-m-d', $input['from'], $zone)->startOfDay();
        $to = Carbon::createFromFormat('Y-m-d', $input['to'], $zone)->endOfDay();

        // Built before the response, so the rows come from this request's database (a sandbox's, inside one).
        $rows = self::rows(Employer::orderBy('id')->first(), $from, $to);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, self::HEADERS, escape: '');

            foreach ($rows as $row) {
                fputcsv($out, array_map(RedemptionsExportController::cell(...), $row), escape: '');
            }

            fclose($out);
        }, 'local-goods-payroll-'.$input['from'].'-to-'.$input['to'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Each allocation in the range, grouped by employee (lowest number first,
     * then oldest first), a total row after each employee and a grand total.
     *
     * @return list<list<string>>
     */
    public static function rows(?Employer $employer, Carbon $from, Carbon $to): array
    {
        if ($employer === null) {
            return [['Grand total', '', '', '', Money::toDollars(0)]];
        }

        /** @var Collection<int, CreditAllocation> $allocations */
        $allocations = CreditAllocation::with('employee')
            ->where('employer_id', $employer->id)
            ->whereBetween('allocated_at', [$from->copy()->utc(), $to->copy()->utc()])
            ->orderBy('employee_id')->orderBy('allocated_at')->orderBy('id')
            ->get();

        $rows = [];
        foreach ($allocations->groupBy('employee_id') as $given) {
            $employee = $given->first()->employee;
            foreach ($given as $allocation) {
                $rows[] = [
                    $employee->initials(),
                    $employee->publicNumber(),
                    $allocation->allocated_at->copy()->setTimezone(config('fraud.timezone'))->format('Y-m-d'),
                    ($allocation->occasion ?? Occasion::Monthly)->label(),
                    Money::toDollars($allocation->amount_cents),
                ];
            }
            $rows[] = ['Total for '.$employee->initials(), $employee->publicNumber(), '', '', Money::toDollars((int) $given->sum('amount_cents'))];
        }
        $rows[] = ['Grand total', '', '', '', Money::toDollars((int) $allocations->sum('amount_cents'))];

        return $rows;
    }
}
