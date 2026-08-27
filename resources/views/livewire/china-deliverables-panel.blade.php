@php
    $done = collect($this->items)->where('state', 'delivered')->count();
    $total = count($this->items);
    $meta = [
        'delivered' => ['icon' => '✅', 'text' => 'Enviado a China',          'color' => '#16a34a'],
        'pending'   => ['icon' => '⏳', 'text' => 'Lo tenemos — falta enviar', 'color' => '#ca8a04'],
        'rejected'  => ['icon' => '⛔', 'text' => 'Rechazado por China',       'color' => '#dc2626'],
        'missing'   => ['icon' => '❌', 'text' => 'No lo tenemos',            'color' => '#dc2626'],
    ];
    $btn = 'display:inline-flex;align-items:center;gap:5px;border:1px solid var(--fi-color-primary-300,#93c5fd);background:var(--fi-color-primary-50,#eff6ff);color:var(--fi-color-primary-700,#1d4ed8);font-size:12px;font-weight:600;padding:5px 11px;border-radius:7px;cursor:pointer;white-space:nowrap;';
    $btnGhost = 'display:inline-flex;align-items:center;gap:5px;border:1px solid #e5e7eb;background:transparent;color:#6b7280;font-size:12px;font-weight:600;padding:5px 11px;border-radius:7px;cursor:pointer;white-space:nowrap;';
@endphp

<div style="font-size:13px;">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px;flex-wrap:wrap;">
        <div style="color:#6b7280;">
            Confirmados por China:
            <strong style="color:{{ $done === $total ? '#16a34a' : '#111827' }};">{{ $done }}/{{ $total }}</strong>
        </div>
        @if($this->pendingCount > 0)
            <button type="button" wire:click="sendAllPending" wire:loading.attr="disabled" style="{{ $btn }}">
                <span wire:loading.remove wire:target="sendAllPending">✈️ Enviar pendientes ({{ $this->pendingCount }})</span>
                <span wire:loading wire:target="sendAllPending">Enviando…</span>
            </button>
        @endif
    </div>

    <div style="display:flex;flex-direction:column;gap:6px;">
        @foreach($this->items as $it)
            @php $m = $meta[$it['state']]; @endphp
            <div style="display:flex;align-items:center;gap:10px;padding:8px 10px;border:1px solid #e5e7eb;border-radius:8px;">
                <span style="font-size:15px;">{{ $m['icon'] }}</span>
                <span style="font-weight:600;min-width:180px;">{{ $it['label'] }}</span>
                <span style="color:{{ $m['color'] }};font-weight:500;">{{ $m['text'] }}</span>

                @if($it['state'] === 'delivered' && $it['delivered_at'])
                    <span style="color:#9ca3af;font-size:12px;margin-left:6px;">
                        {{ \Carbon\Carbon::parse($it['delivered_at'])->timezone('America/Mexico_City')->format('d/m/Y H:i') }}
                        @if($it['drive_url'])
                            · <a href="{{ $it['drive_url'] }}" target="_blank" rel="noopener" style="color:#2563eb;text-decoration:underline;">Drive ↗</a>
                        @endif
                    </span>
                @elseif($it['state'] === 'rejected' && $it['rejection_reason'])
                    <span style="color:#b91c1c;font-size:12px;margin-left:6px;max-width:220px;">Motivo: {{ $it['rejection_reason'] }}</span>
                @endif

                {{-- Acciones a la derecha, según estado --}}
                <span style="margin-left:auto;display:inline-flex;gap:8px;align-items:center;">
                    @if($it['state'] === 'pending')
                        <button type="button" wire:click="send('{{ $it['type'] }}')" wire:loading.attr="disabled" wire:target="send('{{ $it['type'] }}')" style="{{ $btn }}">✈️ Enviar</button>
                    @elseif($it['state'] === 'delivered')
                        <button type="button" wire:click="send('{{ $it['type'] }}')" style="{{ $btnGhost }}">↻ Reenviar</button>
                        <button type="button" x-on:click="$wire.markWrong('{{ $it['type'] }}', window.prompt('¿Por qué está mal este documento?'))" style="{{ $btnGhost }}">⚠️ Erróneo</button>
                    @elseif($it['state'] === 'rejected')
                        <button type="button" wire:click="send('{{ $it['type'] }}')" style="{{ $btn }}">↻ Reenviar</button>
                    @endif
                </span>
            </div>
        @endforeach
    </div>
</div>
