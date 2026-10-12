<?php

namespace App\Http\Controllers\Demo;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * Shows the latest Redemption Fraud Detection eval: how often the AI agreed
 * with hand-labeled cases. The numbers come from `php artisan fraud:eval`.
 */
class FraudEvalController extends Controller
{
    public function __invoke(): View
    {
        $path = config('fraud.eval_latest_path');
        $run = is_file($path) ? json_decode(file_get_contents($path), true) : null;

        return view('demo.fraud-eval', ['run' => $run]);
    }
}
