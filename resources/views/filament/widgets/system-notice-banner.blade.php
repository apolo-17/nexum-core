<div>
    @if ($this->isVisible())
        <div style="border:1px solid #bfdbfe;background:#eff6ff;border-radius:12px;padding:16px 18px;display:flex;gap:14px;align-items:flex-start;">
            <span style="font-size:20px;line-height:1;">📢</span>
            <div style="flex:1;font-size:13.5px;color:#1e3a5f;">
                <div style="font-weight:700;font-size:14.5px;margin-bottom:6px;color:#111827;">
                    Mejora en el sistema — actas y citas del SAT
                </div>
                <p style="margin:0 0 8px;">
                    Ahora, cuando se sube el acta protocolizada de una empresa, el sistema la <strong>lee sola</strong>
                    y extrae a los representantes legales para poder agendar sus citas del SAT, sin capturarlos a mano.
                </p>
                <p style="margin:0 0 8px;">
                    Para las actas escaneadas muy grandes, activaremos un <strong>pequeño ajuste en el servidor</strong>
                    para que ese proceso siempre termine solo. <strong>No implica un costo extra relevante</strong>: lo
                    único que se consume es la lectura con inteligencia artificial, de unos centavos por acta y solo
                    cuando se sube una nueva.
                </p>
                <p style="margin:0 0 12px;">¿Nos confirmas el visto bueno para activarlo?</p>

                <div style="display:flex;gap:10px;align-items:center;">
                    <button type="button" wire:click="acknowledge" wire:loading.attr="disabled"
                        style="display:inline-flex;align-items:center;gap:6px;border:0;background:#2563eb;color:#fff;font-size:13px;font-weight:600;padding:8px 16px;border-radius:8px;cursor:pointer;">
                        ✓ Autorizo
                    </button>
                    <span wire:loading wire:target="acknowledge" style="color:#6b7280;font-size:12px;">Guardando…</span>
                </div>
            </div>
        </div>
    @endif
</div>
