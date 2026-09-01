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

    @elseif ($step === 'reject')
        <div class="card">
            <h1>¿Por qué te rechazaron?</h1>
            <p class="sub">Cuéntanos qué te dijo el SAT (por ejemplo: faltó un documento, el poder no tenía facultad fiscal, la e.firma no estaba activa…). El equipo lo necesita para volver a agendar.</p>
            <textarea wire:model="rejectionReason" rows="4" class="input" placeholder="Escribe el motivo del rechazo…" style="width:100%;padding:12px;border:1px solid #d1d5db;border-radius:10px;font-size:15px;"></textarea>
            @error('rejectionReason') <p class="sub" style="color:#dc2626;">{{ $message }}</p> @enderror
            <button class="btn btn-danger" wire:click="confirmarRechazo" wire:loading.attr="disabled" style="margin-top:12px;">Enviar motivo</button>
            <button class="btn btn-muted" wire:click="$set('step', 'status')" wire:loading.attr="disabled">Volver</button>
        </div>

    @elseif ($step === 'rfc')
        <div class="card">
            <h1 class="center">Escribe el RFC de la empresa</h1>
            <p class="sub center">El RFC que te dio el SAT en tu cita. No necesitas subir foto ni la constancia.</p>

            <label class="field">RFC de la empresa</label>
            <input type="text" class="rfc" wire:model="rfc" maxlength="13" placeholder="XXX000000XXX"
                   autocapitalize="characters" autocorrect="off" spellcheck="false">
            @error('rfc') <div class="err">{{ $message }}</div> @enderror

            <button class="btn btn-ok" wire:click="confirmar" wire:loading.attr="disabled" wire:target="confirmar">
                <span wire:loading.remove wire:target="confirmar">Guardar RFC</span>
                <span wire:loading wire:target="confirmar">Guardando…</span>
            </button>
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
