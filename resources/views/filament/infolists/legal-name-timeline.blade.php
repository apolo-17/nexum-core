@php
    /** @var \App\Models\LegalName $record */
    $record = $getRecord();
    $events = $record->events;

    $colorClasses = [
        'gray' => 'text-gray-500 bg-gray-100 dark:bg-gray-500/20',
        'info' => 'text-sky-600 bg-sky-100 dark:bg-sky-500/20',
        'warning' => 'text-amber-600 bg-amber-100 dark:bg-amber-500/20',
        'danger' => 'text-red-600 bg-red-100 dark:bg-red-500/20',
        'primary' => 'text-primary-600 bg-primary-100 dark:bg-primary-500/20',
        'success' => 'text-green-600 bg-green-100 dark:bg-green-500/20',
    ];
@endphp

<div class="fi-in-timeline">
    @if ($events->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Aún no hay eventos registrados para esta denominación.
        </p>
    @else
        <ol class="relative space-y-6 border-s border-gray-200 ps-6 dark:border-white/10">
            @foreach ($events as $event)
                @php
                    $type = $event->type;
                    $classes = $colorClasses[$type->color()] ?? $colorClasses['gray'];
                @endphp
                <li class="relative">
                    <span class="absolute -start-[2.1rem] flex h-7 w-7 items-center justify-center rounded-full ring-4 ring-white dark:ring-gray-900 {{ $classes }}">
                        <x-filament::icon :icon="$type->icon()" class="h-4 w-4" />
                    </span>

                    <div class="flex flex-col gap-0.5">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-semibold text-gray-950 dark:text-white">
                                {{ $type->label() }}
                            </span>
                            <span class="text-xs text-gray-400" title="{{ $event->created_at->format('d/m/Y H:i:s') }}">
                                {{ $event->created_at->format('d/m/Y H:i') }}
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
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ $event->metadata['reason'] }}
                            </p>
                        @endif

                        <span class="text-xs text-gray-400">
                            @switch($event->actor_type)
                                @case('user')
                                    {{ $event->actor?->name ?? 'Usuario' }}
                                    @break
                                @case('bot')
                                    Bot MUA
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
