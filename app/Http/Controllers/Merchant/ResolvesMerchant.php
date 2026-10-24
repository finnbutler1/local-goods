<?php

namespace App\Http\Controllers\Merchant;

use App\Models\Merchant;
use Illuminate\Http\Request;

trait ResolvesMerchant
{
    /** The merchant's own business; a judge sees the demo merchant, read-only. */
    private function merchant(Request $request): Merchant
    {
        $merchant = $request->user()->isReadOnly()
            ? Merchant::where('name', config('demo.accounts.merchant.business'))->first() ?? Merchant::orderBy('id')->first()
            : $request->user()->merchant;

        abort_if($merchant === null, 403, 'No business is linked to this login yet.');

        return $merchant;
    }
}
