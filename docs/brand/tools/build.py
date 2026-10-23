"""Builds the brand directions preview and logo files.

Writes docs/brand/directions.html (one self-contained page: inline CSS, inline
SVG, fonts embedded) and the SVG files in docs/brand/<direction>/. Tokens and
the contrast pairs come from tokens.json next to this script; the page embeds
them so docs/brand/check-contrast.php checks exactly what the page shows.

Needs fontTools, brotli and uharfbuzz (pip install fonttools brotli uharfbuzz),
plus the six latin woff2 files and their OFL texts in one folder (see
docs/brand/README.md for how to fetch them):

    python docs/brand/tools/build.py --fonts /path/to/fonts

Nothing here is used by the app. Options only (step 2 of the Oct 23 plan).
"""

import argparse
import base64
import html
import io
import json
import pathlib

import uharfbuzz as hb
from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen
from fontTools.ttLib import TTFont

HERE = pathlib.Path(__file__).resolve().parent
BRAND = HERE.parent

FONTS = {
    # css family: (file, weight range, stretch range, credit)
    "LG Archivo": ("archivo-latin-standard-normal.woff2", "100 900", "62% 125%", "Archivo, Omnibus-Type"),
    "LG Plex": ("ibm-plex-sans-latin-wght-normal.woff2", "100 700", None, "IBM Plex Sans, IBM"),
    "LG Overpass": ("overpass-latin-wght-normal.woff2", "100 900", None, "Overpass, Delve Withrington / Red Hat"),
    "LG Atkinson": ("atkinson-hyperlegible-next-latin-wght-normal.woff2", "200 800", None,
                    "Atkinson Hyperlegible Next, Braille Institute"),
    "LG Fraunces": ("fraunces-latin-full-normal.woff2", "100 900", None, "Fraunces, Undercase Type"),
    "LG Public Sans": ("public-sans-latin-wght-normal.woff2", "100 900", None, "Public Sans, USWDS"),
}


# ---------------------------------------------------------------- wordmark

def outline(font_path, text, cap_height, axes):
    """Shapes text with HarfBuzz (kerning included) and returns the glyph
    outlines as one SVG path, scaled so capitals are cap_height tall, with the
    baseline at y=0. Returns (path, width)."""
    tt = TTFont(font_path)
    tt.flavor = None
    raw = io.BytesIO()
    tt.save(raw)
    face = hb.Face(hb.Blob(raw.getvalue()))
    font = hb.Font(face)
    font.set_variations(axes)
    buf = hb.Buffer()
    buf.add_str(text)
    buf.guess_segment_properties()
    hb.shape(font, buf, {"kern": True, "liga": True})

    tt = TTFont(font_path)
    glyphs = tt.getGlyphSet(location=axes)
    scale = cap_height / tt["OS/2"].sCapHeight
    x, parts = 0.0, []
    for info, pos in zip(buf.glyph_infos, buf.glyph_positions):
        pen = SVGPathPen(glyphs, ntos=lambda v: f"{v:.2f}".rstrip("0").rstrip("."))
        glyphs[tt.getGlyphName(info.codepoint)].draw(
            TransformPen(pen, (scale, 0, 0, -scale, x + pos.x_offset * scale, -pos.y_offset * scale)))
        parts.append(pen.getCommands())
        x += pos.x_advance * scale
    return " ".join(p for p in parts if p), x


# ------------------------------------------------------------------ marks
# Each mark is drawn on a 64x64 grid as (tag, attributes, fill role, stroke
# role). Roles are token names, so one drawing serves the page (CSS
# variables, follows light/dark) and the files (fixed colors).

def stripe(x):
    return f"M{x} 12H{x + 12}V26A6 6 0 0 1 {x} 26Z"


MARKS = {
    ("triangle", "a"): [
        ("path", {"d": "M8 56H56L20 8Z"}, "logo-ink", None),
        ("circle", {"cx": 28, "cy": 42, "r": 6}, "logo-accent", None),
    ],
    ("triangle", "b"): [
        ("path", {"d": "M10 8H21V45H56V56H10Z"}, "logo-ink", None),
        ("path", {"d": "M21 8H29L56 36.5V45Z"}, "logo-2", None),
    ],
    ("transit", "a"): [
        ("rect", {"x": 8, "y": 18, "width": 48, "height": 28, "rx": 14, "stroke-width": 6}, None, "logo-ink"),
        ("circle", {"cx": 8, "cy": 32, "r": 4.5}, "text", None),
        ("circle", {"cx": 56, "cy": 32, "r": 4.5}, "text", None),
        ("circle", {"cx": 32, "cy": 18, "r": 7.5}, "logo-accent", None),
    ],
    ("transit", "b"): [
        ("path", {"d": "M34 6V58", "stroke-width": 7, "stroke-linecap": "round"}, None, "logo-2"),
        ("path", {"d": "M6 46H26L42 30H58", "stroke-width": 7, "stroke-linecap": "round",
                  "stroke-linejoin": "round"}, None, "logo-ink"),
        ("circle", {"cx": 34, "cy": 38, "r": 7.5}, "logo-accent", None),
    ],
    ("storefront", "a"): [
        ("rect", {"x": 6, "y": 8, "width": 52, "height": 5, "rx": 2.5}, "logo-ink", None),
        *[("path", {"d": stripe(x), "stroke-width": 2, "stroke-linejoin": "round"},
           "logo-ink" if i % 2 == 0 else "surface", "logo-ink") for i, x in enumerate([8, 20, 32, 44])],
        ("rect", {"x": 12, "y": 36, "width": 40, "height": 20, "rx": 2, "stroke-width": 4}, None, "logo-2"),
        ("rect", {"x": 19, "y": 42, "width": 13, "height": 8, "rx": 1.5}, "logo-accent", None),
    ],
    ("storefront", "b"): [
        ("rect", {"x": 4, "y": 10, "width": 56, "height": 44, "rx": 8}, "logo-ink", None),
        ("rect", {"x": 8.5, "y": 14.5, "width": 47, "height": 35, "rx": 5, "stroke-width": 2}, None, "logo-accent"),
        ("path", {"d": "M17 22V42H27", "stroke-width": 5}, None, "on-primary"),
        ("path", {"d": "M47.7 25.6A10 10 0 1 0 50 32H41", "stroke-width": 5}, None, "on-primary"),
    ],
}

LOGO_NAMES = {
    ("triangle", "a"): ("Coin in the triangle", "The district's shape holding a coin: money that stays in the neighborhood."),
    ("triangle", "b"): ("The closing L", "An L for Local whose open corner closes into the triangle."),
    ("transit", "a"): ("The daily loop", "Home, desk, home, with one local stop added to the route."),
    ("transit", "b"): ("Transfer stop", "Two routes meet, and the transfer point is a local shop."),
    ("storefront", "a"): ("Awning", "A striped awning over a shop window with a small sign lit up."),
    ("storefront", "b"): ("Sign plaque", "A painted shop sign with a framed LG monogram."),
}

WORDMARK = {
    # font file, axes, color role
    "triangle": ("archivo-latin-standard-normal.woff2", {"wght": 800, "wdth": 112}, "text"),
    "transit": ("overpass-latin-wght-normal.woff2", {"wght": 800}, "text"),
    "storefront": ("fraunces-latin-full-normal.woff2", {"opsz": 72, "wght": 700, "SOFT": 100, "WONK": 1}, "logo-ink"),
}


def fmt(v):
    return f"{v:g}" if isinstance(v, float) else str(v)


def draw(elements, color):
    """color(role) -> (attribute name, value) pairs for fill or stroke."""
    out = []
    for tag, attrs, fill, stroke in elements:
        a = " ".join(f'{k}="{fmt(v)}"' for k, v in attrs.items())
        paint = color("fill", fill) + color("stroke", stroke)
        out.append(f"<{tag} {a} {paint}/>")
    return "".join(out)


def hex_color(tokens):
    def color(kind, role):
        if role is None:
            return f'{kind}="none" ' if kind == "fill" else ""
        return f'{kind}="{tokens[role]}" '
    return color


def draw_inline(elements):
    out = []
    for tag, attrs, fill, stroke in elements:
        a = " ".join(f'{k}="{fmt(v)}"' for k, v in attrs.items())
        style = ";".join(s for s in [f"fill:var(--{fill})" if fill else "fill:none",
                                     f"stroke:var(--{stroke})" if stroke else ""] if s)
        out.append(f'<{tag} {a} style="{style}"/>')
    return "".join(out)


def svg_file(view_w, view_h, body, title, w=None, h=None):
    size = f' width="{w}" height="{h}"' if w else ""
    return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {fmt(view_w)} {fmt(view_h)}"{size} '
            f'role="img" aria-label="{html.escape(title)}"><title>{html.escape(title)}</title>{body}</svg>\n')


# ------------------------------------------------- favicons and app icons
# Drawn separately on 16 and 32 pixel grids so lines land on whole pixels.
# Each sits on its own filled tile so it reads on light and dark tab bars.

NAVY, GOLD, CREAM = "#13284a", "#e3b73f", "#f6f4ee"
NIGHT, TEAL, PLUM, CORAL, MIST = "#121820", "#42c4b9", "#c08ae0", "#ff8a63", "#edf1f4"
SHOP_BG, BRICK, SHOP_CREAM, SHOP_INK, MUSTARD = "#fbf6ea", "#a3381f", "#fffdf8", "#1e1a15", "#e4b23e"

FAVICONS = {
    ("triangle", "a", 16): f'<rect width="16" height="16" rx="3" fill="{NAVY}"/><path d="M3 13H13L6 3Z" fill="{GOLD}"/>'
                           f'<circle cx="7.5" cy="10" r="1.6" fill="{NAVY}"/>',
    ("triangle", "a", 32): f'<rect width="32" height="32" rx="6" fill="{NAVY}"/><path d="M5 27H27L11 5Z" fill="{GOLD}"/>'
                           f'<circle cx="15" cy="20" r="3" fill="{NAVY}"/>',
    ("triangle", "b", 16): f'<rect width="16" height="16" rx="3" fill="{NAVY}"/><path d="M3 3H6V10H13V13H3Z" fill="{CREAM}"/>'
                           f'<path d="M6 3H8L13 8.5V10Z" fill="{GOLD}"/>',
    ("triangle", "b", 32): f'<rect width="32" height="32" rx="6" fill="{NAVY}"/><path d="M6 5H11V21H27V27H6Z" fill="{CREAM}"/>'
                           f'<path d="M11 5H15L27 17.5V21Z" fill="{GOLD}"/>',
    # Simplified for 16px (step 3): thicker loop, bigger local stop, no end stops.
    ("transit", "a", 16): f'<rect width="16" height="16" rx="3" fill="{NIGHT}"/>'
                          f'<rect x="2.25" y="5.25" width="11.5" height="8" rx="4" fill="none" stroke="{TEAL}" stroke-width="2.5"/>'
                          f'<circle cx="8" cy="5.25" r="2.75" fill="{CORAL}"/>',
    ("transit", "a", 32): f'<rect width="32" height="32" rx="6" fill="{NIGHT}"/>'
                          f'<rect x="5" y="10" width="22" height="14" rx="7" fill="none" stroke="{TEAL}" stroke-width="3.4"/>'
                          f'<circle cx="5" cy="17" r="2.6" fill="{MIST}"/><circle cx="27" cy="17" r="2.6" fill="{MIST}"/>'
                          f'<circle cx="16" cy="10" r="4.6" fill="{CORAL}"/>',
    ("transit", "b", 16): f'<rect width="16" height="16" rx="3" fill="{NIGHT}"/>'
                          f'<path d="M9 2V14" stroke="{PLUM}" stroke-width="2" stroke-linecap="round"/>'
                          f'<path d="M2 11H6L10 7H14" fill="none" stroke="{TEAL}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
                          f'<circle cx="9" cy="8" r="2.4" fill="{CORAL}"/>',
    ("transit", "b", 32): f'<rect width="32" height="32" rx="6" fill="{NIGHT}"/>'
                          f'<path d="M18 4V28" stroke="{PLUM}" stroke-width="3.6" stroke-linecap="round"/>'
                          f'<path d="M4 22H12L20 14H28" fill="none" stroke="{TEAL}" stroke-width="3.6" stroke-linecap="round" stroke-linejoin="round"/>'
                          f'<circle cx="18" cy="16" r="4.4" fill="{CORAL}"/>',
    ("storefront", "a", 16): f'<rect width="16" height="16" rx="3" fill="{SHOP_BG}"/>'
                             f'<rect x="2" y="2" width="12" height="4.5" fill="{BRICK}"/>'
                             f'<circle cx="4" cy="6.5" r="2" fill="{BRICK}"/><circle cx="12" cy="6.5" r="2" fill="{BRICK}"/>'
                             f'<circle cx="8" cy="6.5" r="1.6" fill="{SHOP_CREAM}" stroke="{BRICK}" stroke-width=".8"/>'
                             f'<rect x="6.4" y="3" width="3.2" height="3.5" fill="{SHOP_CREAM}"/>'
                             f'<rect x="3.75" y="10.25" width="8.5" height="4" rx=".5" fill="none" stroke="{SHOP_INK}" stroke-width="1.5"/>',
    ("storefront", "a", 32): f'<rect width="32" height="32" rx="6" fill="{SHOP_BG}"/>'
                             f'<rect x="4" y="4" width="24" height="9" fill="{BRICK}"/>'
                             f'<circle cx="8" cy="13" r="4" fill="{BRICK}"/><circle cx="24" cy="13" r="4" fill="{BRICK}"/>'
                             f'<circle cx="16" cy="13" r="3.2" fill="{SHOP_CREAM}" stroke="{BRICK}" stroke-width="1.6"/>'
                             f'<rect x="12.8" y="6" width="6.4" height="7" fill="{SHOP_CREAM}"/>'
                             f'<rect x="7.5" y="20.5" width="17" height="8" rx="1" fill="none" stroke="{SHOP_INK}" stroke-width="3"/>'
                             f'<rect x="10.5" y="23" width="5" height="3" rx=".5" fill="{MUSTARD}"/>',
    ("storefront", "b", 16): f'<rect width="16" height="16" rx="3" fill="{BRICK}"/>'
                             f'<path d="M4 4.5V11.5H7" fill="none" stroke="{SHOP_CREAM}" stroke-width="2"/>'
                             f'<path d="M13.1 5.9A3 3 0 1 0 14 8H11.5" fill="none" stroke="{SHOP_CREAM}" stroke-width="1.8"/>',
    ("storefront", "b", 32): f'<rect width="32" height="32" rx="6" fill="{BRICK}"/>'
                             f'<rect x="2.5" y="2.5" width="27" height="27" rx="4" fill="none" stroke="{MUSTARD}" stroke-width="1"/>'
                             f'<path d="M8 9V23H14" fill="none" stroke="{SHOP_CREAM}" stroke-width="3.5"/>'
                             f'<path d="M25.45 11.55A6.3 6.3 0 1 0 27.3 16H21.5" fill="none" stroke="{SHOP_CREAM}" stroke-width="3.5"/>',
}

APP_ICON = {
    # background, role -> color for the mark on that background
    ("triangle", "a"): (NAVY, {"logo-ink": GOLD, "logo-accent": NAVY}),
    ("triangle", "b"): (NAVY, {"logo-ink": CREAM, "logo-2": GOLD}),
    ("transit", "a"): (NIGHT, {"logo-ink": TEAL, "text": MIST, "logo-accent": CORAL}),
    ("transit", "b"): (NIGHT, {"logo-ink": TEAL, "logo-2": PLUM, "logo-accent": CORAL}),
    ("storefront", "a"): (SHOP_BG, None),
    ("storefront", "b"): (SHOP_BG, None),
}


def write_files(tokens, fonts_dir):
    """Writes every SVG file and returns the outlined wordmarks for the page."""
    wordmarks = {}
    for direction, (font_file, axes, role) in WORDMARK.items():
        path, width = outline(fonts_dir / font_file, "Local Goods", 26, axes)
        wordmarks[direction] = (path, width, role)

    for (direction, concept), elements in MARKS.items():
        folder = BRAND / direction
        folder.mkdir(exist_ok=True)
        name = LOGO_NAMES[(direction, concept)][0]
        light, dark = tokens[direction]["light"], tokens[direction]["dark"]
        path, width, role = wordmarks[direction]
        lockup_w = 64 + 14 + width

        def lockup(t):
            return draw(elements, hex_color(t)) + f'<path transform="translate(78 45)" d="{path}" fill="{t[role]}"/>'

        (folder / f"mark-{concept}.svg").write_text(svg_file(64, 64, draw(elements, hex_color(light)),
                                                             f"Local Goods mark: {name}"))
        (folder / f"logo-{concept}.svg").write_text(svg_file(lockup_w, 64, lockup(light), f"Local Goods logo: {name}"))
        (folder / f"logo-{concept}-dark.svg").write_text(svg_file(lockup_w, 64, lockup(dark),
                                                                  f"Local Goods logo: {name} (for dark backgrounds)"))
        for px in (16, 32):
            (folder / f"favicon-{concept}-{px}.svg").write_text(
                svg_file(px, px, FAVICONS[(direction, concept, px)], f"Local Goods favicon: {name}", px, px))
        bg, override = APP_ICON[(direction, concept)]
        icon_tokens = {**light, **(override or {})}
        body = (f'<rect width="512" height="512" fill="{bg}"/>'
                f'<g transform="translate(96 96) scale(5)">{draw(elements, hex_color(icon_tokens))}</g>')
        (folder / f"app-icon-{concept}.svg").write_text(svg_file(512, 512, body, f"Local Goods app icon: {name}", 512, 512))
    return wordmarks


# ------------------------------------------------------------------ page

def luminance(hex_):
    def ch(c):
        c = int(hex_[c:c + 2], 16) / 255
        return c / 12.92 if c <= 0.03928 else ((c + 0.055) / 1.055) ** 2.4
    return 0.2126 * ch(1) + 0.7152 * ch(3) + 0.0722 * ch(5)


def ratio(a, b):
    hi, lo = sorted([luminance(a), luminance(b)], reverse=True)
    return (hi + 0.05) / (lo + 0.05)


def vars_block(tokens):
    return "".join(f"--{k}:{v};" for k, v in tokens.items())


def font_faces(fonts_dir):
    out = []
    for family, (file, weight, stretch, _) in FONTS.items():
        data = base64.b64encode((fonts_dir / file).read_bytes()).decode()
        st = f"font-stretch:{stretch};" if stretch else ""
        out.append(f"@font-face{{font-family:'{family}';src:url(data:font/woff2;base64,{data}) format('woff2');"
                   f"font-weight:{weight};{st}font-style:normal;font-display:swap}}")
    return "\n".join(out)


def theme_css(data):
    """Light by default, dark from the system setting unless the toggle says
    light, dark when the toggle says dark. .force-light/.force-dark pin a tile."""
    css = []
    groups = {f".dir-{k}": v for k, v in data["directions"].items()}
    for sel, modes in groups.items():
        light, dark = vars_block(modes["light"]), vars_block(modes["dark"])
        css.append(f"{sel}{{{light}color-scheme:light}}")
        css.append(f"@media (prefers-color-scheme: dark){{:root:not([data-theme=light]) {sel}{{{dark}color-scheme:dark}}}}")
        css.append(f":root[data-theme=dark] {sel}{{{dark}color-scheme:dark}}")
        css.append(f"{sel} .force-light{{{light}color-scheme:light}}")
        css.append(f"{sel} .force-dark{{{dark}color-scheme:dark}}")
    chrome = {m: "".join(f"--c-{k}:{v};" for k, v in t.items()) for m, t in data["chrome"].items()}
    css.append(f":root{{{chrome['light']}color-scheme:light}}")
    css.append(f"@media (prefers-color-scheme: dark){{:root:not([data-theme=light]){{{chrome['dark']}color-scheme:dark}}}}")
    css.append(f":root[data-theme=dark]{{{chrome['dark']}color-scheme:dark}}")
    return "\n".join(css)


def data_uri(path):
    return "data:image/svg+xml;base64," + base64.b64encode(path.read_bytes()).decode()


E = html.escape

DIRECTIONS = {
    "triangle": {
        "title": "Golden Triangle",
        "tagline": "Spend inside the triangle.",
        "tagline_note": "Names the rule in four words: the credits work here, in this district.",
        "rationale": ("Owning the district's own shape makes Local Goods feel native to downtown DC, and navy with "
                      "gold reads like a serious payments product. Biggest risk: it ties the brand to one district's "
                      "name and must never be mistaken for the BID's own branding, and navy-and-gold can drift "
                      "toward a generic private bank."),
        "eyebrow": "Golden Triangle · Washington, DC",
        "display": "Archivo, expanded",
        "text": "IBM Plex Sans",
        "money_font": "Archivo",
        "fonts": [("Archivo", "Omnibus-Type", "display: width and weight axes; expanded ExtraBold for headlines"),
                  ("IBM Plex Sans", "IBM", "text: digits are all the same width by default")],
        "scale": ["Archivo 125% wide, 800 · 40/42", "Archivo 115% wide, 700 · 24/28", "Archivo 100% wide, 700 · 18/22",
                  "IBM Plex Sans 400 · 16/25", "IBM Plex Sans 400 · 14/20",
                  "IBM Plex Sans 600 (fixed-width digits)"],
        "merchant_note": "Inside the district",
    },
    "transit": {
        "title": "Transit lines",
        "tagline": "Reroute your lunch.",
        "tagline_note": "The commute-desk-commute loop, with one stop added in the neighborhood.",
        "rationale": ("Showing merchants as stops on the route you already take tells the whole story in one picture: "
                      "the same daily loop, rerouted through the neighborhood. Biggest risk: map language invites "
                      "comparison with Metro, so the colors and line style must stay clearly our own, and the line "
                      "motif can get busy on small screens."),
        "eyebrow": "Your daily loop, through the neighborhood",
        "display": "Overpass",
        "text": "Atkinson Hyperlegible Next",
        "money_font": "Overpass",
        "fonts": [("Overpass", "Delve Withrington, Red Hat", "display: inspired by road-sign lettering"),
                  ("Atkinson Hyperlegible Next", "Braille Institute", "text: designed for readers with low vision")],
        "scale": ["Overpass 900 · 40/42", "Overpass 800 · 24/28", "Overpass 700 · 18/22",
                  "Atkinson Hyperlegible Next 400 · 16/25", "Atkinson Hyperlegible Next 400 · 14/20",
                  "Atkinson Hyperlegible Next 700, tabular figures"],
        "merchant_note": "A stop on your lunch loop",
    },
    "storefront": {
        "title": "Storefront",
        "tagline": "Spend local. Prove it.",
        "tagline_note": ("Keep the current line: the awning carries “local”, and the precise UI underneath "
                         "carries “prove it” (ledger, receipts, fraud check)."),
        "rationale": ("Awnings and sign-painted lettering put the corner café first, while the clean, precise UI "
                      "underneath makes it trustworthy enough to handle money. Biggest risk: warm-and-crafty is "
                      "the most common look for buy-local projects, so without discipline it reads as a farmers' "
                      "market flyer instead of a payments product."),
        "eyebrow": "Open · Golden Triangle, DC",
        "display": "Fraunces, soft and wonky",
        "text": "Public Sans",
        "money_font": "Public Sans",
        "fonts": [("Fraunces", "Undercase Type", "display: Soft and Wonk axes give a sign-painter feel; already in the app"),
                  ("Public Sans", "U.S. Web Design System", "text: already in the app; money uses its tabular figures "
                                                            "because Fraunces digits vary in width")],
        "scale": ["Fraunces 700, soft, wonky · 40/42", "Fraunces 600 · 24/28", "Fraunces 600 · 18/22",
                  "Public Sans 400 · 16/25", "Public Sans 400 · 14/20", "Public Sans 600, tabular figures"],
        "merchant_note": "Independent, on 17th to K",
    },
}

CHECK = '<svg class="ico" viewBox="0 0 16 16" aria-hidden="true"><path d="M3 8.5 6.5 12 13 4.5" fill="none" stroke="currentColor" stroke-width="2"/></svg>'
CLOCK = ('<svg class="ico" viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="6" fill="none" stroke="currentColor" '
         'stroke-width="1.8"/><path d="M8 4.5V8l2.5 1.5" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>')
INFO = ('<svg class="ico" viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="6.5" fill="none" stroke="currentColor" '
        'stroke-width="1.6"/><path d="M8 7v4.5M8 4.6v.1" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>')
CHEVRON = ('<svg class="ico chev" viewBox="0 0 16 16" aria-hidden="true"><path d="M4 6l4 4 4-4" fill="none" '
           'stroke="currentColor" stroke-width="2"/></svg>')

HERO_DECOR = {
    "triangle": ('<svg class="hero-decor" viewBox="0 0 120 16" preserveAspectRatio="none" aria-hidden="true">'
                 '<defs><pattern id="saw" width="16" height="16" patternUnits="userSpaceOnUse">'
                 '<path d="M0 16 8 0 16 16Z" style="fill:var(--logo-2)"/></pattern></defs>'
                 '<rect width="120" height="16" fill="url(#saw)"/></svg>'),
    "transit": None,
    "storefront": ('<svg class="awning" viewBox="0 0 48 22" preserveAspectRatio="xMinYMin slice" aria-hidden="true">'
                   '<defs><pattern id="awn" width="24" height="22" patternUnits="userSpaceOnUse">'
                   '<path d="M0 0H12V14A6 6 0 0 1 0 14Z" style="fill:var(--primary)"/>'
                   '<path d="M12 0H24V14A6 6 0 0 1 12 14Z" style="fill:var(--surface);stroke:var(--primary)" stroke-width="1.5"/>'
                   '</pattern></defs><rect width="480" height="22" fill="url(#awn)"/></svg>'),
}


def hero(key, d):
    route = ""
    if key == "transit":
        route = ('<ol class="route" aria-label="Your daily loop">'
                 '<li>Home</li><li>Desk</li><li class="local"><strong>Farragut Grind</strong> <span>local stop</span></li>'
                 '<li>Home</li></ol>')
    decor = HERO_DECOR[key] or ""
    top = decor if key == "storefront" else ""
    bottom = decor if key == "triangle" else ""
    return f"""
<div class="hero">{top}
  <div class="hero-body">
    <p class="eyebrow">{E(d['eyebrow'])}</p>
    {route}
    <p class="t-h1 hero-title">You work here. Spend here.</p>
    <p class="hero-sub">Credits from your employer that only work at independent businesses in the district.</p>
    <div class="btn-row"><button type="button" class="btn btn-primary">Start a program</button>
    <button type="button" class="btn btn-secondary">See the wallet</button></div>
  </div>{bottom}
</div>"""


def buttons(key):
    states = [("Normal", ""), ("Hover", " is-hover"), ("Pressed", " is-pressed")]
    rows = []
    for kind, label, busy in (("primary", "Pay $6.75", "Paying…"), ("secondary", "Receipt", "Opening…")):
        cells = [f'<figure><button type="button" class="btn btn-{kind}{c}" tabindex="-1">{label}</button>'
                 f'<figcaption>{n}</figcaption></figure>' for n, c in states]
        cells.append(f'<figure><button type="button" class="btn btn-{kind} is-loading" aria-disabled="true" '
                     f'tabindex="-1"><span class="spinner" aria-hidden="true"></span>{busy}</button>'
                     f'<figcaption>Loading</figcaption></figure>')
        cells.append(f'<figure><button type="button" class="btn btn-{kind}" disabled>{label}</button>'
                     f'<figcaption>Disabled</figcaption></figure>')
        rows.append(f'<p class="sub">{kind.title()}</p><div class="states">{"".join(cells)}</div>')
    live = (f'<p class="sub">Try them (keyboard too)</p><div class="btn-row">'
            f'<button type="button" class="btn btn-primary" data-demo-loading="Paying…">Pay $6.75</button>'
            f'<button type="button" class="btn btn-secondary">Receipt</button></div>')
    return "".join(rows) + live


def merchant(key, d):
    return f"""
<article class="card merchant" id="merchant-{key}" aria-labelledby="merchant-{key}-name">
  <div class="m-head">
    <span class="m-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 9h11v5a5 5 0 0 1-5 5h-1a5 5 0 0 1-5-5Z M16 11h2a2 2 0 0 1 0 4h-2" fill="none" stroke="currentColor" stroke-width="2"/><path d="M8 3v3M11 3v3" stroke="currentColor" stroke-width="2"/></svg></span>
    <div><h4 id="merchant-{key}-name">Farragut Grind</h4>
    <p class="m-meta">Cafe · 1600 block of K St NW</p></div>
  </div>
  <p class="badges"><span class="badge ok">{CHECK}Independent</span><span class="badge neutral">Takes credits</span></p>
  <p class="m-note">{E(d['merchant_note'])}. Fictional demo business.</p>
  <button type="button" class="btn btn-secondary">View menu</button>
</article>"""


def wallet(key):
    return f"""
<article class="card wallet" id="wallet-{key}" aria-labelledby="wallet-{key}-label">
  <p class="w-label" id="wallet-{key}-label">Jordan Rivera's balance</p>
  <p class="w-balance money">$84.50</p>
  <p class="w-sub">Available at independent businesses in the district</p>
  <ul class="w-rows">
    <li><span>Monthly credit · Meridian Policy Group</span><span class="money">+$40.00</span></li>
    <li><span>Farragut Grind</span><span class="money">−$6.75</span></li>
    <li><span>Press Room Deli <span class="badge warn">{CLOCK}Under review</span></span><span class="money">−$12.40</span></li>
    <li><span>Triangle Stems</span><span class="money">−$24.00</span></li>
  </ul>
  <button type="button" class="btn btn-primary">Pay at a shop</button>
</article>"""


def dropdown(key):
    return f"""
<div class="menu-wrap">
  <button type="button" class="btn btn-secondary" aria-expanded="true" aria-controls="menu-{key}" data-menu-button>Account {CHEVRON}</button>
  <ul class="menu" id="menu-{key}">
    <li><a href="#wallet-{key}">My wallet</a></li>
    <li><a href="#merchant-{key}">Find a shop</a></li>
    <li><button type="button" data-close-menu>Send feedback</button></li>
    <li><button type="button" data-close-menu>Log out</button></li>
  </ul>
</div>"""


def palette(modes):
    names = ["bg", "surface", "text", "muted", "primary", "primary-hover", "primary-pressed", "accent",
             "success", "warning", "danger", "focus"]
    rows = "".join(
        f'<tr><th scope="row">{n}</th>'
        f'<td><span class="chip" style="background:{modes["light"][n]}"></span>{modes["light"][n]}</td>'
        f'<td><span class="chip" style="background:{modes["dark"][n]}"></span>{modes["dark"][n]}</td></tr>'
        for n in names)
    return (f'<div class="scroll-region" role="region" tabindex="0" aria-label="Palette">'
            f'<table class="tokens"><thead><tr><th scope="col">Token</th><th scope="col">Light</th>'
            f'<th scope="col">Dark</th></tr></thead><tbody>{rows}</tbody></table></div>')


def contrast(modes, pairs, key):
    rows = []
    for fg, bg, minimum, label in pairs:
        cells = []
        for m in ("light", "dark"):
            r = ratio(modes[m][fg], modes[m][bg])
            ok = r >= minimum
            cells.append(f'<td class="money"><span class="aa" style="color:{modes[m][fg]};background:{modes[m][bg]}" '
                         f'aria-hidden="true">Aa</span> {r:.2f}:1<span class="verdict">{"✓ Pass" if ok else "✗ FAIL"}</span></td>')
        rows.append(f'<tr><th scope="row">{E(label)}<span class="pair">{fg} on {bg} · min {minimum:g}:1</span></th>'
                    f'{"".join(cells)}</tr>')
    return (f'<div class="scroll-region" role="region" tabindex="0" aria-label="Contrast checks, {key}">'
            f'<table class="contrast"><thead><tr><th scope="col">Pair</th><th scope="col">Light</th>'
            f'<th scope="col">Dark</th></tr></thead><tbody>{"".join(rows)}</tbody></table></div>')


def logos(key, wordmarks):
    path, width, role = wordmarks[key]
    out = []
    for concept in ("a", "b"):
        name, why = LOGO_NAMES[(key, concept)]
        els = MARKS[(key, concept)]
        lockup = (f'<svg class="lockup" viewBox="0 0 {64 + 14 + width:.1f} 64" role="img" aria-label="Local Goods">'
                  f'{draw_inline(els)}<path transform="translate(78 45)" d="{path}" style="fill:var(--{role})"/></svg>')
        mark = f'<svg class="mark" viewBox="0 0 64 64" aria-hidden="true">{draw_inline(els)}</svg>'
        folder = BRAND / key
        fav16, fav32 = data_uri(folder / f"favicon-{concept}-16.svg"), data_uri(folder / f"favicon-{concept}-32.svg")
        app = data_uri(folder / f"app-icon-{concept}.svg")
        out.append(f"""
<figure class="logo">
  <figcaption><strong>{concept.upper()} · {E(name)}</strong> {E(why)}</figcaption>
  <div class="logo-tiles">
    <div class="tile force-light"><span class="vh">On light:</span>{lockup}</div>
    <div class="tile force-dark"><span class="vh">On dark:</span>{lockup}</div>
  </div>
  <div class="logo-sizes">
    <span class="size">{mark}<span>Mark</span></span>
    <span class="size"><img src="{fav16}" width="16" height="16" alt="Favicon at 16 pixels"><span>16px</span></span>
    <span class="size"><img src="{fav32}" width="32" height="32" alt="Favicon at 32 pixels"><span>32px</span></span>
    <span class="size"><img class="app" src="{app}" width="72" height="72" alt="App icon"><span>App icon</span></span>
  </div>
  <p class="files">Files: <code>docs/brand/{key}/logo-{concept}.svg</code>, <code>-dark</code>, <code>mark-{concept}</code>,
  <code>favicon-{concept}-16/32</code>, <code>app-icon-{concept}</code> (512)</p>
</figure>""")
    return "".join(out)


def type_specimen(d):
    s = d["scale"]
    return f"""
<div class="specimen">
  <p class="t-h1">You work here.</p><p class="cap">h1 · {E(s[0])}</p>
  <p class="t-h2">Why it matters</p><p class="cap">h2 · {E(s[1])}</p>
  <p class="t-h3">Farragut Grind</p><p class="cap">h3 · {E(s[2])}</p>
  <p class="t-body">Your employer adds credits each month. Spend them at the café, the deli or the florist down the block.</p>
  <p class="cap">body · {E(s[3])}</p>
  <p class="t-small">Cleared Tuesday, 12:40 p.m.</p><p class="cap">small · {E(s[4])}</p>
  <table class="amounts"><caption class="cap">numbers · {E(s[5])}; amounts right-aligned so the decimal points line up</caption>
  <tbody><tr><td>Farragut Grind</td><td class="money">$6.75</td></tr><tr><td>Press Room Deli</td><td class="money">$12.40</td></tr>
  <tr><td>Monthly credit</td><td class="money">$40.00</td></tr><tr><td>Program total</td><td class="money">$1,600.00</td></tr></tbody></table>
  <ul class="font-credits">{"".join(f"<li><strong>{E(n)}</strong> ({E(by)}), SIL Open Font License 1.1: {E(note)}</li>" for n, by, note in d["fonts"])}</ul>
</div>"""


def column(key, data, wordmarks):
    d = DIRECTIONS[key]
    modes = data["directions"][key]
    return f"""
<section class="dir dir-{key}" aria-labelledby="dir-{key}">
  <header class="dir-head">
    <h2 id="dir-{key}">{E(d['title'])}</h2>
    <p class="tagline">“{E(d['tagline'])}”</p>
    <p class="tagline-note">{E(d['tagline_note'])}</p>
    <p class="rationale">{E(d['rationale'])}</p>
  </header>
  <section class="block"><h3>Landing hero</h3>{hero(key, d)}</section>
  <section class="block"><h3>Logo concepts</h3>{logos(key, wordmarks)}</section>
  <section class="block"><h3>Type</h3>{type_specimen(d)}</section>
  <section class="block"><h3>Buttons</h3>{buttons(key)}</section>
  <section class="block"><h3>Merchant card</h3>{merchant(key, d)}</section>
  <section class="block"><h3>Wallet balance</h3>{wallet(key)}</section>
  <section class="block"><h3>Dropdown menu</h3>{dropdown(key)}</section>
  <section class="block"><h3>Demo data label</h3>
    <p class="demo-label">{INFO}Demo data: all people and transactions are fictional.</p></section>
  <section class="block"><h3>Palette</h3>{palette(modes)}</section>
  <section class="block"><h3>Contrast (WCAG AA)</h3>
    <p class="sub">Computed from this page's token data; <code>php docs/brand/check-contrast.php</code> re-checks it. Text needs 4.5:1, focus rings and edges 3:1.</p>
    {contrast(modes, data['pairs'], key)}</section>
</section>"""


CSS = r"""
*,*::before,*::after{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body{margin:0;background:var(--c-bg);color:var(--c-text);font:16px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}
:focus-visible{outline:3px solid var(--c-focus);outline-offset:2px}
code{font:.85em/1.4 ui-monospace,SFMono-Regular,Menlo,monospace;overflow-wrap:anywhere}
.vh{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
.page-head{padding:20px 16px 8px;max-width:1500px;margin:0 auto}
.page-head h1{margin:0;font-size:1.5rem;line-height:1.2}
.page-head p{margin:.5rem 0 0;color:var(--c-muted);max-width:70ch}
.page-head p strong{color:var(--c-text)}
.theme{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:12px}
.theme span{color:var(--c-muted);font-size:.875rem}
.theme button{font:inherit;font-size:.875rem;min-height:36px;padding:0 14px;border-radius:999px;cursor:pointer;
  border:1px solid var(--c-border-strong);background:var(--c-surface);color:var(--c-text)}
.theme button[aria-pressed=true]{background:var(--c-primary);color:var(--c-on-primary);border-color:var(--c-primary)}
.notice{font-size:.875rem}
.directions{display:grid;grid-template-columns:minmax(0,1fr);gap:20px;padding:12px 16px 24px;max-width:1500px;margin:0 auto}
@media (min-width:1200px){.directions{grid-template-columns:repeat(3,minmax(0,1fr))}}
.page-foot{padding:0 16px 32px;max-width:1500px;margin:0 auto;color:var(--c-muted);font-size:.875rem}

.dir{min-width:0;background:var(--bg);color:var(--text);font-family:var(--font-text);border-radius:16px;
  padding:20px 16px;border:1px solid var(--border-strong)}
.dir :focus-visible{outline:3px solid var(--focus);outline-offset:2px}
.dir p{margin:0}
.dir h2{margin:0;font-family:var(--font-display);font-weight:var(--display-weight);font-stretch:var(--display-stretch);
  font-variation-settings:var(--display-fvs);font-size:1.75rem;line-height:1.1;letter-spacing:var(--display-tracking)}
.tagline{margin-top:.5rem!important;font-family:var(--font-display);font-weight:700;font-size:1.25rem;color:var(--accent-text)}
.tagline-note,.rationale,.sub,.cap,.files,.m-meta,.m-note,.w-sub{color:var(--muted)}
.tagline-note{font-size:.875rem;margin-top:.25rem!important}
.rationale{margin-top:.75rem!important;font-size:.9375rem}
.block{margin-top:28px}
.block>h3{margin:0 0 12px;font:700 .8125rem/1.2 var(--font-text);letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}
.sub{font-size:.875rem;margin:12px 0 8px!important}
.cap{font-size:.8125rem;margin:2px 0 14px!important}

/* type */
.t-h1,.t-h2,.t-h3{font-family:var(--font-display);font-weight:var(--display-weight);font-stretch:var(--display-stretch);
  font-variation-settings:var(--display-fvs);letter-spacing:var(--display-tracking);color:var(--text)}
.t-h1{font-size:2.5rem;line-height:1.05}
.t-h2{font-size:1.5rem;line-height:1.15;font-weight:var(--h2-weight);font-stretch:var(--h2-stretch)}
.t-h3{font-size:1.125rem;line-height:1.25;font-weight:var(--h3-weight);font-stretch:100%;letter-spacing:0}
.t-body{font-size:1rem;line-height:1.55}
.t-small{font-size:.875rem;line-height:1.45}
.money{font-variant-numeric:tabular-nums lining-nums;font-weight:600;white-space:nowrap}
table.amounts{border-collapse:collapse;width:100%;max-width:280px}
table.amounts caption{text-align:left;caption-side:bottom}
table.amounts td{padding:3px 0;border-bottom:1px solid var(--border-strong)}
table.amounts td.money{text-align:right}
.font-credits{margin:12px 0 0;padding-left:1.1rem;font-size:.8125rem;color:var(--muted)}
.font-credits strong{color:var(--text)}

/* buttons */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:.5em;min-height:44px;padding:0 18px;
  border:2px solid transparent;border-radius:var(--btn-radius);font-family:var(--font-text);font-size:.9375rem;
  font-weight:var(--btn-weight);letter-spacing:var(--btn-tracking);text-transform:var(--btn-case);line-height:1.1;
  cursor:pointer;text-align:center}
.btn-primary{background:var(--primary);color:var(--on-primary);border-color:var(--primary)}
.btn-primary:hover,.btn-primary.is-hover{background:var(--primary-hover);border-color:var(--primary-hover)}
.btn-primary:active,.btn-primary.is-pressed{background:var(--primary-pressed);border-color:var(--primary-pressed);transform:translateY(1px)}
.btn-secondary{background:var(--surface);color:var(--primary);border-color:var(--border-strong)}
.btn-secondary:hover,.btn-secondary.is-hover{background:var(--bg);border-color:var(--primary)}
.btn-secondary:active,.btn-secondary.is-pressed{background:var(--bg);border-color:var(--primary);box-shadow:inset 0 2px 0 var(--border-strong);transform:translateY(1px)}
.btn[disabled],.btn[disabled]:hover{background:var(--disabled-bg);color:var(--disabled-text);border-color:var(--disabled-bg);cursor:not-allowed;transform:none;box-shadow:none}
.btn.is-loading{cursor:progress}
.btn .ico{width:1em;height:1em}
.spinner{width:1em;height:1em;border-radius:50%;border:2px solid currentColor;border-right-color:transparent;flex:none}
@media (prefers-reduced-motion:no-preference){
  .spinner{animation:spin .8s linear infinite}
  .btn{transition:background-color .12s,border-color .12s,transform .06s}
}
@keyframes spin{to{transform:rotate(360deg)}}
.btn-row{display:flex;flex-wrap:wrap;gap:10px}
.states{display:grid;grid-template-columns:repeat(auto-fill,minmax(128px,1fr));gap:10px}
.states figure{margin:0;display:flex;flex-direction:column;gap:4px}
.states .btn{width:100%;padding:0 10px}
.states figcaption{font-size:.75rem;color:var(--muted)}

/* cards and badges */
.card{background:var(--surface);border-radius:var(--radius);padding:16px;border:1px solid var(--card-edge,transparent);position:relative}
.card h4{margin:0;font-family:var(--font-display);font-weight:var(--h3-weight);font-size:1.125rem;line-height:1.25}
.m-head{display:flex;gap:12px;align-items:center}
.m-icon{flex:none;display:grid;place-items:center;width:44px;height:44px;border-radius:var(--icon-radius);background:var(--bg);color:var(--primary)}
.m-icon svg{width:24px;height:24px}
.m-meta{font-size:.875rem}
.badges{display:flex;flex-wrap:wrap;gap:6px;margin:12px 0 8px!important}
.badge{display:inline-flex;align-items:center;gap:4px;font-size:.8125rem;font-weight:600;padding:2px 8px;border-radius:var(--badge-radius);white-space:nowrap}
.badge .ico{width:14px;height:14px}
.badge.ok{background:var(--success-bg);color:var(--success)}
.badge.warn{background:var(--warning-bg);color:var(--warning)}
.badge.neutral{background:var(--bg);color:var(--text)}
.m-note{font-size:.875rem;margin-bottom:12px!important}
.w-label{font-size:.875rem;font-weight:600;color:var(--muted)}
.w-balance{font-family:var(--font-money);font-size:2.5rem;line-height:1.1;font-weight:800;margin:4px 0 2px!important;font-stretch:var(--money-stretch)}
.w-sub{font-size:.875rem}
.w-rows{list-style:none;margin:14px 0;padding:0;font-size:.9375rem}
.w-rows li{display:flex;justify-content:space-between;gap:12px;align-items:baseline;padding:8px 0;border-top:1px solid var(--border-strong)}
.w-rows li>span:first-child{min-width:0}
.w-rows .badge{margin-left:4px}
.wallet .btn{width:100%}

/* dropdown */
.menu-wrap{display:inline-flex;flex-direction:column;align-items:flex-start;gap:6px;max-width:100%}
.menu-wrap .chev{transition:none}
.menu-wrap [aria-expanded=true] .chev{transform:rotate(180deg)}
.menu{list-style:none;margin:0;padding:6px;min-width:220px;max-width:100%;background:var(--surface);border:1px solid var(--border-strong);
  border-radius:var(--radius);box-shadow:0 8px 24px rgb(0 0 0 / .12)}
.menu[hidden]{display:none}
.menu a,.menu button{display:block;width:100%;text-align:left;padding:10px 12px;border:0;background:none;font:inherit;
  color:var(--text);text-decoration:none;border-radius:calc(var(--radius) / 2);cursor:pointer}
.menu a:hover,.menu button:hover{background:var(--bg);text-decoration:underline}

/* demo label */
.demo-label{display:inline-flex;align-items:flex-start;gap:6px;font-size:.8125rem;font-weight:600;padding:6px 10px;
  background:var(--warning-bg);color:var(--warning);border-radius:var(--badge-radius);border:1px dashed var(--warning)}
.demo-label .ico{width:16px;height:16px;flex:none;margin-top:1px}

/* palette and contrast tables */
.scroll-region{overflow-x:auto;max-width:100%}
table.tokens,table.contrast{border-collapse:collapse;width:100%;font-size:.8125rem}
.tokens th,.tokens td,.contrast th,.contrast td{text-align:left;padding:5px 4px;border-bottom:1px solid var(--border-strong);vertical-align:top}
.tokens thead th,.contrast thead th{color:var(--muted);font-weight:600}
.tokens td{white-space:nowrap;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.75rem}
.chip{display:inline-block;width:14px;height:14px;border-radius:3px;border:1px solid var(--text);vertical-align:-2px;margin-right:6px}
.contrast th[scope=row]{font-weight:500}
.contrast .pair{display:block;color:var(--muted);font-size:.75rem;font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
.contrast td{white-space:nowrap;font-weight:500}
.contrast .verdict{display:block;font-size:.75rem;color:var(--muted)}
.aa{display:inline-block;padding:0 4px;border-radius:3px;font-weight:700;border:1px solid var(--border-strong)}

/* logos */
.logo{margin:0 0 22px}
.logo figcaption{font-size:.875rem;color:var(--muted);margin-bottom:8px}
.logo figcaption strong{color:var(--text)}
.logo-tiles{display:grid;gap:8px}
.tile{background:var(--bg);border-radius:var(--radius);padding:14px;border:1px solid var(--border-strong)}
.lockup{display:block;width:100%;max-width:260px;height:auto}
.logo-sizes{display:flex;flex-wrap:wrap;gap:16px;align-items:flex-end;margin-top:10px}
.size{display:inline-flex;flex-direction:column;align-items:center;gap:4px;font-size:.75rem;color:var(--muted)}
.size .mark{width:48px;height:48px}
.size .app{border-radius:16px}
.files{font-size:.75rem;margin-top:6px!important}

/* hero */
.hero{background:var(--surface);border-radius:var(--radius);overflow:hidden;position:relative;border:1px solid var(--card-edge,transparent)}
.hero-body{padding:22px 18px 24px}
.eyebrow{font-size:.8125rem;font-weight:700;color:var(--accent-text);letter-spacing:var(--eyebrow-tracking);text-transform:var(--eyebrow-case)}
.hero-title{margin:10px 0!important}
.hero-sub{color:var(--muted);margin-bottom:18px!important}

/* --- Golden Triangle --- */
.dir-triangle{--font-display:'LG Archivo',system-ui,sans-serif;--font-text:'LG Plex',system-ui,sans-serif;--font-money:'LG Archivo',sans-serif;
  --display-weight:800;--display-stretch:125%;--display-fvs:normal;--display-tracking:-.01em;--h2-weight:700;--h2-stretch:115%;--h3-weight:700;
  --money-stretch:100%;--radius:4px;--btn-radius:2px;--btn-case:uppercase;--btn-tracking:.06em;--btn-weight:600;--badge-radius:2px;
  --icon-radius:2px;--eyebrow-case:uppercase;--eyebrow-tracking:.1em}
.dir-triangle .block>h3::before{content:"";display:inline-block;width:.7em;height:.6em;margin-right:.5em;background:var(--logo-2);clip-path:polygon(0 100%,100% 100%,0 0)}
.dir-triangle .hero-body{padding-bottom:30px}
.dir-triangle .hero-decor{display:block;width:100%;height:16px}
.dir-triangle .card::after{content:"";position:absolute;top:0;right:0;width:22px;height:22px;background:var(--logo-2);clip-path:polygon(0 0,100% 0,100% 100%)}
.dir-triangle .wallet{border-top:4px solid var(--logo-2)}
.dir-triangle .wallet::after{display:none}
.dir-triangle .menu::after{display:none}

/* --- Transit lines --- */
.dir-transit{--font-display:'LG Overpass',system-ui,sans-serif;--font-text:'LG Atkinson',system-ui,sans-serif;--font-money:'LG Overpass',sans-serif;
  --display-weight:900;--display-stretch:100%;--display-fvs:normal;--display-tracking:-.015em;--h2-weight:800;--h2-stretch:100%;--h3-weight:700;
  --money-stretch:100%;--radius:16px;--btn-radius:999px;--btn-case:none;--btn-tracking:0;--btn-weight:700;--badge-radius:999px;
  --icon-radius:999px;--eyebrow-case:none;--eyebrow-tracking:0}
.dir-transit .block>h3::before{content:"";display:inline-block;width:.6em;height:.6em;border-radius:50%;margin-right:.5em;background:var(--accent);
  box-shadow:-.9em 0 0 -.15em var(--logo-2)}
.dir-transit .block>h3{padding-left:.9em}
.route{list-style:none;margin:16px 0 4px;padding:0;display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr) minmax(0,1.7fr) minmax(0,1fr);font-size:.75rem;color:var(--muted)}
.route li{position:relative;padding-top:22px;padding-right:4px;line-height:1.25}
.route li::before{content:"";position:absolute;top:7px;left:0;right:0;height:5px;background:var(--primary)}
.route li:last-child::before{right:auto;width:10px}
.route li::after{content:"";position:absolute;top:3px;left:0;width:13px;height:13px;border-radius:50%;background:var(--text)}
.route li.local{color:var(--text)}
.route li.local strong{display:block;font-size:.8125rem}
.route li.local span{color:var(--accent-text);font-weight:700}
.route li.local::after{top:-1px;width:21px;height:21px;left:-4px;background:var(--accent);box-shadow:0 0 0 3px var(--surface)}
.dir-transit .merchant{border-left:6px solid var(--primary);padding-left:18px}
.dir-transit .merchant::before{content:"";position:absolute;left:-12px;top:22px;width:18px;height:18px;border-radius:50%;background:var(--accent);border:3px solid var(--surface)}
.dir-transit .wallet{background:linear-gradient(var(--surface),var(--surface)) padding-box;border:2px dashed var(--border-strong)}
.dir-transit .w-rows li{border-top-style:dashed}

/* --- Storefront --- */
.dir-storefront{--font-display:'LG Fraunces',Georgia,serif;--font-text:'LG Public Sans',system-ui,sans-serif;--font-money:'LG Public Sans',sans-serif;
  --display-weight:700;--display-stretch:100%;--display-fvs:'SOFT' 100,'WONK' 1;--display-tracking:-.01em;--h2-weight:600;--h2-stretch:100%;--h3-weight:600;
  --money-stretch:100%;--radius:10px;--btn-radius:8px;--btn-case:none;--btn-tracking:0;--btn-weight:650;--badge-radius:4px;
  --icon-radius:8px;--eyebrow-case:uppercase;--eyebrow-tracking:.12em;--card-edge:var(--border-strong)}
.dir-storefront .t-h1,.dir-storefront .t-h2,.dir-storefront .t-h3,.dir-storefront h2,.dir-storefront .card h4{font-optical-sizing:auto}
.dir-storefront .block>h3{font-family:var(--font-display);text-transform:none;letter-spacing:0;font-size:1rem;color:var(--text);font-weight:600}
.dir-storefront .awning{display:block;width:100%;height:22px}
.dir-storefront .hero-body{margin:0 12px 12px;border:3px solid var(--logo-2);border-top-width:0;border-radius:0 0 6px 6px;box-shadow:inset 0 0 0 4px var(--surface),inset 0 0 0 5px var(--border-strong)}
.dir-storefront .eyebrow{display:inline-block;border:2px solid var(--accent-text);padding:2px 8px;border-radius:4px}
.dir-storefront .btn-primary{box-shadow:inset 0 0 0 2px var(--primary),inset 0 0 0 3px var(--on-primary)}
.dir-storefront .btn-primary[disabled]{box-shadow:none}
.dir-storefront .merchant{padding-top:28px}
.dir-storefront .merchant::before{content:"";position:absolute;top:0;left:0;right:0;height:12px;border-radius:var(--radius) var(--radius) 0 0;
  background:repeating-linear-gradient(90deg,var(--primary) 0 14px,var(--surface) 14px 28px);border-bottom:2px solid var(--primary)}
.dir-storefront .w-balance{letter-spacing:-.01em}
"""

JS = r"""
(function () {
  var root = document.documentElement;
  var buttons = document.querySelectorAll('[data-set-theme]');
  function apply(theme) {
    if (theme === 'system') root.removeAttribute('data-theme'); else root.setAttribute('data-theme', theme);
    buttons.forEach(function (b) { b.setAttribute('aria-pressed', String(b.getAttribute('data-set-theme') === theme)); });
  }
  var saved = 'system';
  try { saved = localStorage.getItem('lg-brand-theme') || 'system'; } catch (e) {}
  if (location.hash === '#check-dark') saved = 'dark';
  if (location.hash === '#check-light' || location.hash === '#check') saved = 'light';
  apply(saved);
  buttons.forEach(function (b) {
    b.addEventListener('click', function () {
      var t = b.getAttribute('data-set-theme');
      apply(t);
      try { localStorage.setItem('lg-brand-theme', t); } catch (e) {}
    });
  });

  // Disclosure menus: Enter/Space toggle, arrows move, Escape closes.
  document.querySelectorAll('[data-menu-button]').forEach(function (btn) {
    var menu = document.getElementById(btn.getAttribute('aria-controls'));
    var items = function () { return Array.prototype.slice.call(menu.querySelectorAll('a,button')); };
    function set(open) { btn.setAttribute('aria-expanded', String(open)); menu.hidden = !open; }
    btn.addEventListener('click', function () { set(btn.getAttribute('aria-expanded') !== 'true'); });
    btn.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') { e.preventDefault(); set(true); items()[0].focus(); }
    });
    menu.addEventListener('keydown', function (e) {
      var list = items(), i = list.indexOf(document.activeElement);
      if (e.key === 'ArrowDown') { e.preventDefault(); list[(i + 1) % list.length].focus(); }
      if (e.key === 'ArrowUp') { e.preventDefault(); list[(i - 1 + list.length) % list.length].focus(); }
      if (e.key === 'Escape') { set(false); btn.focus(); }
    });
    menu.querySelectorAll('[data-close-menu]').forEach(function (b) {
      b.addEventListener('click', function () { set(false); btn.focus(); });
    });
  });

  // Live loading state on the "Try them" buttons.
  document.querySelectorAll('[data-demo-loading]').forEach(function (btn) {
    var label = btn.innerHTML;
    btn.addEventListener('click', function () {
      if (btn.getAttribute('aria-disabled') === 'true') return;
      btn.setAttribute('aria-disabled', 'true');
      btn.classList.add('is-loading');
      btn.innerHTML = '<span class="spinner" aria-hidden="true"></span>' + btn.getAttribute('data-demo-loading');
      setTimeout(function () { btn.innerHTML = label; btn.classList.remove('is-loading'); btn.removeAttribute('aria-disabled'); }, 1500);
    });
  });

  // Phone-width check: opened with #check or #check-dark, the page records
  // whether anything is wider than the window.
  if (location.hash.indexOf('#check') === 0) {
    (document.fonts ? document.fonts.ready : Promise.resolve()).then(function () {
      var wide = [];
      document.querySelectorAll('body *').forEach(function (el) {
        var r = el.getBoundingClientRect();
        if (r.right > root.clientWidth + 0.5 && !el.closest('.scroll-region') && !(el.ownerSVGElement)) wide.push(el.tagName + '.' + el.getAttribute('class'));
      });
      root.setAttribute('data-check', 'viewport=' + root.clientWidth + ' scrollWidth=' + root.scrollWidth +
        ' overflowing=' + wide.length + (wide.length ? ' first=' + wide.slice(0, 3).join('|') : ''));
    });
  }
})();
"""


def page(data, wordmarks, fonts_dir):
    embedded = json.dumps({"pairs": data["pairs"], "directions": data["directions"], "chrome": data["chrome"],
                           "chromePairs": data["chromePairs"]}, indent=1)
    cols = "".join(column(k, data, wordmarks) for k in DIRECTIONS)
    return f"""<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Local Goods brand directions</title>
<meta name="description" content="Three brand directions for Local Goods (options only): Golden Triangle, Transit lines and Storefront.">
<!-- Generated by docs/brand/tools/build.py. Edit tokens.json or build.py, then rebuild. -->
<!-- Fonts embedded below under the SIL Open Font License 1.1; license texts in docs/brand/fonts/. -->
<style>
{font_faces(fonts_dir)}
{theme_css(data)}
{CSS}
</style>
<script type="application/json" id="brand-tokens">{embedded}</script>
</head>
<body>
<header class="page-head">
  <h1>Local Goods: brand directions</h1>
  <p><strong>Options only.</strong> Step 2 of the October 23 plan. Nothing in the app has changed. Pick one direction and one logo; step 3 applies it.</p>
  <p class="notice">These directions are original designs. Local Goods is not affiliated with the Golden Triangle BID or WMATA.
  All merchants and people shown are fictional demo data.</p>
  <div class="theme" role="group" aria-label="Color theme"><span>Theme:</span>
    <button type="button" data-set-theme="light" aria-pressed="false">Light</button>
    <button type="button" data-set-theme="dark" aria-pressed="false">Dark</button>
    <button type="button" data-set-theme="system" aria-pressed="true">System</button>
  </div>
</header>
<main class="directions">{cols}
</main>
<footer class="page-foot">
  <p>Contrast ratios are computed from the token data embedded in this page (WCAG 2 formula) and re-checked by
  <code>php docs/brand/check-contrast.php</code>. Logos are exempt from WCAG contrast but their main color is checked at 3:1 anyway.
  Fonts are embedded latin subsets, all under the SIL Open Font License 1.1. No network requests.</p>
</footer>
<script>{JS}</script>
</body>
</html>
"""


def main():
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--fonts", required=True, type=pathlib.Path, help="folder with the six woff2 files and OFL-*.txt")
    args = parser.parse_args()
    data = json.loads((HERE / "tokens.json").read_text())
    wordmarks = write_files(data["directions"], args.fonts)
    (BRAND / "directions.html").write_text(page(data, wordmarks, args.fonts))
    fonts_out = BRAND / "fonts"
    fonts_out.mkdir(exist_ok=True)
    for lic in sorted(args.fonts.glob("OFL-*.txt")):
        (fonts_out / lic.name).write_text(lic.read_text())
    print("Wrote", BRAND / "directions.html")


if __name__ == "__main__":
    main()
