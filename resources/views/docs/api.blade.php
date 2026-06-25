<!doctype html>
<html lang="es" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="color-scheme" content="light">
    <title>{{ config('app.name') }} — API Docs</title>

    <script src="https://unpkg.com/@stoplight/elements@8.4.2/web-components.min.js"></script>
    <link rel="stylesheet" href="https://unpkg.com/@stoplight/elements@8.4.2/styles.min.css">

    <style>
        html, body { margin: 0; height: 100%; }
        body { display: flex; flex-direction: column; }

        /* Language switcher bar */
        .lang-bar {
            display: flex;
            align-items: center;
            gap: .75rem;
            padding: .55rem 1rem;
            background: #0f172a;
            color: #e2e8f0;
            font: 500 14px/1.2 ui-sans-serif, system-ui, -apple-system, sans-serif;
            border-bottom: 1px solid #1e293b;
        }
        .lang-bar .brand { font-weight: 700; margin-right: auto; }
        .lang-bar button {
            cursor: pointer;
            border: 1px solid #334155;
            background: transparent;
            color: #cbd5e1;
            padding: .3rem .8rem;
            border-radius: 6px;
            font: inherit;
            transition: background .12s, color .12s, border-color .12s;
        }
        .lang-bar button:hover { border-color: #64748b; color: #f1f5f9; }
        .lang-bar button[aria-pressed="true"] {
            background: #2563eb;
            border-color: #2563eb;
            color: #fff;
        }

        .docs-wrap { flex: 1; min-height: 0; overflow-y: hidden; }
    </style>
</head>
<body>
    <div class="lang-bar">
        <span class="brand">{{ config('app.name') }} · API</span>
        <span id="lang-label">Idioma:</span>
        <button type="button" data-lang="es" aria-pressed="true" onclick="setLang('es')">Español</button>
        <button type="button" data-lang="en" aria-pressed="false" onclick="setLang('en')">English</button>
    </div>

    <div class="docs-wrap">
        <elements-api
            id="docs"
            router="hash"
            layout="responsive"
            tryItCredentialsPolicy="include"
            apiDescriptionUrl="{{ url('docs/api/es.json') }}"
        />
    </div>

    <script>
        const SPECS = {
            es: @json(url('docs/api/es.json')),
            en: @json(url('docs/api/en.json')),
        };
        const LABELS = { es: 'Idioma:', en: 'Language:' };

        // Persist the reader's choice across reloads.
        const STORAGE_KEY = 'nexum-api-docs-lang';

        function setLang(lang) {
            if (! SPECS[lang]) return;

            const docs = document.getElementById('docs');
            // Swapping apiDescriptionUrl re-renders Stoplight Elements in place — no reload.
            docs.setAttribute('apiDescriptionUrl', SPECS[lang]);

            document.documentElement.setAttribute('lang', lang);
            document.getElementById('lang-label').textContent = LABELS[lang];
            document.querySelectorAll('.lang-bar button[data-lang]').forEach((btn) => {
                btn.setAttribute('aria-pressed', String(btn.dataset.lang === lang));
            });

            try { localStorage.setItem(STORAGE_KEY, lang); } catch (e) {}
        }

        // Restore the saved language on load (default: Spanish).
        try {
            const saved = localStorage.getItem(STORAGE_KEY);
            if (saved && saved !== 'es') setLang(saved);
        } catch (e) {}
    </script>
</body>
</html>
