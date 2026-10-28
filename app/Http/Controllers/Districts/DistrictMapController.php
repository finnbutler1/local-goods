<?php

namespace App\Http\Controllers\Districts;

use App\Http\Controllers\Controller;
use App\Models\District;
use App\Models\Merchant;
use App\Services\Districts\DistrictCheck;
use App\Services\Geo\AddressLookup;
use App\Support\Districts\DistrictMap;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The public district map (ADR 0031): the boundary DC publishes, drawn from
 * the stored polygon, the live demo merchants as pins, and "Check an
 * address", which runs DistrictCheck, the same lookup and point-in-polygon
 * rule that enforcement uses. The answer is shown from this request; the
 * typed address is never stored (AddressLookup, origin checker).
 */
class DistrictMapController extends Controller
{
    public function __invoke(Request $request, DistrictCheck $check): View
    {
        $data = $request->validate(['address' => ['nullable', 'string', 'max:200']]);
        $district = District::default();
        $address = trim((string) ($data['address'] ?? ''));

        return view('districts.map', [
            'district' => $district,
            'map' => DistrictMap::for($district),
            'merchants' => Merchant::where('district_id', $district->id)->whereNotNull('district_approved_at')->whereNotNull('live_at')
                ->whereNotNull('latitude')->orderBy('name')->get(),
            'address' => $address,
            'result' => $address === '' ? null : $check->address($district, $address, AddressLookup::ORIGIN_CHECKER),
        ]);
    }
}
