<div>
    @if ($step === 'none')
        <div class="card center">
            <div class="ok-badge" style="background:#eef0f3;color:#6b7280;">✓</div>
            <h1>No tienes citas por reportar</h1>
            <p class="sub">Cuando termines una cita del SAT, aquí te aparecerá para que nos cuentes cómo te fue.</p>
        </div>

    @elseif ($step === 'status')
        <div class="card">
            <h1>¿Cómo te fue en tu cita de <span class="empresa">{{ $empresa }}</span>?</h1>
            <p class="sub">Elige lo que pasó en el SAT.</p>
            <button class="btn btn-ok" wire:click="marcar('attended')" wire:loading.attr="disabled">✅ Salió bien</button>
            <button class="btn btn-danger" wire:click="marcar('rejected')" wire:loading.attr="disabled">❌ Me rechazaron</button>
            <button class="btn btn-muted" wire:click="marcar('no_show')" wire:loading.attr="disabled">🚫 No asistí</button>
        </div>

    @elseif ($step === 'photo')
        <div class="card center">
            <h1>Toma la foto de tu Constancia (CSF)</h1>
            <p class="sub">La hoja del SAT donde viene el RFC de la empresa. Que se vea completa y de frente.</p>

            <div wire:loading.remove wire:target="foto">
                <label class="btn btn-primary filebtn">
                    📸 Tomar foto / subir
                    <input type="file" accept="image/*,application/pdf" capture="environment" wire:model="foto">
                </label>
            </div>

            <div wire:loading.flex wire:target="foto" style="flex-direction:column;align-items:center;padding:10px 0;">
                <div class="spinner"></div>
                <b>Leyendo tu RFC…</b>
                <span class="note">Tardamos unos segundos.</span>
            </div>

            @error('foto') <div class="err">{{ $message }}</div> @enderror
        </div>

    @elseif ($step === 'verify')
        <div class="card">
            <h1 class="center">Confirma el RFC</h1>

            @if ($extractError)
                <div class="err">{{ $extractError }}</div>
            @else
                <p class="sub center">Léelo contra tu documento y corrige si algo no cuadra.</p>
            @endif

            @if ($foto)
                <img src="{{ $foto->temporaryUrl() }}" class="preview" alt="CSF">
            @endif

            <label class="field">RFC de la empresa</label>
            <input type="text" class="rfc" wire:model="rfc" maxlength="13" placeholder="XXX000000XXX"
                   autocapitalize="characters" autocorrect="off" spellcheck="false">
            @error('rfc') <div class="err">{{ $message }}</div> @enderror

            <button class="btn btn-ok" wire:click="confirmar" wire:loading.attr="disabled" wire:target="confirmar">
                <span wire:loading.remove wire:target="confirmar">Confirmar y guardar</span>
                <span wire:loading wire:target="confirmar">Guardando…</span>
            </button>
            <button class="btn btn-ghost" wire:click="retomar">Volver a tomar la foto</button>
        </div>

    @elseif ($step === 'efirma')
        <div class="card">
            <div class="ok-badge">✓</div>
            <h1 class="center">RFC guardado</h1>
            <p class="sub center">¿Vas a ir también a la cita de <span class="empresa">e.firma</span> de <span class="empresa">{{ $empresa }}</span>?</p>
            <button class="btn btn-primary" wire:click="efirma(true)" wire:loading.attr="disabled">Sí, yo voy</button>
            <button class="btn btn-muted" wire:click="efirma(false)" wire:loading.attr="disabled">No / todavía no</button>
            <p class="note">Si dices que sí, la formamos automáticamente con tu RFC.</p>
        </div>

    @elseif ($step === 'done')
        <div class="card center">
            <div class="ok-badge">✓</div>
            <h1>{{ $doneTitle }}</h1>
            <p class="sub">{{ $doneBody }}</p>
            <button class="btn btn-muted" onclick="window.close(); location.reload();">Cerrar</button>
        </div>
    @endif
</div>
