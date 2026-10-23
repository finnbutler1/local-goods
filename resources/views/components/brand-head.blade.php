{{-- In every layout's <head>: icons (Logo A, docs/brand/transit), theme colors
     and the theme script. The server never sets data-theme, so with no
     JavaScript (or no storage) the page follows the system light/dark setting.
     This applies a choice saved by the header toggle before the first paint,
     and marks the page as having JavaScript (menus start closed only then). --}}
<link rel="icon" href="/favicon.ico" sizes="48x48">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="icon" href="/favicon-32.png" type="image/png" sizes="32x32">
<link rel="icon" href="/favicon-16.png" type="image/png" sizes="16x16">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="manifest" href="/site.webmanifest">
<meta name="theme-color" content="#f4f6f5" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0d1216" media="(prefers-color-scheme: dark)">
<script>
    document.documentElement.classList.add('js');
    try {
        var savedTheme = localStorage.getItem('lg-theme');
        if (savedTheme === 'light' || savedTheme === 'dark') document.documentElement.dataset.theme = savedTheme;
    } catch (e) {}
</script>
