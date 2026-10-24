<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\MerchantProduct;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A merchant's own product menu (ADR 0023): view it, change a product's
 * name, price or description, take it off the menu or put it back, and
 * add one. Changes apply to new redemptions only; past ones keep the name
 * and price they had. Display only: Redemption Fraud Detection checks the
 * amount, never the items. A judge sees the demo merchant's menu, read-only.
 */
class MenuController extends Controller
{
    use ResolvesMerchant;

    /** Keeps the redeem screen short enough to use on a phone. */
    public const MAX_PRODUCTS = 12;

    public function show(Request $request): View
    {
        $merchant = $this->merchant($request);

        return view('merchant.menu', [
            'merchant' => $merchant,
            'products' => $merchant->products()->get(),
            'readOnly' => $request->user()->isReadOnly(),
            'canAdd' => $merchant->mayHaveProducts() && $merchant->products()->count() < self::MAX_PRODUCTS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $merchant = $this->merchant($request);
        abort_unless($merchant->mayHaveProducts(), 403, 'Only the fictional demo merchants have menus.');

        if ($merchant->products()->count() >= self::MAX_PRODUCTS) {
            return redirect()->to(route('merchant.menu').'#add')->with('error', 'The menu already has '.self::MAX_PRODUCTS.' products, the most it can hold. Take one off the menu or change it instead.');
        }

        $data = $request->validateWithBag('add', $this->rules(), $this->messages());

        $product = $merchant->products()->create([
            ...$this->fields($data),
            'is_available' => true,
            'position' => (int) $merchant->products()->max('position') + 1,
        ]);

        return redirect()->to(route('merchant.menu').'#product-'.$product->id)
            ->with('status', "{$product->name} added to the menu at ".Money::format($product->price_cents).'.');
    }

    public function update(Request $request, MerchantProduct $product): RedirectResponse
    {
        abort_unless($product->merchant_id === $this->merchant($request)->id, 403, 'That product is on another business\'s menu.');

        $data = $request->validateWithBag('product'.$product->id, $this->rules(), $this->messages());
        $product->update([...$this->fields($data), 'is_available' => $request->boolean('is_available')]);

        return redirect()->to(route('merchant.menu').'#product-'.$product->id)->with('status', $product->is_available
            ? "{$product->name} saved: ".Money::format($product->price_cents).', on the menu.'
            : "{$product->name} saved and taken off the menu. Employees won't see it until you put it back.");
    }

    /** @return array<string, list<string>> */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'price' => ['required', 'regex:/^\d{1,4}(\.\d{1,2})?$/', 'not_regex:/^0+(\.0+)?$/'],
            'description' => ['nullable', 'string', 'max:160'],
        ];
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'price.regex' => 'Enter a price in dollars, like 5.75.',
            'price.not_regex' => 'Enter a price greater than zero.',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{name: string, price_cents: int, description: ?string}
     */
    private function fields(array $data): array
    {
        return [
            'name' => trim($data['name']),
            'price_cents' => Money::toCents($data['price']),
            'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
        ];
    }
}
