<x-app-shell title="Styleguide" :preview="App\Enums\Role::BidAdmin" context="Local Goods design tokens and components">
<div class="space-y-16">
    <header class="max-w-3xl">
        <x-eyebrow class="text-accent-ink">Internal</x-eyebrow>
        <h1 class="mt-1 text-5xl font-semibold text-primary">Styleguide</h1>
        <p class="mt-3 text-lg text-muted">
            Every color token, type style and component in one place. Colors are defined once in
            <code>resources/css/app.css</code>; contrast ratios below are computed from those values, and
            <code>ColorContrastTest</code> fails if an approved pair drops below WCAG AA (4.5:1).
        </p>
        <nav aria-label="Styleguide sections" class="mt-6 flex flex-wrap gap-2">
            @foreach (['colors' => 'Colors', 'type' => 'Type', 'buttons' => 'Buttons', 'cards' => 'Cards', 'forms' => 'Form inputs', 'badges' => 'Badges', 'tables' => 'Tables', 'states' => 'States'] as $id => $label)
                <a href="#{{ $id }}" class="rounded-full bg-surface px-3 py-1 text-sm font-semibold text-primary ring-1 ring-line hover:bg-primary-tint">{{ $label }}</a>
            @endforeach
        </nav>
    </header>

    {{-- Colors --}}
    <section id="colors" aria-labelledby="colors-heading" class="scroll-mt-24">
        <h2 id="colors-heading" class="text-3xl font-semibold text-primary">Colors</h2>
        <p class="mt-1 text-muted">Use the Tailwind class names (<code>bg-primary</code>, <code>text-ink</code>…), never raw hex values in templates.</p>
        <ul class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($tokens as $name => $hex)
                <li class="overflow-hidden rounded-2xl bg-surface ring-1 ring-line/70">
                    <div class="h-24 border-b border-line/70" style="background-color: var(--color-{{ $name }})"></div>
                    <div class="p-4">
                        <p class="font-semibold"><code>--color-{{ $name }}</code></p>
                        <p class="text-sm text-muted"><code>{{ $hex }}</code> · <code>bg-{{ $name }}</code> / <code>text-{{ $name }}</code></p>
                        @if (isset($pairs[$name]))
                            <ul class="mt-3 space-y-1.5" aria-label="Approved text colors on {{ $name }}">
                                @foreach ($pairs[$name] as $pair)
                                    <li class="flex items-center justify-between gap-2 rounded-lg px-2.5 py-1.5 text-sm font-semibold" style="background-color: var(--color-{{ $name }}); color: var(--color-{{ $pair['text'] }})">
                                        <span>{{ $pair['text'] }} text</span>
                                        <span class="tabular">{{ number_format($pair['ratio'], 2) }}:1 {{ $pair['ratio'] >= 7 ? 'AAA' : 'AA' }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @elseif ($name === 'line')
                            <p class="mt-3 text-sm text-muted">Decorative rules only. Never text, and never the only boundary of a control.</p>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </section>

    {{-- Type --}}
    <section id="type" aria-labelledby="type-heading" class="scroll-mt-24">
        <h2 id="type-heading" class="text-3xl font-semibold text-primary">Type</h2>
        <p class="mt-1 text-muted">Headings: Overpass. Body: Atkinson Hyperlegible Next, with digits from Overpass for a plain zero. Both self-hosted from npm (ADR 0025).</p>
        <x-card class="mt-6 space-y-6">
            @foreach ([
                ['Heading 1', 'h1', 'text-5xl sm:text-6xl', 'Spend local. Prove it.'],
                ['Heading 2', 'h2', 'text-4xl', 'Three steps from payroll perk to a busier block.'],
                ['Heading 3', 'h3', 'text-2xl', 'Employees spend them nearby'],
                ['Heading 4', 'h4', 'text-xl', 'Signals sent to the AI'],
            ] as [$label, $tag, $size, $sample])
                <div class="grid gap-2 border-b border-line/70 pb-6 sm:grid-cols-[10rem_1fr]">
                    <p class="text-sm text-muted">{{ $label }} <code>&lt;{{ $tag }}&gt;</code><br><code>{{ $size }}</code></p>
                    {{-- Rendered as <p> so this page keeps a sensible heading outline. --}}
                    <p class="font-display font-semibold text-primary {{ $size }}" style="text-wrap: balance">{{ $sample }}</p>
                </div>
            @endforeach
            <div class="grid gap-2 border-b border-line/70 pb-6 sm:grid-cols-[10rem_1fr]">
                <p class="text-sm text-muted">Eyebrow</p>
                <x-eyebrow class="text-accent-ink">Trust and AI</x-eyebrow>
            </div>
            <div class="grid gap-2 border-b border-line/70 pb-6 sm:grid-cols-[10rem_1fr]">
                <p class="text-sm text-muted">Lead</p>
                <p class="text-xl leading-relaxed">Give your team a monthly perk they can only spend at independent businesses near the office.</p>
            </div>
            <div class="grid gap-2 border-b border-line/70 pb-6 sm:grid-cols-[10rem_1fr]">
                <p class="text-sm text-muted">Body</p>
                <p>A held redemption is never denied automatically: a person reviews it. If the AI check fails for any reason, the redemption is held, never cleared.</p>
            </div>
            <div class="grid gap-2 border-b border-line/70 pb-6 sm:grid-cols-[10rem_1fr]">
                <p class="text-sm text-muted">Small / muted</p>
                <p class="text-sm text-muted">Restores the seeded demo data only.</p>
            </div>
            <div class="grid gap-2 sm:grid-cols-[10rem_1fr]">
                <p class="text-sm text-muted">Money <code>tabular</code></p>
                <p class="tabular font-display text-4xl font-semibold text-primary">$1,120.00 · $13.87</p>
            </div>
        </x-card>
    </section>

    {{-- Buttons --}}
    <section id="buttons" aria-labelledby="buttons-heading" class="scroll-mt-24">
        <h2 id="buttons-heading" class="text-3xl font-semibold text-primary">Buttons</h2>
        <p class="mt-1 text-muted"><code>&lt;x-button variant="…" size="sm|md|lg" href="…"&gt;</code>. Renders a link when given <code>href</code>, otherwise a button. Use one <em>accent</em> per screen.</p>
        <x-card class="mt-6 space-y-6">
            <div class="flex flex-wrap items-center gap-3">
                <x-button variant="accent">Accent</x-button>
                <x-button>Primary</x-button>
                <x-button variant="secondary">Secondary</x-button>
                <x-button variant="ghost">Ghost</x-button>
                <x-button variant="danger">Danger</x-button>
                <x-button disabled>Disabled</x-button>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <x-button size="sm">Small</x-button>
                <x-button>Medium</x-button>
                <x-button size="lg">Large</x-button>
            </div>
        </x-card>
        <x-card tone="night" class="mt-4 flex flex-wrap items-center gap-3">
            <x-button variant="accent">Accent</x-button>
            <x-button variant="inverse">Inverse</x-button>
            <x-button variant="inverse-outline">Inverse outline</x-button>
        </x-card>
    </section>

    {{-- Cards --}}
    <section id="cards" aria-labelledby="cards-heading" class="scroll-mt-24">
        <h2 id="cards-heading" class="text-3xl font-semibold text-primary">Cards</h2>
        <p class="mt-1 text-muted"><code>&lt;x-card tone="surface|night|tint|band" as="section"&gt;</code></p>
        <div class="mt-6 grid gap-4 sm:grid-cols-2">
            @foreach (['surface' => 'Default surface for content.', 'night' => 'The one thing that matters most on a page. Dark in both modes.', 'tint' => 'Gentle positive emphasis.', 'band' => 'Quiet grouping or a band.'] as $tone => $body)
                <x-card :tone="$tone">
                    <p class="font-display text-xl font-semibold">{{ ucfirst($tone) }}</p>
                    <p @class(['mt-1', 'text-night-muted' => $tone === 'night', 'text-muted' => $tone !== 'night'])>{{ $body }}</p>
                </x-card>
            @endforeach
        </div>
    </section>

    {{-- Forms --}}
    <section id="forms" aria-labelledby="forms-heading" class="scroll-mt-24">
        <h2 id="forms-heading" class="text-3xl font-semibold text-primary">Form inputs</h2>
        <p class="mt-1 text-muted">Every control has a visible label. Hints and errors are tied to the control with <code>aria-describedby</code>.</p>
        <x-card class="mt-6 grid gap-6 md:grid-cols-2">
            <x-field name="sg-text" label="Text input" hint="Helper text sits under the field.">
                <input id="sg-text" type="text" placeholder="Jordan Rivera" aria-describedby="sg-text-hint"
                       class="block w-full rounded-xl border-2 border-muted bg-surface px-3 py-3 focus-visible:border-primary">
            </x-field>
            <x-field name="sg-select" label="Select">
                <select id="sg-select" class="block w-full rounded-xl border-2 border-muted bg-surface px-3 py-3 focus-visible:border-primary">
                    <option>Press Room Deli (restaurant)</option>
                    <option>Farragut Grind (cafe)</option>
                </select>
            </x-field>
            <x-field name="sg-error" label="With an error" error="Enter an amount above $0.00.">
                <input id="sg-error" type="text" value="0" aria-invalid="true" aria-describedby="sg-error-error"
                       class="block w-full rounded-xl border-2 border-hold bg-surface px-3 py-3 focus-visible:border-hold">
            </x-field>
            <x-field name="sg-disabled" label="Disabled">
                <input id="sg-disabled" type="text" value="Not editable" disabled
                       class="block w-full cursor-not-allowed rounded-xl border-2 border-muted bg-band px-3 py-3 text-muted">
            </x-field>
            <fieldset class="space-y-2">
                <legend class="font-semibold">Checkbox and radio</legend>
                <label class="flex items-center gap-2"><input type="checkbox" checked class="size-5 accent-primary"> Email me a weekly summary</label>
                <label class="flex items-center gap-2"><input type="radio" name="sg-radio" checked class="size-5 accent-primary"> Monthly allowance</label>
                <label class="flex items-center gap-2"><input type="radio" name="sg-radio" class="size-5 accent-primary"> One-time grant</label>
            </fieldset>
            <x-field name="sg-textarea" label="Textarea">
                <textarea id="sg-textarea" rows="3" class="block w-full rounded-xl border-2 border-muted bg-surface px-3 py-3 focus-visible:border-primary">Reviewed: regular customer, normal lunch.</textarea>
            </x-field>
        </x-card>
    </section>

    {{-- Badges --}}
    <section id="badges" aria-labelledby="badges-heading" class="scroll-mt-24">
        <h2 id="badges-heading" class="text-3xl font-semibold text-primary">Badges</h2>
        <p class="mt-1 text-muted wrap-anywhere"><code>&lt;x-badge tone="neutral|primary|ok|hold|danger|accent"&gt;</code> (add <code>:wrap="true"</code> for long text). Status badges pair color with a word, never color alone.</p>
        <x-card class="mt-6 flex flex-wrap items-center gap-3">
            <x-badge>Neutral</x-badge>
            <x-badge tone="primary">Employee</x-badge>
            <x-badge tone="ok"><span class="size-1.5 rounded-full bg-ok" aria-hidden="true"></span>Approved</x-badge>
            <x-badge tone="hold"><span class="size-1.5 rounded-full bg-hold" aria-hidden="true"></span>Under review</x-badge>
            <x-badge tone="accent">For HR teams</x-badge>
        </x-card>
    </section>

    {{-- Tables --}}
    <section id="tables" aria-labelledby="tables-heading" class="scroll-mt-24">
        <h2 id="tables-heading" class="text-3xl font-semibold text-primary">Tables</h2>
        <p class="mt-1 text-muted">Use a <code>&lt;caption&gt;</code> and <code>scope</code> on header cells. Put every table in <code>&lt;x-rtable label="…"&gt;</code> with <code>&lt;table data-rtable="stack"&gt;</code>: below 640px each row becomes a card. Give every body cell <code>data-label="Column name"</code>, the row's name <code>data-primary</code> and an actions cell <code>data-label=""</code>. Only a short table that fits a 320px phone as it is uses <code>data-rtable="fit"</code>. Long emails and links go in <code>&lt;x-breakable :value="…" /&gt;</code>, so they never split mid-word.</p>
        <x-rtable :card="false" class="mt-6" label="Recent redemptions table">
            <table data-rtable="stack" class="tabular w-full min-w-lg overflow-hidden rounded-2xl bg-surface text-left ring-1 ring-line/70">
                <caption class="px-4 pt-4 pb-2 text-left font-semibold">Recent redemptions (example data)</caption>
                <thead>
                    <tr class="bg-band/60 text-sm text-muted">
                        <th scope="col" class="px-4 py-2 font-semibold">Merchant</th>
                        <th scope="col" class="px-4 py-2 font-semibold">When</th>
                        <th scope="col" class="px-4 py-2 text-right font-semibold">Amount</th>
                        <th scope="col" class="px-4 py-2 font-semibold">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ([['Press Room Deli', 'Oct 9, 12:15 PM', '$12.90', true], ['Triangle Stems', 'Oct 8, 11:40 PM', '$150.00', false], ['Farragut Grind', 'Oct 6, 9:00 AM', '$6.10', true]] as [$m, $w, $a, $ok])
                        <tr class="border-t border-line/70">
                            <th scope="row" data-primary class="px-4 py-3 font-semibold">{{ $m }}</th>
                            <td data-label="When" class="px-4 py-3 text-muted">{{ $w }}</td>
                            <td data-label="Amount" class="px-4 py-3 text-right font-semibold">{{ $a }}</td>
                            <td data-label="Status" class="px-4 py-3">
                                <x-badge :tone="$ok ? 'ok' : 'hold'">{{ $ok ? 'Approved' : 'Under review' }}</x-badge>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-rtable>
    </section>

    {{-- States --}}
    <section id="states" aria-labelledby="states-heading" class="scroll-mt-24 space-y-6">
        <div>
            <h2 id="states-heading" class="text-3xl font-semibold text-primary">States</h2>
            <p class="mt-1 text-muted">What a page says after an action, while it works, when it's empty and when it fails.</p>
        </div>

        <x-card class="space-y-3">
            <h3 class="text-xl font-semibold">Result of an action</h3>
            <p class="text-sm text-muted">Every layout shows <code>&lt;x-flash /&gt;</code> once, as a toast at the top of the screen, from <code>session('status')</code> (it worked), <code>session('notice')</code> (it worked, but something still waits) or <code>session('error')</code> (nothing happened). Say exactly what happened, with the amount and who: employees by initials only. After the page reloads, focus moves to it so screen readers announce it, unless a field has an error, which wins. × or Escape dismisses it; status and notice fade out after 8 seconds, unless reduced motion is on. Errors stay. (Here <code>:floating="false" :focus="false"</code>, as these are only samples.)</p>
            <x-flash status="$50.00 sent to J.R. They can spend it now." :notice="null" :error="null" :floating="false" :focus="false" />
            <x-flash :status="null" notice="Held for a quick review: no money moves until a BID admin decides." :error="null" :floating="false" :focus="false" />
            <x-flash :status="null" :notice="null" error="Nothing changed: this redemption is approved." :floating="false" :focus="false" />
        </x-card>

        <x-card class="space-y-3">
            <h3 class="text-xl font-semibold">Working</h3>
            <p class="text-sm text-muted">Every form that posts has <code>data-busy="…"</code>. When it is sent, the pressed button shows that text, can't be pressed again, and a screen reader hears it. Say what is happening: "Checking with Redemption Fraud Detection…", not "Loading".</p>
            <p class="text-sm text-muted">Every button: pointer, a hover color, pressed (darker and 1px down), loading (spinner, only when motion is allowed, plus the busy text) and unavailable (grey, never see-through). A second press is cancelled and the form's submit buttons are disabled a moment later.</p>
            <div class="flex flex-wrap gap-3">
                <x-button variant="accent">Confirm redemption</x-button>
                <x-button variant="accent" aria-disabled="true" aria-busy="true"><span class="spinner" aria-hidden="true"></span>Checking with Redemption Fraud Detection…</x-button>
                <x-button disabled>Unavailable</x-button>
            </div>
        </x-card>

        <x-card class="border-2 border-dashed border-line ring-0! text-center" padding="px-6 py-10">
            <h3 class="text-xl font-semibold">Empty</h3>
            <p class="mx-auto mt-1 max-w-md text-muted">Never a blank space: say what will appear here and what makes it appear. "No redemptions yet. When an employee spends credits, the redemption shows here."</p>
        </x-card>

        <x-card class="space-y-2">
            <h3 class="text-xl font-semibold">Errors</h3>
            <p class="text-sm text-muted">Field errors use <code>&lt;x-field&gt;</code> (see Form inputs): the control gets <code>aria-invalid="true"</code> and <code>aria-describedby="{id}-error"</code>, and focus starts on the first one. Page errors (403, 404, 419, 429, 500, 503) have their own pages in <code>resources/views/errors</code>: what happened in plain words and where to go next.</p>
        </x-card>
    </section>
    </section>
</div>
</x-app-shell>
