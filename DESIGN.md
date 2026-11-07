---
name: Local Goods
description: Reroute your lunch. A calm, auditable benefits product drawn like a transit map.
colors:
  page: "#f4f6f5"
  surface: "#ffffff"
  band: "#e6ecea"
  ink: "#121820"
  muted: "#4d5666"
  line: "#d3dad8"
  edge: "#7a8494"
  primary: "#0a6b67"
  primary-hover: "#085754"
  primary-pressed: "#064441"
  on-primary: "#ffffff"
  primary-tint: "#e0f0ee"
  accent: "#e2603a"
  on-accent: "#121820"
  accent-ink: "#a83c1b"
  route-2: "#7a3f9a"
  ok: "#196a3e"
  ok-bg: "#e0f1e6"
  hold: "#8a4b00"
  hold-bg: "#fcefd9"
  danger: "#b3261e"
  danger-bg: "#fbe5e3"
  focus: "#5a2d86"
  disabled-ink: "#4d5462"
  disabled-bg: "#dde1e6"
  night: "#121820"
  night-soft: "#1f2832"
  night-line: "#2f3a45"
  on-night: "#edf1f4"
  night-muted: "#a9b2bf"
  night-primary: "#42c4b9"
  night-accent: "#ff8a63"
  night-focus: "#d7b4ff"
typography:
  title:
    fontFamily: "Overpass Variable, ui-sans-serif, system-ui, sans-serif"
    fontSize: "clamp(2rem, 1.3rem + 3.5vw, 3rem)"
    fontWeight: 700
    lineHeight: 1.1
    letterSpacing: "-0.015em"
  headline:
    fontFamily: "Overpass Variable, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.5rem"
    fontWeight: 700
    lineHeight: 1.3
    letterSpacing: "-0.015em"
  subhead:
    fontFamily: "Overpass Variable, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.25rem"
    fontWeight: 600
    lineHeight: 1.35
    letterSpacing: "-0.015em"
  body:
    fontFamily: "Atkinson Hyperlegible Next Variable, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1rem"
    fontWeight: 400
    lineHeight: 1.5
  body-small:
    fontFamily: "Atkinson Hyperlegible Next Variable, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 400
    lineHeight: 1.45
  label:
    fontFamily: "Atkinson Hyperlegible Next Variable, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 600
    lineHeight: 1.25
    letterSpacing: "0.1em"
rounded:
  md: "8px"
  full: "9999px"
spacing:
  card-padding: "20px"
  card-padding-wide: "24px"
  tap-target: "44px"
components:
  button-primary:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.on-primary}"
    rounded: "{rounded.md}"
    padding: "10px 20px"
    height: "44px"
  button-primary-hover:
    backgroundColor: "{colors.primary-hover}"
  button-primary-pressed:
    backgroundColor: "{colors.primary-pressed}"
  button-accent:
    backgroundColor: "{colors.accent}"
    textColor: "{colors.on-accent}"
    rounded: "{rounded.md}"
    padding: "10px 20px"
    height: "44px"
  button-secondary:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.primary}"
    rounded: "{rounded.md}"
    padding: "10px 20px"
    height: "44px"
  button-danger:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.danger}"
    rounded: "{rounded.md}"
    padding: "10px 20px"
    height: "44px"
  card-surface:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.md}"
    padding: "20px"
  card-night:
    backgroundColor: "{colors.night}"
    textColor: "{colors.on-night}"
    rounded: "{rounded.md}"
    padding: "20px"
  badge-ok:
    backgroundColor: "{colors.ok-bg}"
    textColor: "{colors.ok}"
    rounded: "{rounded.full}"
    padding: "2px 10px"
  badge-hold:
    backgroundColor: "{colors.hold-bg}"
    textColor: "{colors.hold}"
    rounded: "{rounded.full}"
    padding: "2px 10px"
  input:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.md}"
    padding: "12px"
---

# Design System: Local Goods

## Overview

**Creative North Star: "The Transit Map"**

Clear civic wayfinding. One teal line runs through the product, one coral stop marks the local place you are sending people to, and everything else stays quiet: pale grey-green paper, white cards held by hairlines, ink-dark text. The interface should read as a calm, credible benefits product to an HR manager within ten seconds, and as a place a tired employee can pay for lunch on a phone without thinking.

Money, fraud-hold and admin screens follow a second rule, **Ledger Desk precision**: the numbers and the rules lead, figures are tabular, tables are plain and the route motif steps back. The motif is wayfinding, not wallpaper; it appears in three places only (section eyebrows, the landing hero's route line, participating merchant cards).

The system is deliberately restrained. It rejects generic AI-product decoration: gradients, glows, emoji, piles of cards and badges, rounded-everything. It also rejects the retired earlier look (forest, cream, paper, amber, sage, the awning).

**Key Characteristics:**
- Teal for the line and the main action; coral used rarely, for the local stop.
- Flat surfaces: hairline rings, no decorative shadows, tonal night panels for depth.
- Overpass for headings and every digit, Atkinson Hyperlegible Next for text.
- Every state visible: hover, pressed (1px down), busy (spinner), unavailable (grey, not see-through).
- 44px tap targets and a 3px focus ring everywhere; dark mode mirrors every token.
- Not yet tried in a real browser with a screen reader or on a real phone.

## Colors

A cool, near-neutral field with one teal and one coral. Light values below; dark mode redefines the same token names (`resources/css/app.css`, two blocks kept identical) and every class switches with the theme.

### Primary
- **Route Teal** (#0a6b67): the line. Primary buttons, links, the route-stop line, the merchant rail. Hover #085754, pressed #064441, tint #e0f0ee for selected and hover backgrounds. Dark mode: #42c4b9.

### Secondary
- **Local Stop Coral** (#e2603a): the one place that matters. Accent button, the local stop dot, current-item marker in menus, small badges. Text on it is ink (#121820). Where coral must be text on a light surface use **Coral Ink** (#a83c1b).

### Tertiary
- **Second Line Plum** (#7a3f9a): a second route line, decoration only; never carries meaning or text.

### Neutral
- **Page Mist** (#f4f6f5): page background.
- **Card White** (#ffffff): cards and fields.
- **Band Sage** (#e6ecea): section bands, neutral badge, menu hover.
- **Ink** (#121820): body text.
- **Muted Slate** (#4d5666): secondary text.
- **Hairline** (#d3dad8): decorative rules and card rings only, never the sole boundary of a control.
- **Control Edge** (#7a8494): control borders (3:1 against the page).

### Status
- **OK** (#196a3e on #e0f1e6), **Hold** (#8a4b00 on #fcefd9), **Danger** (#b3261e on #fbe5e3). Hold is the calm amber of "a person is checking", used for field errors too; danger is reserved for destructive or failed states. Status is never colour alone: pair with words or a mark.
- **Focus Violet** (#5a2d86): the keyboard ring, deliberately outside the teal/coral world. **Disabled** ink #4d5462 on #dde1e6.

### Night panels (dark in both themes)
- Night (#121820), Night Soft (#1f2832), Night Line (#2f3a45), On Night (#edf1f4), Night Muted (#a9b2bf), Night Teal (#42c4b9), Night Coral (#ff8a63), Night Focus (#d7b4ff). Used by the sidebar, footer, tab bar and headline-number cards.

### Named Rules
**The One Local Stop Rule.** Coral marks the local stop and the single call to action that matters most on a screen. If a screen has two coral things, one is wrong.

**The Line Is Not A Boundary Rule.** `line` is decoration. A control's border uses `edge`, so it meets 3:1.

**The Pairs Are Tested Rule.** Any new text/background pair is added to `ColorContrast::PAIRS` and passes in light and dark before it ships.

## Typography

**Display Font:** Overpass Variable (with ui-sans-serif, system-ui)
**Body Font:** Atkinson Hyperlegible Next Variable (with ui-sans-serif, system-ui)

**Character:** Overpass is a sturdy, signage-flavoured grotesque, the voice of the route map; Atkinson Hyperlegible Next is chosen for legibility, the voice of the person reading. Digits 0-9 always come from Overpass (plain zero, tabular), because Atkinson only has a slashed zero. Both are self-hosted; no font server is contacted.

### Hierarchy
- **Title** (700, clamp 2rem to 3rem, line-height 1.1, tracking -0.015em): page titles (`text-title`), 32px on a 320px phone growing to 48px by about 780px.
- **Headline** (700, 1.5rem, 1.3): section headings (`text-2xl`, the most common heading size).
- **Subhead** (600, 1.25rem, 1.35): card and group headings (`text-xl`, `text-lg`).
- **Body** (400, 1rem, 1.5): running text; reading column held to `max-w-3xl`, about 65 to 75 characters.
- **Body small** (400, 0.875rem): the workhorse (`text-sm`, by far the most used size): tables, hints, meta lines. Fields never go below 16px on phones.
- **Label** (600, 0.875rem, +0.1em, uppercase): eyebrows above section headings, always after the route stop.

Headings use `text-wrap: balance`.

### Named Rules
**The Plain Figures Rule.** Money is set with `tabular` (tabular, lining figures) so columns align and zeros read as zeros.

**The No Third Font Rule.** Two families, no more. Hierarchy comes from size and weight, not new faces.

## Layout

A centered main column on a pale page. Reading and form pages use `max-w-3xl` (most common); dashboards, tables and the landing page widen to `max-w-6xl`. Headings, the hero and page titles are centered; body text, lists, tables and forms stay left-aligned.

Signed-in screens sit in the app shell: a fixed 256px night sidebar from 1024px (max five items, the rest in More), a sticky top bar with a translucent page-colour background, and on phones a night tab bar (max four items, the rest in More) with the Feedback button above it. Grouped admin menus come from `<x-nav-groups>`.

Spacing follows the Tailwind 4px scale; cards pad 20px, 24px from 640px up. Phones: every button, select, summary and checkbox row is at least 44px; standalone links use `tap-target`; the app-shell footer clears the tab bar and `env(safe-area-inset-bottom)`. Tables use `<x-rtable>`: ordinary tables from 640px, each row becomes a labelled card below that. Long emails and ids break at `<wbr>` points, never mid-word.

### Named Rules
**The Phone Is The Card Rule.** Design the 320px phone first; desktop is the same page given room.

**The Honesty Line Rule.** Demo, projection and "not measured" labels never sit in anything hidden on phones.

## Elevation & Depth

Flat by default. Cards are white with a 1px hairline ring and a 1px bottom rule (`ring-1 ring-line` plus `0 1px 0 0 line`), so they sit on the page like printed tickets, not floating panels. Depth is tonal: the night sidebar, footer, tab bar and headline-number cards are dark in both themes, and bands (Band Sage) step content back.

### Shadow Vocabulary
- **Hairline card rule** (`box-shadow: 0 1px 0 0 var(--color-line)`): the card's bottom edge.
- **Accent press edge** (`box-shadow: 0 2px 0 0 var(--color-primary-pressed)`): the coral button looks like a keycap; it drops to 1px and moves 1px down on hover.
- **Stop halo** (`box-shadow: 0 0 0 3px var(--color-page)`, or `surface` on cards): a ring that cuts the local stop out of its line.
- **Focus ring** (`outline: 3px solid var(--color-focus)`, offset 2px; Night Focus on night panels): the one strong, always-on indicator.

### Named Rules
**The Flat At Rest Rule.** No decorative shadow, glow or blur. A shadow, when present, is a part (an edge, a halo, a ring), not an atmosphere.

## Shapes

One corner radius, 8px (`rounded-lg`, `rounded-xl`, `rounded-2xl` and `rounded-3xl` all resolve to it through the theme tokens in `app.css`), for cards, panels, fields, menus, toasts and buttons. Fully round pills (`rounded-full`) are only for small status badges, status dots, the spinner, the theme toggle and the menu triggers. Dashed borders mark only "not measured" boxes. Control borders are 2px (`border-2`); card rings are 1px.

The route motif is geometry: a 3px teal line ending in a coral dot (`route-stop`), a 5px line with ink dots and a larger coral local stop (`route-line`), and a 5px teal rail with a coral stop on the left of participating merchant cards (`merchant-rail`, never on candidates). Motion is minimal: 150ms colour transitions, a 1px press, a spinner only when motion is allowed; `prefers-reduced-motion` removes everything else.

### Planned refinements (direction for later passes, not yet built)
- **Route motif steps back** on ledger, Where the Money Is, fraud-hold and admin pages.
- Cut remaining generic decoration (extra cards and badges in the landing "Why it matters" grid, `shadow-lg` on the menu panel).

## Components

### Buttons
Calm, pressable, always at least 44px tall on phones.
- **Shape:** 8px radius (`rounded-lg`), never a pill.
- **Primary (teal):** Route Teal fill, white text, `10px 20px`; hover #085754, pressed #064441 and 1px down. The default action.
- **Accent (coral):** Local Stop Coral with ink text and the press-edge shadow. One per screen at most.
- **Secondary:** white fill, 2px teal border and text; hover tint.
- **Ghost:** teal text, underline on hover. **Danger:** white, 2px danger border and text. **Inverse / inverse-outline** for night panels.
- **States:** unavailable = grey fill and grey text, not see-through, still focusable if `aria-disabled` so its reason can be read. Busy = spinner plus static busy text ("Paying…"), second submit cancelled. Sizes sm, md, lg; sm stays compact only from 640px.

### Cards / Containers
- **Surface:** white, 8px radius, 1px hairline ring, bottom rule, padding 20px (24px from 640px).
- **Night:** dark in both modes, for headline numbers. **Tint** (#e0f0ee) and **Band** (#e6ecea) for emphasis and grouping.
- No nested cards; a card never has a shadow beyond the rule.

### Badges
Small status words, `text-xs` semibold, `2px 10px`, fully round. Tones: neutral (band), primary, ok, hold, danger, accent. Long text uses `:wrap="true"`. Never colour alone.

### Inputs / Fields
- **Style:** white, 2px `muted` border, 8px radius, `12px` padding, at least 16px text on phones; select is redrawn on phones (44px, own chevron).
- **Anatomy (`<x-field>`):** semibold label above, control, muted hint below, error below that with a flag mark (⚑) in Hold colour; control points at `{id}-hint` and `{id}-error`.
- **Focus:** border turns teal plus the 3px focus ring. **Error:** `aria-invalid`, and the first invalid field takes focus. **Disabled:** grey.

### Navigation
- **Sidebar and tab bar:** night panels with Night Teal for the current item; coral dot (`aria-current`) in disclosure menus (`<x-menu>`: Account, More, View as, public Menu).
- **Admin groups:** `<x-nav-groups>` in the sidebar and the phone Menu sheet; groups collapse with JavaScript and show open without it.
- **Top bar:** sticky, translucent page colour, theme toggle (44px round), account menu trigger as a pill.

### Eyebrow (signature)
A small uppercase label with a short teal route line ending in a coral stop, above a section heading (`<x-eyebrow>`). Text colour is Coral Ink on light, Night Coral on night. This is the motif's main everyday appearance.

### Responsive Table
`<x-rtable>` around `<table data-rtable="stack">`. A white table with plain rules from 640px; below that each row is a card with column names on the left and values on the right, the primary cell as the card title. Header row stays for screen readers. On money screens figures are tabular and right-aligned.

### Toast
`<x-flash />`: one toast for `status` (✓), `notice` and `error`; focus moves to it after a redirect, field errors win.

### Logo
Logo A, "The daily loop": a teal route loop with two ink stops and one coral local stop, with the Local Goods wordmark as outlines. `on-dark` for night panels, `mark-only` for tight phone bars. Tagline "Reroute your lunch."

## Do's and Don'ts

### Do:
- **Do** keep teal as the line and main action, and coral as the single local stop.
- **Do** put control borders on `edge` (#7a8494) and decoration on `line`.
- **Do** use Overpass for headings and digits, Atkinson Hyperlegible Next for text, with `tabular` on money.
- **Do** show every state: hover, pressed (1px down), busy, unavailable, focus (3px ring).
- **Do** keep tap targets at 44px and fields at 16px or more on phones.
- **Do** centre the hero, page titles and main column; keep body text, lists, tables and forms left-aligned.
- **Do** let ledger, money, fraud-hold and admin screens lead with numbers and plain tables, with the route motif stepped back.
- **Do** add any new colour pair to `ColorContrast::PAIRS` and keep the two dark blocks in `app.css` identical.
- **Do** use `<x-rtable>`, `<x-breakable>` and `<x-eyebrow>` rather than hand-rolling their patterns.

### Don't:
- **Don't** reintroduce forest, cream, paper, amber, sage or the awning.
- **Don't** add a new font, a new colour world or a new tagline; the Transit lines brand (ADR 0025) is binding.
- **Don't** use gradients, glows, emoji, decorative blur, or heavy shadows; no `shadow-lg` or `shadow-xl` on new work.
- **Don't** add a second coral call to action on a screen, put coral fills behind figures, or use a pill for anything bigger than a badge.
- **Don't** stack cards in cards, or add cards and badges where plain text and a rule will do.
- **Don't** use the route motif beyond eyebrows, the landing route line and participating merchant cards; never put a merchant rail on a candidate.
- **Don't** use `break-all`; break at `<wbr>` or `wrap-anywhere-safe`.
- **Don't** hide demo, projection or honesty lines in anything that is hidden on phones.
- **Don't** convey status by colour alone, and don't remove, merge, hide or rename content or navigation in a design pass: visual changes only.
