{{--
    Document preview modal content. Handles three file types:

      1. Image (jpeg, png, gif, webp)
         Rendered as a native <img> with object-fit: contain so a high-res
         passport photo or ID scan fills the container correctly without
         overflowing horizontally.

      2. PDF
         Rendered via PDF.js so it fills the container width. Chrome's built-in
         PDF viewer ignores #view=FitH when embedded in an iframe, so we render
         directly onto a <canvas>. Multi-page PDFs get prev/next navigation.

      3. Fallback (unknown extension / connection error)
         An iframe is kept as a last-resort renderer. In practice this only fires
         for file types not yet listed in Document::mimeType().

    The outer container height is intentionally limited so the evaluation form
    below remains visible without the page scrolling behind the modal.

    Variables:
      $previewUrl  string                URL to the document served inline (admin.documents.preview).
      $isImage     bool                  True when the file is an image (jpeg, png, gif, webp).
      $isPdf       bool                  True when the file is a PDF.
      $analysis    DocumentAnalysis|null Extracted AI data, or null if not yet analysed.
--}}

{{-- =========================================================================
     CASE 1 — IMAGE
     ========================================================================= --}}
@if ($isImage)
    <div
        class="w-full rounded-lg border border-gray-200 dark:border-gray-700 flex items-center justify-center"
        style="height: 47vh; min-height: 280px; background: #525659;"
    >
        <img
            src="{{ $previewUrl }}"
            alt="Vista previa del documento"
            style="max-width: 100%; max-height: 100%; object-fit: contain; display: block;"
        >
    </div>

{{-- =========================================================================
     CASE 2 — PDF (blob URL en iframe)

     No embebemos $previewUrl directamente porque el edge (Laravel Cloud /
     Cloudflare) responde con X-Frame-Options/CSP que bloquea el framing
     ("rechazó la conexión"). Tampoco usamos PDF.js por CDN (cdnjs puede estar
     bloqueado → se quedaba en "Cargando documento…"). En su lugar descargamos
     el PDF con fetch (same-origin, con cookies, igual que las imágenes que sí
     cargan) y lo mostramos como blob: URL local — a los blob: no les aplica
     X-Frame-Options porque ya no hay respuesta HTTP que filtrar.
     ========================================================================= --}}
@elseif ($isPdf)
    <div
        class="w-full rounded-lg overflow-hidden border border-gray-200 dark:border-gray-700"
        style="height: 47vh; min-height: 280px; background: #525659; position: relative;"
    >
        <iframe
            id="nexum-pdf-frame"
            style="display: none; width: 100%; height: 100%; border: none; background: transparent;"
            title="Vista previa del documento"
        ></iframe>

        <div
            id="nexum-pdf-status"
            style="position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; color: #d1d5db; font-family: system-ui, sans-serif; font-size: .9rem; text-align: center; padding: 1rem;"
        >
            Cargando documento…
        </div>
    </div>

    <script>
    (function () {
        'use strict';

        var previewUrl = @json($previewUrl);
        var frame  = document.getElementById('nexum-pdf-frame');
        var status = document.getElementById('nexum-pdf-status');

        if (!frame || !status) { return; }

        function showError(detail) {
            status.innerHTML = 'No se pudo cargar el documento (' + detail + '). '
                + '<a href="' + previewUrl + '" target="_blank" rel="noopener" style="color:#93c5fd; margin-left:.35rem;">Ábrelo en una pestaña nueva.</a>';
        }

        fetch(previewUrl, { credentials: 'same-origin' })
            .then(function (response) {
                if (!response.ok) { throw new Error('HTTP ' + response.status); }
                return response.blob();
            })
            .then(function (blob) {
                // Si el servidor devolvió una página de error HTML (R2 caído, etc.)
                // en vez del PDF, lo detectamos por el content-type y avisamos.
                if (blob.type && blob.type.indexOf('text/html') === 0) {
                    showError('respuesta HTML, no PDF');
                    return;
                }

                // Asegura el tipo correcto para que el navegador use su visor de PDF.
                var pdfBlob = blob.type === 'application/pdf'
                    ? blob
                    : blob.slice(0, blob.size, 'application/pdf');

                frame.src = URL.createObjectURL(pdfBlob);
                frame.style.display = 'block';
                status.style.display = 'none';
            })
            .catch(function (err) {
                showError(err && err.message ? err.message : 'error de red');
            });
    }());
    </script>

{{-- =========================================================================
     CASE 3 — FALLBACK (unknown type)
     ========================================================================= --}}
@else
    <div
        class="w-full rounded-lg overflow-hidden border border-gray-200 dark:border-gray-700"
        style="height: 47vh; min-height: 280px; background: #525659;"
    >
        <iframe
            src="{{ $previewUrl }}"
            style="display: block; width: 100%; height: 100%; border: none; background: transparent;"
            title="Vista previa del documento"
        ></iframe>
    </div>
@endif

{{-- =========================================================================
     PANEL DE DATOS EXTRAÍDOS POR IA
     Se muestra debajo del documento si ya existe un DocumentAnalysis.
     ========================================================================= --}}
@if (isset($analysis))

    @if ($analysis->analyzed)
        {{-- ✅ Extracción exitosa --}}
        <div style="margin-top: 12px; border: 1px solid #d1fae5; border-radius: 8px; overflow: hidden;">
            <div style="background: #059669; color: #fff; padding: 8px 14px; font-size: 12px; font-weight: 600; display: flex; align-items: center; gap: 6px;">
                <span>✓</span>
                <span>Datos extraídos por IA</span>
                <span style="margin-left: auto; font-weight: 400; opacity: .85; font-size: 11px;">
                    {{ $analysis->updated_at?->format('d/m/Y H:i') }}
                </span>
            </div>
            <div style="padding: 12px 14px; display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; background: #f0fdf4;">

                @if (filled($analysis->document_number))
                <div>
                    <div style="font-size: 10px; color: #6b7280; margin-bottom: 2px; text-transform: uppercase; letter-spacing: .04em;">Número de documento</div>
                    <div style="font-size: 13px; font-weight: 600; color: #111827; font-family: monospace;">{{ $analysis->document_number }}</div>
                </div>
                @endif

                @if (filled($analysis->gender))
                <div>
                    <div style="font-size: 10px; color: #6b7280; margin-bottom: 2px; text-transform: uppercase; letter-spacing: .04em;">Sexo</div>
                    <div style="font-size: 13px; font-weight: 500; color: #111827;">
                        {{ $analysis->gender === 'F' ? 'Femenino' : ($analysis->gender === 'M' ? 'Masculino' : $analysis->gender) }}
                    </div>
                </div>
                @endif

                @if (filled($analysis->nationality))
                <div>
                    <div style="font-size: 10px; color: #6b7280; margin-bottom: 2px; text-transform: uppercase; letter-spacing: .04em;">Nacionalidad</div>
                    <div style="font-size: 13px; font-weight: 500; color: #111827;">{{ ucfirst(strtolower($analysis->nationality)) }}</div>
                </div>
                @endif

                @if ($analysis->birthdate)
                <div>
                    <div style="font-size: 10px; color: #6b7280; margin-bottom: 2px; text-transform: uppercase; letter-spacing: .04em;">Fecha de nacimiento</div>
                    <div style="font-size: 13px; font-weight: 500; color: #111827;">{{ $analysis->birthdate->format('d/m/Y') }}</div>
                </div>
                @endif

                @if (filled($analysis->birthplace))
                <div>
                    <div style="font-size: 10px; color: #6b7280; margin-bottom: 2px; text-transform: uppercase; letter-spacing: .04em;">Lugar de nacimiento</div>
                    <div style="font-size: 13px; font-weight: 500; color: #111827;">{{ $analysis->birthplace }}</div>
                </div>
                @endif

                @if ($analysis->expiry_date)
                <div>
                    <div style="font-size: 10px; color: #6b7280; margin-bottom: 2px; text-transform: uppercase; letter-spacing: .04em;">Vencimiento</div>
                    <div style="font-size: 13px; font-weight: 500; color: #111827;">{{ $analysis->expiry_date->format('d/m/Y') }}</div>
                </div>
                @endif

                @if (filled($analysis->address))
                <div style="grid-column: span 3;">
                    <div style="font-size: 10px; color: #6b7280; margin-bottom: 2px; text-transform: uppercase; letter-spacing: .04em;">Domicilio</div>
                    <div style="font-size: 13px; font-weight: 500; color: #111827;">{{ $analysis->address }}</div>
                </div>
                @endif

                @if (filled($analysis->country_of_residence))
                <div>
                    <div style="font-size: 10px; color: #6b7280; margin-bottom: 2px; text-transform: uppercase; letter-spacing: .04em;">País de residencia</div>
                    <div style="font-size: 13px; font-weight: 500; color: #111827;">{{ $analysis->country_of_residence }}</div>
                </div>
                @endif

                @if (filled($analysis->matrimonial_regime))
                <div>
                    <div style="font-size: 10px; color: #6b7280; margin-bottom: 2px; text-transform: uppercase; letter-spacing: .04em;">Régimen matrimonial</div>
                    <div style="font-size: 13px; font-weight: 500; color: #111827;">
                        {{ $analysis->matrimonial_regime === 'sociedad_conyugal' ? 'Sociedad conyugal' : 'Separación de bienes' }}
                    </div>
                </div>
                @endif

            </div>
        </div>

    @elseif (filled($analysis->error_message))
        {{-- ❌ Extracción fallida --}}
        <div style="margin-top: 12px; border: 1px solid #fecaca; border-radius: 8px; overflow: hidden;">
            <div style="background: #dc2626; color: #fff; padding: 8px 14px; font-size: 12px; font-weight: 600; display: flex; align-items: center; gap: 6px;">
                <span>✗</span>
                <span>Error en la extracción IA</span>
                <span style="margin-left: auto; font-weight: 400; opacity: .85; font-size: 11px;">
                    {{ $analysis->updated_at?->format('d/m/Y H:i') }}
                </span>
            </div>
            <div style="padding: 10px 14px; background: #fef2f2; font-size: 12px; color: #7f1d1d;">
                {{ $analysis->error_message }}
            </div>
        </div>

    @else
        {{-- ⟳ Job en cola o en proceso --}}
        <div style="margin-top: 12px; border: 1px solid #fde68a; border-radius: 8px; overflow: hidden;">
            <div style="background: #d97706; color: #fff; padding: 8px 14px; font-size: 12px; font-weight: 600; display: flex; align-items: center; gap: 6px;">
                <svg class="animate-spin" style="width:13px;height:13px;flex-shrink:0;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle style="opacity:.3" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path style="opacity:.8" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                </svg>
                <span>Extracción en proceso…</span>
            </div>
            <div style="padding: 10px 14px; background: #fffbeb; font-size: 12px; color: #92400e;">
                Claude está analizando el documento. Los datos aparecerán aquí automáticamente cuando finalice.
            </div>
        </div>
    @endif

@else
    {{-- Sin registro de análisis todavía --}}
    <div style="margin-top: 10px; padding: 8px 12px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 12px; color: #9ca3af; text-align: center;">
        Sin datos de extracción IA — se procesará automáticamente al aprobar el documento.
    </div>
@endif
