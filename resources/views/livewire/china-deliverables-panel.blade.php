@php
    $done = collect($this->items)->where('state', 'delivered')->count();
    $total = count($this->items);
    $poll = $this->pollInterval;
    $meta = [
        'delivered' => ['icon' => '✅', 'text' => 'Enviado a China',           'color' => '#16a34a'],
        'sending'   => ['icon' => '⏳', 'text' => 'Enviando a China…',          'color' => '#2563eb'],
        'pending'   => ['icon' => '📤', 'text' => 'Lo tenemos — falta enviar',  'color' => '#ca8a04'],
        'failed'    => ['icon' => '⚠️', 'text' => 'No se pudo enviar',          'color' => '#dc2626'],
        'rejected'  => ['icon' => '⛔', 'text' => 'Rechazado por China',        'color' => '#dc2626'],
        'missing'   => ['icon' => '❌', 'text' => 'No lo tenemos',             'color' => '#dc2626'],
    ];
    // Cada fila puede tener un menú de acciones según su estado.
    $actionsFor = function (array $it): array {
        return match ($it['state']) {
            'pending'  => [['label' => '✈️  Enviar a China', 'call' => "send('{$it['type']}')"]],
            'delivered' => [
                ['label' => '↻  Reenviar a China', 'call' => "send('{$it['type']}')"],
                ['label' => '⚠️  Marcar como erróneo', 'prompt' => true],
            ],
            'failed', 'rejected' => [['label' => '↻  Reintentar envío', 'call' => "send('{$it['type']}')"]],
            default => [],
        };
    };
@endphp

<div style="font-size:13px;" @if($poll) wire:poll.{{ $poll }} @endif>
    <style>
        [x-cloak] { display: none !important; }
        @keyframes ccd-spin { to { transform: rotate(360deg); } }
        .ccd-spinner { width:13px;height:13px;border:2px solid #bfdbfe;border-top-color:#2563eb;border-radius:50%;display:inline-block;animation:ccd-spin .7s linear infinite; }
        .ccd-menu-item { display:block;width:100%;text-align:left;background:transparent;border:0;padding:7px 12px;font-size:12.5px;color:#374151;cursor:pointer;white-space:nowrap; }
        .ccd-menu-item:hover { background:#f3f4f6; }
    </style>

    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px;flex-wrap:wrap;">
        <div style="color:#6b7280;">
            Confirmados por China:
            <strong style="color:{{ $done === $total ? '#16a34a' : '#111827' }};">{{ $done }}/{{ $total }}</strong>
        </div>
        @if($this->pendingCount > 0)
            <button type="button"
                wire:click="sendAllPending"
                wire:loading.attr="disabled" wire:target="sendAllPending"
                style="display:inline-flex;align-items:center;gap:6px;border:1px solid var(--fi-color-primary-300,#93c5fd);background:var(--fi-color-primary-50,#eff6ff);color:var(--fi-color-primary-700,#1d4ed8);font-size:12px;font-weight:600;padding:6px 12px;border-radius:7px;cursor:pointer;white-space:nowrap;">
                ✈️ Enviar pendientes ({{ $this->pendingCount }})
            </button>
        @endif
    </div>

    <div style="display:flex;flex-direction:column;gap:6px;">
        @foreach($this->items as $it)
            @php $m = $meta[$it['state']]; $actions = $actionsFor($it); @endphp
            <div style="display:flex;align-items:center;gap:10px;padding:8px 10px;border:1px solid #e5e7eb;border-radius:8px;{{ $it['state'] === 'sending' ? 'background:#f0f7ff;' : '' }}">
                <span style="font-size:15px;width:18px;text-align:center;">
                    @if($it['state'] === 'sending')<span class="ccd-spinner"></span>@else{{ $m['icon'] }}@endif
                </span>
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
                    <span style="color:#b91c1c;font-size:12px;margin-left:6px;max-width:260px;">Motivo: {{ $it['rejection_reason'] }}</span>
                @elseif($it['state'] === 'failed' && ($it['failure_reason'] ?? null))
                    <span style="color:#b91c1c;font-size:12px;margin-left:6px;max-width:340px;">{{ $it['failure_reason'] }}</span>
                @endif

                {{-- Menú de acciones (claramente acciones, no estados) --}}
                <span style="margin-left:auto;display:inline-flex;align-items:center;">
                    @if($it['state'] === 'sending')
                        <span style="color:#2563eb;font-size:12px;font-weight:600;">En curso…</span>
                    @elseif(count($actions) > 0)
                        <div style="position:relative;" x-data="{ open: false }" @keydown.escape="open = false">
                            <button type="button" @click="open = !open" @click.away="open = false"
                                style="display:inline-flex;align-items:center;gap:5px;border:1px solid #e5e7eb;background:#fff;color:#374151;font-size:12px;font-weight:600;padding:5px 10px;border-radius:7px;cursor:pointer;white-space:nowrap;">
                                Acciones
                                <span style="font-size:9px;">▾</span>
                            </button>
                            <div x-show="open" x-cloak x-transition.opacity
                                style="position:absolute;right:0;top:calc(100% + 4px);z-index:20;background:#fff;border:1px solid #e5e7eb;border-radius:8px;box-shadow:0 6px 20px rgba(0,0,0,.12);overflow:hidden;min-width:190px;">
                                @foreach($actions as $action)
                                    @if($action['prompt'] ?? false)
                                        <button type="button" class="ccd-menu-item"
                                            x-on:click="open = false; $wire.markWrong('{{ $it['type'] }}', window.prompt('¿Por qué está mal este documento?'))">
                                            {{ $action['label'] }}
                                        </button>
                                    @else
                                        <button type="button" class="ccd-menu-item"
                                            wire:click="{{ $action['call'] }}"
                                            x-on:click="open = false">
                                            {{ $action['label'] }}
                                        </button>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endif
                </span>
            </div>
        @endforeach
    </div>
</div>
