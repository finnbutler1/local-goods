{{-- In every layout's <head>. The server never sets data-theme, so with no
     JavaScript (or no storage) the page follows the system light/dark setting.
     This applies a choice saved by the header toggle before the first paint,
     and marks the page as having JavaScript (menus start closed only then). --}}
<meta name="theme-color" content="#f4f6f5" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0d1216" media="(prefers-color-scheme: dark)">
<script>
    document.documentElement.classList.add('js');
    try {
        var savedTheme = localStorage.getItem('lg-theme');
        if (savedTheme === 'light' || savedTheme === 'dark') document.documentElement.dataset.theme = savedTheme;
    } catch (e) {}
</script>
