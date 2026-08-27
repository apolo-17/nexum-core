@php
    /**
     * Panel de entregables a China: los 5 documentos que China necesita, con su estado de
     * entrega (confirmado por China / pendiente de enviar / no lo tenemos) y el link a su
     * Google Drive cuando ya lo recibió. Datos vía ChinaDeliverablesService.
     */
    $registration = $getRecord();
    $svc = app(\App\Services\Singapur\ChinaDeliverablesService::class);
    $items = $svc->statusFor($registration);
    $done = collect($items)->where('state', 'delivered')->count();
    $total = $svc->total();

    $meta = [
        'delivered' => ['icon' => '✅', 'text' => 'Enviado a China',        'color' => '#16a34a'],
        'pending'   => ['icon' => '⏳', 'text' => 'Lo tenemos — falta enviar', 'color' => '#ca8a04'],
        'missing'   => ['icon' => '❌', 'text' => 'No lo tenemos',          'color' => '#dc2626'],
    ];
@endphp

<div style="font-size:13px;">
    <div style="margin-bottom:10px;color:#6b7280;">
        Confirmados por China: <strong style="color:{{ $done === $total ? '#16a34a' : '#111827' }};">{{ $done }}/{{ $total }}</strong>
    </div>

    <div style="display:flex;flex-direction:column;gap:6px;">
        @foreach($items as $it)
            @php $m = $meta[$it['state']]; @endphp
            <div style="display:flex;align-items:center;gap:10px;padding:8px 10px;border:1px solid #e5e7eb;border-radius:8px;">
                <span style="font-size:15px;">{{ $m['icon'] }}</span>
                <span style="font-weight:600;min-width:190px;">{{ $it['label'] }}</span>
                <span style="color:{{ $m['color'] }};font-weight:500;">{{ $m['text'] }}</span>
                @if($it['state'] === 'delivered')
                    <span style="color:#9ca3af;margin-left:auto;font-size:12px;">
                        {{ \Carbon\Carbon::parse($it['delivered_at'])->timezone('America/Mexico_City')->format('d/m/Y H:i') }}
                        @if($it['drive_url'])
                            · <a href="{{ $it['drive_url'] }}" target="_blank" rel="noopener" style="color:#2563eb;text-decoration:underline;">ver en Drive ↗</a>
                        @endif
                    </span>
                @endif
            </div>
        @endforeach
    </div>
</div>
