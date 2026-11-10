@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\QualityControlResultAttachment> $attachments */
@endphp

<div class="space-y-3 text-sm">
    @forelse ($attachments as $attachment)
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="min-w-0">
                <div class="truncate font-semibold text-gray-950 dark:text-white">{{ $attachment->original_filename }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">
                    {{ $attachment->mime_type }} · {{ number_format($attachment->size_bytes / 1024, 1, ',', ' ') }} Ko
                    · {{ $attachment->created_at?->timezone(config('quality.display_timezone', 'Europe/Paris'))->format('d/m/Y H:i') }}
                </div>
            </div>
            <a
                href="{{ route('filament.workshop.quality.result-attachments.download', $attachment) }}"
                class="inline-flex min-h-10 items-center rounded-lg border border-gray-300 px-3 py-2 font-semibold text-gray-700 hover:bg-gray-50 dark:border-white/10 dark:text-gray-200 dark:hover:bg-white/5"
                target="_blank"
                rel="noopener noreferrer"
            >
                Télécharger
            </a>
        </div>
    @empty
        <p class="text-gray-500 dark:text-gray-400">Aucune preuve jointe.</p>
    @endforelse
</div>
