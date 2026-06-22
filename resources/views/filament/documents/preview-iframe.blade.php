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
      $previewUrl  string  URL to the document served inline (admin.documents.preview).
      $isImage     bool    True when the file is an image (jpeg, png, gif, webp).
      $isPdf       bool    True when the file is a PDF.
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
     CASE 2 — PDF (PDF.js)
     ========================================================================= --}}
@elseif ($isPdf)
    <div
        id="nexum-preview-container"
        class="w-full rounded-lg border border-gray-200 dark:border-gray-700"
        style="height: 47vh; min-height: 280px; background: #525659; overflow-y: auto; overflow-x: hidden;"
    >
        {{-- Canvas wrapper — hidden until PDF.js renders the first page --}}
        <div id="nexum-pdf-wrapper" style="display: none; padding: 8px;">
            <canvas id="nexum-pdf-canvas" style="display: block; width: 100%; background: white;"></canvas>
        </div>

        {{-- Shown while PDF.js is loading --}}
        <div
            id="nexum-pdf-loading"
            style="height: 100%; display: flex; align-items: center; justify-content: center; color: #9ca3af; font-family: system-ui, sans-serif; font-size: .9rem;"
        >
            Cargando documento…
        </div>
    </div>

    {{-- Page navigation — shown only for multi-page PDFs --}}
    <div
        id="nexum-pdf-nav"
        style="display: none; justify-content: center; align-items: center; gap: 1rem; padding: .5rem 0; font-size: .8rem; color: #6b7280;"
    >
        <button
            id="nexum-pdf-prev"
            style="padding: 2px 14px; border: 1px solid #d1d5db; border-radius: 4px; background: white; cursor: pointer; color: #374151;"
        >‹</button>
        <span id="nexum-pdf-page-info">Página 1 de 1</span>
        <button
            id="nexum-pdf-next"
            style="padding: 2px 14px; border: 1px solid #d1d5db; border-radius: 4px; background: white; cursor: pointer; color: #374151;"
        >›</button>
    </div>

    <script>
    (function () {
        'use strict';

        const PDFJS_CDN  = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
        const WORKER_CDN = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
        const previewUrl = @json($previewUrl);

        const container = document.getElementById('nexum-preview-container');
        const wrapper   = document.getElementById('nexum-pdf-wrapper');
        const loading   = document.getElementById('nexum-pdf-loading');
        const canvas    = document.getElementById('nexum-pdf-canvas');
        const nav       = document.getElementById('nexum-pdf-nav');
        const prevBtn   = document.getElementById('nexum-pdf-prev');
        const nextBtn   = document.getElementById('nexum-pdf-next');
        const pageInfo  = document.getElementById('nexum-pdf-page-info');

        let pdfDoc    = null;
        let pageNum   = 1;
        let rendering = false;

        function renderPage(num) {
            if (!pdfDoc || rendering) { return; }
            rendering = true;

            pdfDoc.getPage(num).then(function (page) {
                const ctx          = canvas.getContext('2d');
                const containerW   = container.clientWidth - 24;
                const baseViewport = page.getViewport({ scale: 1 });
                const scale        = containerW / baseViewport.width;
                const viewport     = page.getViewport({ scale: scale });

                canvas.width  = viewport.width;
                canvas.height = viewport.height;

                return page.render({ canvasContext: ctx, viewport: viewport }).promise;
            }).then(function () {
                rendering = false;
                pageNum   = num;
                pageInfo.textContent = 'Página ' + num + ' de ' + pdfDoc.numPages;
                prevBtn.disabled     = num <= 1;
                nextBtn.disabled     = num >= pdfDoc.numPages;
            }).catch(function () {
                rendering = false;
            });
        }

        prevBtn.addEventListener('click', function () {
            if (pageNum > 1) { renderPage(pageNum - 1); }
        });

        nextBtn.addEventListener('click', function () {
            if (pdfDoc && pageNum < pdfDoc.numPages) { renderPage(pageNum + 1); }
        });

        function initViewer() {
            if (typeof pdfjsLib === 'undefined') {
                setTimeout(initViewer, 80);
                return;
            }

            pdfjsLib.GlobalWorkerOptions.workerSrc = WORKER_CDN;

            pdfjsLib.getDocument(previewUrl).promise
                .then(function (pdf) {
                    pdfDoc = pdf;

                    loading.style.display = 'none';
                    wrapper.style.display = 'block';

                    if (pdf.numPages > 1) {
                        nav.style.display = 'flex';
                    }

                    renderPage(1);
                })
                .catch(function () {
                    loading.textContent = 'No se pudo cargar el PDF.';
                });
        }

        if (window.__nexumPdfJsLoaded) {
            initViewer();
        } else {
            window.__nexumPdfJsLoaded = true;
            var script    = document.createElement('script');
            script.src    = PDFJS_CDN;
            script.onload = initViewer;
            document.head.appendChild(script);
        }
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
