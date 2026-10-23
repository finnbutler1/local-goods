# Brand directions (options only)

Step 2 of the October 23 plan: three brand directions for Local Goods, for
Finn to pick from. Nothing here is used by the app; step 3 applies the chosen
direction.

```
open docs/brand/directions.html
```

One self-contained page: inline CSS and SVG, fonts embedded, no network
requests, Light / Dark / System toggle. Three columns on a wide screen,
stacked on a phone.

| Direction | Display font | Text font | Tagline |
|---|---|---|---|
| Golden Triangle | Archivo | IBM Plex Sans | "Spend inside the triangle." |
| Transit lines | Overpass | Atkinson Hyperlegible Next | "Reroute your lunch." |
| Storefront | Fraunces | Public Sans | keep "Spend local. Prove it." |

These directions are original designs. Local Goods is not affiliated with the
Golden Triangle BID or WMATA. All merchants and people shown are the fictional
demo data from `DatabaseSeeder`.

## Files

Each of `triangle/`, `transit/` and `storefront/` holds two logo concepts,
`a` and `b`:

- `logo-a.svg`, `logo-a-dark.svg`: mark plus the "Local Goods" wordmark,
  outlined to paths (no font needed); `-dark` is for dark backgrounds
- `mark-a.svg`: the mark alone, 64×64 grid
- `favicon-a-16.svg`, `favicon-a-32.svg`: drawn on a 16 and 32 pixel grid,
  on their own tile so they read on light and dark tab bars
- `app-icon-a.svg`: 512×512 square, full-bleed background

## Fonts

All six are under the SIL Open Font License 1.1 (texts in `fonts/`), taken
from the `@fontsource-variable` npm packages (version 5.3.0) and embedded as
latin-subset woff2. Tabular figures were checked with fontTools: Archivo,
Overpass, Atkinson Hyperlegible Next and Public Sans have the `tnum` feature;
IBM Plex Sans digits are all the same width by default; Fraunces digits vary,
so Storefront shows money in Public Sans.

## Contrast

Tokens and the pairs to check live in `tools/tokens.json` and are embedded in
the page. To check them by script:

```
php docs/brand/check-contrast.php
```

It reads the token block from `directions.html` and measures every pair with
the app's own `App\Support\ColorContrast::ratio()`: 4.5:1 for all text, 3:1
for focus rings, button edges and borders. It exits non-zero if anything
fails. The contrast tables on the page use the same data and formula.

## Rebuilding

Edit `tools/tokens.json` or `tools/build.py`, then:

```
mkdir -p /tmp/lg-fonts && cd /tmp/lg-fonts
for p in archivo ibm-plex-sans overpass atkinson-hyperlegible-next fraunces public-sans; do
  npm pack @fontsource-variable/$p@5.3.0 && mkdir -p $p && tar xzf fontsource-variable-$p-5.3.0.tgz -C $p
  cp $p/package/LICENSE OFL-$p.txt; cp $p/package/files/*-latin-{standard,wght,full}-normal.woff2 . 2>/dev/null
done
cd -
python3 -m venv /tmp/lg-venv && /tmp/lg-venv/bin/pip install fonttools brotli uharfbuzz
/tmp/lg-venv/bin/python docs/brand/tools/build.py --fonts /tmp/lg-fonts
php docs/brand/check-contrast.php
```

This changes nothing in `package.json`, `resources/` or `public/`.
