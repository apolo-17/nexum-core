<x-filament-panels::page>

    {{-- ================================================================ --}}
    {{-- TOOLBAR: leyenda de colores + acciones                           --}}
    {{-- ================================================================ --}}
    <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:10px 20px;box-shadow:0 1px 3px rgba(0,0,0,.07);">

        {{-- Leyenda de fuentes --}}
        <div style="display:flex;flex-wrap:wrap;align-items:center;gap:16px;font-size:12px;color:#6b7280;">
            <span style="font-weight:600;color:#374151;">Fuentes:</span>

            <span style="display:inline-flex;align-items:center;gap:5px;">
                <span style="display:inline-block;width:14px;height:14px;border-radius:2px;background:#fef9c3;border-bottom:2px solid #ca8a04;"></span>
                MUA / SE
            </span>

            <span style="display:inline-flex;align-items:center;gap:5px;">
                <span style="display:inline-block;width:14px;height:14px;border-radius:2px;background:#dcfce7;border-bottom:2px solid #16a34a;"></span>
                Extracción IA
            </span>

            <span style="display:inline-flex;align-items:center;gap:5px;">
                <span style="display:inline-block;width:14px;height:14px;border-radius:2px;background:#ede9fe;border-bottom:2px solid #7c3aed;"></span>
                Datos del socio
            </span>

            <span style="display:inline-flex;align-items:center;gap:5px;">
                <span style="display:inline-block;width:14px;height:14px;border-radius:2px;background:#e0f2fe;border-bottom:2px solid #0284c7;"></span>
                Captura manual
            </span>

            <span style="color:#d1d5db;">|</span>
            <span>Haz clic en cualquier campo resaltado para editarlo.</span>
        </div>

        {{-- Acciones del toolbar: Descargar .docx + Guardar cambios --}}
        <div style="display:flex;align-items:center;gap:8px;">

            {{-- Descargar .docx — llama a generateDocx() en Livewire --}}
            <button
                wire:click="generateDocx"
                wire:loading.attr="disabled"
                wire:target="generateDocx"
                style="display:inline-flex;align-items:center;gap:6px;background:#fff;color:#374151;font-size:13px;font-weight:500;padding:7px 14px;border-radius:8px;border:1px solid #d1d5db;cursor:pointer;transition:opacity .15s;"
            >
                <span wire:loading.remove wire:target="generateDocx" style="display:inline-flex;align-items:center;gap:6px;">
                    <svg style="width:14px;height:14px;flex-shrink:0;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16v-8m0 8l-3-3m3 3l3-3M4 20h16"/></svg>
                    Descargar .docx
                </span>
                <span wire:loading wire:target="generateDocx" style="display:inline-flex;align-items:center;gap:6px;">
                    <svg style="width:14px;height:14px;flex-shrink:0;animation:spin 1s linear infinite;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 12a8 8 0 018-8v4l3-3-3-3v4a10 10 0 100 10h-2a8 8 0 01-8-8z"/></svg>
                    Generando…
                </span>
            </button>

            {{-- Guardar cambios — despacha evento que captura el Alpine component --}}
            <button
                onclick="window.dispatchEvent(new Event('acta-save'))"
                style="display:inline-flex;align-items:center;gap:6px;background:#7c3aed;color:#fff;font-size:13px;font-weight:600;padding:8px 16px;border-radius:8px;border:none;cursor:pointer;box-shadow:0 1px 3px rgba(0,0,0,.15);"
                title="Ctrl+S"
            >
                <svg style="width:14px;height:14px;flex-shrink:0;" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                Guardar cambios
            </button>
        </div>
    </div>

    {{-- ================================================================ --}}
    {{-- DOCUMENTO EDITABLE                                               --}}
    {{-- ================================================================ --}}
    <div
        id="acta-editor-root"
        x-data="actaEditor()"
        @acta-save.window="save()"
        @keydown.ctrl.s.window.prevent="save()"
        @keydown.meta.s.window.prevent="save()"
        @open-download-url.window="window.open($event.detail.url, '_blank')"
        style="border-radius:12px;border:1px solid #d1d5db;background:#fff;box-shadow:0 4px 20px rgba(0,0,0,.10),0 1px 4px rgba(0,0,0,.06);"
    >
        <div id="acta-doc" class="mx-auto max-w-3xl px-8 py-6">
            @include('filament.acta.render-document', [
                'data'     => $templateData,
                'editable' => true,
            ])
        </div>

        {{-- Barra inferior pegajosa --}}
        <div style="position:sticky;bottom:0;z-index:10;display:flex;align-items:center;justify-content:space-between;border-top:1px solid #e5e7eb;background:rgba(255,255,255,.95);backdrop-filter:blur(6px);padding:10px 24px;">
            <span style="font-size:12px;color:#9ca3af;" x-text="statusText"></span>

            <div style="display:flex;align-items:center;gap:8px;">
                <a
                    href="{{ $this->getResource()::getUrl('view', ['record' => $this->getRecord()]) }}"
                    style="font-size:13px;color:#6b7280;text-decoration:none;padding:7px 12px;border-radius:8px;border:1px solid #e5e7eb;"
                >
                    ← Volver
                </a>

                {{-- Descargar .docx (repetido en barra inferior para acceso sin scrollear) --}}
                <button
                    wire:click="generateDocx"
                    wire:loading.attr="disabled"
                    wire:target="generateDocx"
                    style="display:inline-flex;align-items:center;gap:6px;background:#fff;color:#374151;font-size:13px;font-weight:500;padding:7px 14px;border-radius:8px;border:1px solid #d1d5db;cursor:pointer;transition:opacity .15s;"
                >
                    <span wire:loading.remove wire:target="generateDocx" style="display:inline-flex;align-items:center;gap:6px;">
                        <svg style="width:13px;height:13px;flex-shrink:0;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16v-8m0 8l-3-3m3 3l3-3M4 20h16"/></svg>
                        .docx
                    </span>
                    <span wire:loading wire:target="generateDocx">Generando…</span>
                </button>

                <button
                    @click="save()"
                    :disabled="saving"
                    style="display:inline-flex;align-items:center;gap:6px;background:#7c3aed;color:#fff;font-size:13px;font-weight:600;padding:8px 16px;border-radius:8px;border:none;cursor:pointer;box-shadow:0 1px 3px rgba(0,0,0,.15);transition:opacity .15s;"
                    :style="saving ? 'opacity:.6;cursor:not-allowed' : ''"
                >
                    <svg style="width:14px;height:14px;flex-shrink:0;" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    <span x-text="saving ? 'Guardando…' : 'Guardar cambios'"></span>
                </button>
            </div>
        </div>
    </div>

    <style>
    @keyframes spin { to { transform: rotate(360deg); } }
    </style>

    <script>
    /**
     * Alpine.js component that drives the inline acta editor.
     *
     * On save() it walks every [data-field] span inside #acta-doc, collects the
     * trimmed innerText, and dispatches to the Livewire saveFields() method via
     * the $wire magic property (Livewire v3 + Alpine v3 standard API).
     *
     * The @open-download-url.window listener (on #acta-editor-root) handles the
     * browser event dispatched by generateDocx() after R2 upload, opening the
     * presigned URL in a new tab.
     *
     * Registered on window so Alpine can resolve "actaEditor()" via x-data.
     */
    window.actaEditor = function actaEditor() {
        return {
            saving: false,
            statusText: 'Edita cualquier campo resaltado para comenzar.',

            init() {
                /**
                 * Live-sync: when the user edits any [data-field] span, propagate
                 * the new text to every other span with the same data-field key so
                 * that fields appearing more than once (e.g. company name in the
                 * header and in Clause PRIMERA) stay consistent while editing.
                 */
                const doc = document.getElementById('acta-doc');
                if (!doc) return;

                doc.addEventListener('input', (e) => {
                    if (!e.target.matches('[data-field]')) return;
                    const key   = e.target.dataset.field;
                    const value = e.target.innerText;
                    doc.querySelectorAll('[data-field="' + key + '"]').forEach(el => {
                        if (el !== e.target) el.innerText = value;
                    });
                });
            },

            async save() {
                if (this.saving) return;

                // Collect first occurrence of each data-field key (live-sync keeps
                // all instances in sync, so first == last == current value).
                const fields = {};
                const doc = document.getElementById('acta-doc');
                if (doc) {
                    doc.querySelectorAll('[data-field]').forEach(el => {
                        const key = el.dataset.field;
                        if (!(key in fields)) {
                            fields[key] = el.innerText.trim();
                        }
                    });
                }

                if (Object.keys(fields).length === 0) {
                    this.statusText = 'No hay campos editables.';
                    return;
                }

                this.saving = true;
                this.statusText = 'Guardando…';

                try {
                    // $wire is the Livewire v3 magic property injected by Alpine
                    // into every component mounted inside a Livewire template.
                    await this.$wire.saveFields(fields);
                    this.statusText = 'Guardado — ' + new Date().toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' });
                } catch (err) {
                    this.statusText = 'Error al guardar — revisa la consola.';
                    console.error('[actaEditor] saveFields failed:', err);
                } finally {
                    this.saving = false;
                }
            },
        };
    }
    </script>

</x-filament-panels::page>
