@php
    /** @var \App\Models\Soldado $record */
    $record = $getRecord();

    // Each side resolves to its private storage path and an inline preview URL.
    // PDFs are detected by extension so we render a link instead of an <img>.
    $sides = [
        'front' => ['label' => 'INE — anverso', 'path' => $record->ine_front_path],
        'back' => ['label' => 'INE — reverso', 'path' => $record->ine_back_path],
    ];
@endphp

<div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
    @foreach ($sides as $side => $data)
        <div>
            <p class="mb-2 text-sm font-medium text-gray-700 dark:text-gray-200">
                {{ $data['label'] }}
            </p>

            @if (blank($data['path']))
                <div class="flex h-40 items-center justify-center rounded-lg border border-dashed border-gray-300 text-sm text-gray-400 dark:border-white/10 dark:text-gray-500">
                    Sin cargar
                </div>
            @else
                @php
                    $url = route('admin.soldados.ine.preview', ['soldado' => $record, 'side' => $side]);
                    $isPdf = strtolower(pathinfo($data['path'], PATHINFO_EXTENSION)) === 'pdf';
                @endphp

                @if ($isPdf)
                    <a
                        href="{{ $url }}"
                        target="_blank"
                        rel="noopener"
                        class="flex h-40 items-center justify-center gap-2 rounded-lg border border-gray-200 bg-gray-50 text-sm font-medium text-primary-600 hover:bg-gray-100 dark:border-white/10 dark:bg-white/5 dark:text-primary-400 dark:hover:bg-white/10"
                    >
                        📄 Abrir PDF
                    </a>
                @else
                    <a href="{{ $url }}" target="_blank" rel="noopener" title="Abrir en tamaño completo">
                        <img
                            src="{{ $url }}"
                            alt="{{ $data['label'] }}"
                            class="h-40 w-full rounded-lg border border-gray-200 object-contain dark:border-white/10"
                            style="background: #525659;"
                        >
                    </a>
                @endif
            @endif
        </div>
    @endforeach
</div>
