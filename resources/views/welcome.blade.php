<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
    <style>
        :root {
            --bg: #f7f5f0;
            --text: #1f2a24;
            --muted: #5b6660;
            --accent: #2f6b4f;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #141a17;
                --text: #eef2ef;
                --muted: #a7b3ac;
                --accent: #7cc39f;
            }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            background: var(--bg);
            color: var(--text);
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            text-align: center;
        }
        main { max-width: 36rem; }
        h1 { font-size: clamp(2.5rem, 8vw, 4rem); margin: 0 0 1rem; letter-spacing: -0.02em; }
        p { font-size: 1.125rem; line-height: 1.6; color: var(--muted); margin: 0 0 2rem; }
        .soon {
            display: inline-block;
            padding: 0.5rem 1.25rem;
            border: 2px solid var(--accent);
            border-radius: 999px;
            color: var(--accent);
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
    </style>
</head>
<body>
    <main>
        <h1>{{ config('app.name') }}</h1>
        <p>An employee rewards program that keeps employer spending in the neighborhood where it does business.</p>
        <span class="soon">Coming Soon</span>
    </main>
</body>
</html>
