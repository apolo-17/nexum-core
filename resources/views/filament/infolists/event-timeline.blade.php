@php
    /**
     * Timeline unificado de eventos (citas del SAT y denominaciones/MUA).
     *
     * Un solo componente para ambos: el registro (`$getRecord()`) expone `events` con
     * `type` (color/icon/label), `created_at`, `description`, `metadata` y `actor`.
     * Para acortar el historial, se COLAPSAN los eventos consecutivos del mismo tipo en
     * una sola entrada con su contador (p. ej. la revisión cada 4 h deja de repetirse
     * decenas de veces y se muestra como "Revisada ×30 · última …").
     */
    $record = $getRecord();
    $events = $record->events;

    $isAppointment = $record instanceof \App\Models\Appointment;
    $botLabel = $isAppointment ? 'Bot de citas SAT' : 'Bot MUA';
    $emptyMessage = $isAppointment
        ? 'Aún no hay movimientos registrados para esta cita.'
        : 'Aún no hay eventos registrados para esta denominación.';

    // Timestamps guardados en UTC; se muestran en horario CDMX.
    $tz = 'America/Mexico_City';

    $colorClasses = [
        'gray' => 'text-gray-500 bg-gray-100 dark:bg-gray-500/20',
        'info' => 'text-sky-600 bg-sky-100 dark:bg-sky-500/20',
        'warning' => 'text-amber-600 bg-amber-100 dark:bg-amber-500/20',
        'danger' => 'text-red-600 bg-red-100 dark:bg-red-500/20',
        'primary' => 'text-primary-600 bg-primary-100 dark:bg-primary-500/20',
        'success' => 'text-green-600 bg-green-100 dark:bg-green-500/20',
    ];

    // Colapsa eventos consecutivos del mismo tipo Y mismo actor en grupos con contador.
    $groups = [];

    foreach ($events as $event) {
        $tail = $groups[count($groups) - 1] ?? null;

        if ($tail !== null && $tail['type'] === $event->type && $tail['actor_type'] === $event->actor_type) {
            $groups[count($groups) - 1]['count']++;
            $groups[count($groups) - 1]['last'] = $event;
        } else {
            $groups[] = [
                'type' => $event->type,
                'actor_type' => $event->actor_type,
                'first' => $event,
                'last' => $event,
                'count' => 1,
            ];
        }
    }
@endphp

<div class="fi-in-timeline">
    @if ($events->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ $emptyMessage }}
        </p>
    @else
        <ol class="relative space-y-6 border-s border-gray-200 ps-6 dark:border-white/10">
            @foreach ($groups as $group)
                @php
                    $type = $group['type'];
                    $event = $group['last'];
                    $classes = $colorClasses[$type->color()] ?? $colorClasses['gray'];
                    $lastAt = $group['last']->created_at->copy()->timezone($tz);
                    $firstAt = $group['first']->created_at->copy()->timezone($tz);
                @endphp
                <li class="relative">
                    <span class="absolute -start-[2.1rem] flex h-7 w-7 items-center justify-center rounded-full ring-4 ring-white dark:ring-gray-900 {{ $classes }}">
                        <x-filament::icon :icon="$type->icon()" class="h-4 w-4" />
                    </span>

                    <div class="flex flex-col gap-0.5">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm font-semibold text-gray-950 dark:text-white">
                                {{ $type->label() }}
                            </span>

                            @if ($group['count'] > 1)
                                <span class="rounded-full bg-gray-100 px-1.5 py-0.5 text-[0.65rem] font-semibold text-gray-500 dark:bg-white/10 dark:text-gray-400">
                                    ×{{ $group['count'] }}
                                </span>
                            @endif

                            <span class="text-xs text-gray-400"
                                title="{{ $group['count'] > 1 ? $firstAt->format('d/m/Y H:i') . ' → ' . $lastAt->format('d/m/Y H:i:s') : $lastAt->format('d/m/Y H:i:s') }} (CDMX)">
                                {{ $group['count'] > 1 ? 'última: ' : '' }}{{ $lastAt->format('d/m/Y H:i') }}
                            </span>
                        </div>

                        @if ($event->description)
                            <p class="text-sm text-gray-600 dark:text-gray-300">
                                {{ $event->description }}
                            </p>
                        @endif

                        @if (! empty($event->metadata['error']))
                            <p class="mt-1 rounded-md bg-red-50 px-2 py-1 font-mono text-xs text-red-700 dark:bg-red-500/10 dark:text-red-400">
                                {{ \Illuminate\Support\Str::limit($event->metadata['error'], 200) }}
                            </p>
                        @endif

                        @if (! empty($event->metadata['reason']))
                            <p class="mt-1 rounded-md bg-red-50 px-2 py-1 font-mono text-xs text-red-700 dark:bg-red-500/10 dark:text-red-400">
                                {{ \Illuminate\Support\Str::limit($event->metadata['reason'], 200) }}
                            </p>
                        @endif

                        <span class="text-xs text-gray-400">
                            @switch($event->actor_type)
                                @case('user')
                                    {{ $event->actor?->name ?? 'Usuario' }}
                                    @break
                                @case('bot')
                                    {{ $botLabel }}
                                    @break
                                @default
                                    Sistema
                            @endswitch
                        </span>
                    </div>
                </li>
            @endforeach
        </ol>
    @endif
</div>
