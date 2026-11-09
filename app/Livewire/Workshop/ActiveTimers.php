<?php

namespace App\Livewire\Workshop;

use App\Models\OperationTimeEntry;
use App\Support\DurationFormatter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Component;

/**
 * Chronomètres actifs, actualisés côté serveur par Livewire.
 *
 * Le rendu ne modifie jamais la base : la durée affichée est calculée à
 * partir de started_at et de l'heure serveur. Les opérateurs standards ne
 * voient que leurs propres sessions ; les responsables voient toute l'atelier.
 */
class ActiveTimers extends Component
{
    public function render(): View
    {
        $user = auth()->user();

        $query = OperationTimeEntry::query()
            ->whereNull('ended_at')
            ->with(['user', 'operation.workOrder.job'])
            ->orderBy('started_at');

        if ($user === null) {
            $query->whereRaw('1 = 0');
        } elseif (! $user->canManageOperationTimes()) {
            $query->where('user_id', $user->getKey());
        }

        $entries = $query->get();
        $now = Carbon::now();

        return view('livewire.workshop.active-timers', [
            'entries' => $entries,
            'now' => $now,
            'formatDuration' => fn (int $seconds): string => DurationFormatter::format($seconds, true),
        ]);
    }
}
