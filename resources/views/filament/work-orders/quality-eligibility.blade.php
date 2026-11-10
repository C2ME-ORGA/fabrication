@php
    /** @var \App\DTO\ClosureEligibility $eligibility */
    $eligible = $eligibility->eligible;
@endphp

<div class="space-y-4 text-sm">
    <div class="flex flex-wrap items-center gap-3">
        <span @class([
            'inline-flex items-center rounded-md px-2 py-1 text-xs font-semibold',
            'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400' => $eligible,
            'bg-danger-50 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400' => ! $eligible,
        ])>
            {{ $eligible ? 'Éligible qualité' : 'Bloquée qualité' }}
        </span>
        <span class="text-gray-500 dark:text-gray-400">
            Calcul du {{ $eligibility->evaluatedAt }} — phase {{ $eligibility->phase }}
        </span>
    </div>

    @if ($eligibility->blockers !== [])
        <div>
            <p class="mb-2 font-semibold text-danger-700 dark:text-danger-400">Blocages</p>
            <ul class="list-disc space-y-1 pl-5">
                @foreach ($eligibility->blockers as $blocker)
                    <li>
                        <span class="font-medium">{{ $blocker->code->getLabel() }}</span> —
                        {{ $blocker->message }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($eligibility->warnings !== [])
        <div>
            <p class="mb-2 font-semibold text-warning-700 dark:text-warning-400">Avertissements</p>
            <ul class="list-disc space-y-1 pl-5">
                @foreach ($eligibility->warnings as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($controls->isNotEmpty())
        <div>
            <p class="mb-2 font-semibold">Contrôles concernés</p>
            <ul class="list-disc space-y-1 pl-5">
                @foreach ($controls as $control)
                    <li>
                        {{ $control->template_code }} — {{ $control->template_name }}
                        <span class="text-gray-500">({{ $control->status->getLabel() }})</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($nonConformities->isNotEmpty())
        <div>
            <p class="mb-2 font-semibold">Non-conformités concernées</p>
            <ul class="list-disc space-y-1 pl-5">
                @foreach ($nonConformities as $nonConformity)
                    <li>
                        {{ $nonConformity->reference }} — {{ $nonConformity->status->getLabel() }}
                        <span class="text-gray-500">({{ $nonConformity->severity->getLabel() }})</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($deviations->isNotEmpty())
        <div>
            <p class="mb-2 font-semibold">Dérogations appliquées</p>
            <ul class="list-disc space-y-1 pl-5">
                @foreach ($deviations as $deviation)
                    <li>
                        {{ $deviation->reference }} — {{ $deviation->targetDescription() }}
                        @if ($deviation->expires_at !== null)
                            <span class="text-gray-500">(expire le {{ $deviation->expires_at->format('d/m/Y H:i') }})</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
