{{--
    "IA" column for the documents table.

    Rendered through a ViewColumn (not TextColumn->html()) so the markup is NOT
    run through Filament's HTML sanitizer — that is what was stripping the inline
    brain <svg> before. Styling is inline (plus the @once keyframes below) so it
    does not depend on Tailwind utilities being compiled into the panel CSS.

    States come from Document::aiAnalysisState(). The table polls periodically, so
    the badge repaints to "Extraído" on its own once the extraction job finishes.
--}}
@once
    <style>
        @keyframes ia-blink { 0%, 100% { opacity: 1; } 50% { opacity: .35; } }
        .ia-blink { animation: ia-blink 1.4s ease-in-out infinite; }
        .ia-dot { width: 3px; height: 3px; border-radius: 9999px; background: #6366f1; animation: ia-blink 1.4s ease-in-out infinite; }
        .ia-pill { display: inline-flex; align-items: center; gap: 6px; padding: 2px 9px; border-radius: 9999px; font-size: 11px; font-weight: 600; white-space: nowrap; line-height: 1.2; }
    </style>
@endonce

@php($state = $getRecord()->aiAnalysisState())

@switch($state)
    @case('done')
        <span class="ia-pill" style="background:#dcfce7;color:#166534;">✓ Extraído</span>
        @break

    @case('failed')
        <span class="ia-pill" style="background:#fee2e2;color:#991b1b;">✗ Error</span>
        @break

    @case('processing')
        <span class="ia-pill" style="background:#eef2ff;color:#4338ca;" title="Extrayendo datos con IA…">
            <svg
                class="ia-blink"
                xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                width="14" height="14"
                fill="none" stroke="currentColor" stroke-width="2"
                stroke-linecap="round" stroke-linejoin="round"
                style="display:block;flex-shrink:0;"
                aria-hidden="true"
            >
                <path d="M9.5 2A2.5 2.5 0 0 1 12 4.5v15a2.5 2.5 0 0 1-4.96-.44 2.5 2.5 0 0 1-2.96-3.08 3 3 0 0 1-.34-5.58 2.5 2.5 0 0 1 1.32-4.24 2.5 2.5 0 0 1 1.98-3A2.5 2.5 0 0 1 9.5 2Z" />
                <path d="M14.5 2A2.5 2.5 0 0 0 12 4.5v15a2.5 2.5 0 0 0 4.96-.44 2.5 2.5 0 0 0 2.96-3.08 3 3 0 0 0 .34-5.58 2.5 2.5 0 0 0-1.32-4.24 2.5 2.5 0 0 0-1.98-3A2.5 2.5 0 0 0 14.5 2Z" />
            </svg>
            <span style="display:inline-flex;align-items:center;gap:2px;">
                <span class="ia-dot" style="animation-delay:0ms;"></span>
                <span class="ia-dot" style="animation-delay:200ms;"></span>
                <span class="ia-dot" style="animation-delay:400ms;"></span>
            </span>
        </span>
        @break

    @default
        <span class="ia-pill" style="background:#f3f4f6;color:#6b7280;font-weight:500;">— Pendiente</span>
@endswitch
