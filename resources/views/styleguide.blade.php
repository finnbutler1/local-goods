<x-app-shell title="Styleguide" user-name="Design system" role="Local only" context="Local Goods design tokens and components">
<div class="space-y-16">
    <header class="max-w-3xl">
        <p class="text-sm font-semibold uppercase tracking-widest text-amber-ink">Internal</p>
        <h1 class="mt-1 text-5xl font-semibold text-forest">Styleguide</h1>
        <p class="mt-3 text-lg text-muted">
            Every color token, type style and component in one place. Colors are defined once in
            <code>resources/css/app.css</code>; contrast ratios below are computed from those values, and
            <code>ColorContrastTest</code> fails if an approved pair drops below WCAG AA (4.5:1).
        </p>
        <nav aria-label="Styleguide sections" class="mt-6 flex flex-wrap gap-2">
            @foreach (['colors' => 'Colors', 'type' => 'Type', 'buttons' => 'Buttons', 'cards' => 'Cards', 'forms' => 'Form inputs', 'badges' => 'Badges', 'tables' => 'Tables'] as $id => $label)
                <a href="#{{ $id }}" class="rounded-full bg-paper px-3 py-1 text-sm font-semibold text-forest ring-1 ring-line hover:bg-forest-tint">{{ $label }}</a>
            @endforeach
        </nav>
    </header>

    {{-- Colors --}}
    <section id="colors" aria-labelledby="colors-heading" class="scroll-mt-24">
        <h2 id="colors-heading" class="text-3xl font-semibold text-forest">Colors</h2>
        <p class="mt-1 text-muted">Use the Tailwind class names (<code>bg-forest</code>, <code>text-ink</code>…), never raw hex values in templates.</p>
        <ul class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($tokens as $name => $hex)
                <li class="overflow-hidden rounded-2xl bg-paper ring-1 ring-line/70">
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
        <h2 id="type-heading" class="text-3xl font-semibold text-forest">Type</h2>
        <p class="mt-1 text-muted">Headings: Fraunces (variable, optical size). Body: Public Sans. Both self-hosted from npm.</p>
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
                    <p class="font-display font-semibold text-forest {{ $size }}" style="text-wrap: balance">{{ $sample }}</p>
                </div>
            @endforeach
            <div class="grid gap-2 border-b border-line/70 pb-6 sm:grid-cols-[10rem_1fr]">
                <p class="text-sm text-muted">Eyebrow</p>
                <p class="text-sm font-semibold uppercase tracking-widest text-amber-ink">Trust and AI</p>
            </div>
            <div class="grid gap-2 border-b border-line/70 pb-6 sm:grid-cols-[10rem_1fr]">
                <p class="text-sm text-muted">Lead</p>
                <p class="text-xl leading-relaxed">Give your team a monthly perk they can only spend at independent businesses near the office.</p>
            </div>
            <div class="grid gap-2 border-b border-line/70 pb-6 sm:grid-cols-[10rem_1fr]">
                <p class="text-sm text-muted">Body</p>
                <p>A held redemption is never rejected automatically: a person reviews it. If the AI check fails for any reason, the redemption is held, never cleared.</p>
            </div>
            <div class="grid gap-2 border-b border-line/70 pb-6 sm:grid-cols-[10rem_1fr]">
                <p class="text-sm text-muted">Small / muted</p>
                <p class="text-sm text-muted">Restores the seeded demo data only.</p>
            </div>
            <div class="grid gap-2 sm:grid-cols-[10rem_1fr]">
                <p class="text-sm text-muted">Money <code>tabular</code></p>
                <p class="tabular font-display text-4xl font-semibold text-forest">$1,120.00 · $13.87</p>
            </div>
        </x-card>
    </section>

    {{-- Buttons --}}
    <section id="buttons" aria-labelledby="buttons-heading" class="scroll-mt-24">
        <h2 id="buttons-heading" class="text-3xl font-semibold text-forest">Buttons</h2>
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
        <x-card tone="forest" class="mt-4 flex flex-wrap items-center gap-3">
            <x-button variant="accent">Accent on forest</x-button>
            <x-button variant="inverse">Inverse</x-button>
            <x-button variant="inverse-outline">Inverse outline</x-button>
        </x-card>
    </section>

    {{-- Cards --}}
    <section id="cards" aria-labelledby="cards-heading" class="scroll-mt-24">
        <h2 id="cards-heading" class="text-3xl font-semibold text-forest">Cards</h2>
        <p class="mt-1 text-muted"><code>&lt;x-card tone="paper|forest|tint|cream" as="section"&gt;</code></p>
        <div class="mt-6 grid gap-4 sm:grid-cols-2">
            @foreach (['paper' => 'Default surface for content.', 'forest' => 'The one thing that matters most on a page.', 'tint' => 'Gentle positive emphasis.', 'cream' => 'Quiet grouping or a band.'] as $tone => $body)
                <x-card :tone="$tone">
                    <p class="font-display text-xl font-semibold">{{ ucfirst($tone) }}</p>
                    <p @class(['mt-1', 'text-sage' => $tone === 'forest', 'text-muted' => $tone !== 'forest'])>{{ $body }}</p>
                </x-card>
            @endforeach
        </div>
    </section>

    {{-- Forms --}}
    <section id="forms" aria-labelledby="forms-heading" class="scroll-mt-24">
        <h2 id="forms-heading" class="text-3xl font-semibold text-forest">Form inputs</h2>
        <p class="mt-1 text-muted">Every control has a visible label. Hints and errors are tied to the control with <code>aria-describedby</code>.</p>
        <x-card class="mt-6 grid gap-6 md:grid-cols-2">
            <x-field name="sg-text" label="Text input" hint="Helper text sits under the field.">
                <input id="sg-text" type="text" placeholder="Jordan Rivera" aria-describedby="sg-text-hint"
                       class="block w-full rounded-xl border-2 border-muted bg-paper px-3 py-3 focus-visible:border-forest">
            </x-field>
            <x-field name="sg-select" label="Select">
                <select id="sg-select" class="block w-full rounded-xl border-2 border-muted bg-paper px-3 py-3 focus-visible:border-forest">
                    <option>Press Room Deli (restaurant)</option>
                    <option>Farragut Grind (cafe)</option>
                </select>
            </x-field>
            <x-field name="sg-error" label="With an error" error="Enter an amount above $0.00.">
                <input id="sg-error" type="text" value="0" aria-invalid="true" aria-describedby="sg-error-error"
                       class="block w-full rounded-xl border-2 border-hold bg-paper px-3 py-3 focus-visible:border-hold">
            </x-field>
            <x-field name="sg-disabled" label="Disabled">
                <input id="sg-disabled" type="text" value="Not editable" disabled
                       class="block w-full cursor-not-allowed rounded-xl border-2 border-muted bg-cream-deep px-3 py-3 text-muted">
            </x-field>
            <fieldset class="space-y-2">
                <legend class="font-semibold">Checkbox and radio</legend>
                <label class="flex items-center gap-2"><input type="checkbox" checked class="size-5 accent-forest"> Email me a weekly summary</label>
                <label class="flex items-center gap-2"><input type="radio" name="sg-radio" checked class="size-5 accent-forest"> Monthly allowance</label>
                <label class="flex items-center gap-2"><input type="radio" name="sg-radio" class="size-5 accent-forest"> One-time grant</label>
            </fieldset>
            <x-field name="sg-textarea" label="Textarea">
                <textarea id="sg-textarea" rows="3" class="block w-full rounded-xl border-2 border-muted bg-paper px-3 py-3 focus-visible:border-forest">Reviewed: regular customer, normal lunch.</textarea>
            </x-field>
        </x-card>
    </section>

    {{-- Badges --}}
    <section id="badges" aria-labelledby="badges-heading" class="scroll-mt-24">
        <h2 id="badges-heading" class="text-3xl font-semibold text-forest">Badges</h2>
        <p class="mt-1 text-muted"><code>&lt;x-badge tone="neutral|forest|ok|hold|amber"&gt;</code>. Status badges pair color with a word, never color alone.</p>
        <x-card class="mt-6 flex flex-wrap items-center gap-3">
            <x-badge>Neutral</x-badge>
            <x-badge tone="forest">Employee</x-badge>
            <x-badge tone="ok"><span class="size-1.5 rounded-full bg-ok" aria-hidden="true"></span>Approved</x-badge>
            <x-badge tone="hold"><span class="size-1.5 rounded-full bg-hold" aria-hidden="true"></span>Under review</x-badge>
            <x-badge tone="amber">For HR teams</x-badge>
        </x-card>
    </section>

    {{-- Tables --}}
    <section id="tables" aria-labelledby="tables-heading" class="scroll-mt-24">
        <h2 id="tables-heading" class="text-3xl font-semibold text-forest">Tables</h2>
        <p class="mt-1 text-muted">Use a <code>&lt;caption&gt;</code> and <code>scope</code> on header cells. Wrap wide tables in <code>overflow-x-auto</code>.</p>
        <div class="mt-6 overflow-x-auto">
            <table class="tabular w-full min-w-lg overflow-hidden rounded-2xl bg-paper text-left ring-1 ring-line/70">
                <caption class="px-4 pt-4 pb-2 text-left font-semibold">Recent redemptions (example data)</caption>
                <thead>
                    <tr class="bg-cream-deep/60 text-sm text-muted">
                        <th scope="col" class="px-4 py-2 font-semibold">Merchant</th>
                        <th scope="col" class="px-4 py-2 font-semibold">When</th>
                        <th scope="col" class="px-4 py-2 text-right font-semibold">Amount</th>
                        <th scope="col" class="px-4 py-2 font-semibold">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ([['Press Room Deli', 'Oct 9, 12:15 PM', '$12.90', true], ['Triangle Stems', 'Oct 8, 11:40 PM', '$150.00', false], ['Farragut Grind', 'Oct 6, 9:00 AM', '$6.10', true]] as [$m, $w, $a, $ok])
                        <tr class="border-t border-line/70">
                            <th scope="row" class="px-4 py-3 font-semibold">{{ $m }}</th>
                            <td class="px-4 py-3 text-muted">{{ $w }}</td>
                            <td class="px-4 py-3 text-right font-semibold">{{ $a }}</td>
                            <td class="px-4 py-3">
                                <x-badge :tone="$ok ? 'ok' : 'hold'">{{ $ok ? 'Approved' : 'Under review' }}</x-badge>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
</div>
</x-app-shell>
