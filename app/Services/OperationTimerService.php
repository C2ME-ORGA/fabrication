<?php

namespace App\Services;

use App\Enums\OperationTimeEntryType;
use App\Enums\WorkshopOperationAuditEvent;
use App\Enums\WorkshopOperationStatus;
use App\Models\OperationTimeEntry;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderOperation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gestion des sessions de chronométrage individuelles.
 *
 * Une session appartient à un seul opérateur. Plusieurs opérateurs peuvent
 * travailler simultanément sur la même opération. L'unicité d'une session
 * active par utilisateur est garantie par l'index SQL et vérifiée dans la
 * transaction.
 */
class OperationTimerService
{
    public function __construct(
        protected WorkOrderOperationAuditService $audit,
    ) {}

    public function start(WorkOrderOperation $operation, User $actor): OperationTimeEntry
    {
        try {
            return DB::transaction(function () use ($operation, $actor): OperationTimeEntry {
                $workOrder = $this->lockWorkOrder($operation);
                $locked = $this->lockOperation($workOrder, $operation);

                if (! $workOrder->allowsOperationExecution()) {
                    $this->fail(
                        'status',
                        "L'ordre de fabrication {$workOrder->number} doit être libéré pour chronométrer une opération.",
                    );
                }

                if (! $actor->can('startTimer', $locked)) {
                    $this->fail('operation', 'Vous n\'êtes pas autorisé à démarrer ce chronomètre.');
                }

                if ($locked->status !== WorkshopOperationStatus::InProgress) {
                    $this->fail('status', 'Le chronomètre ne peut démarrer que sur une opération en cours.');
                }

                if (! $locked->dependenciesSatisfied()) {
                    $this->fail('dependencies', 'Toutes les dépendances doivent être terminées avant le chronométrage.');
                }

                $activeSession = OperationTimeEntry::query()
                    ->where('user_id', $actor->getKey())
                    ->whereNull('ended_at')
                    ->lockForUpdate()
                    ->first();

                if ($activeSession !== null) {
                    $this->fail(
                        'time_entries',
                        'Vous avez déjà une session de chronométrage active. Arrêtez-la avant d\'en démarrer une nouvelle.',
                    );
                }

                $startedAt = now();

                $entry = OperationTimeEntry::query()->create([
                    'work_order_operation_id' => $locked->getKey(),
                    'original_entry_id' => null,
                    'user_id' => $actor->getKey(),
                    'started_at' => $startedAt,
                    'ended_at' => null,
                    'duration_seconds' => null,
                    'entry_type' => OperationTimeEntryType::Timer,
                    'created_by' => $actor->getKey(),
                    'corrected_by' => null,
                    'correction_reason' => null,
                    'notes' => null,
                ]);

                if ($locked->started_at === null) {
                    $locked->started_at = $startedAt;
                    $locked->save();
                }

                $this->audit->record(
                    $locked,
                    $workOrder,
                    WorkshopOperationAuditEvent::TimerStarted,
                    $actor,
                    $locked->status->value,
                    $locked->status->value,
                    null,
                    [
                        'time_entry_id' => $entry->getKey(),
                        'operator_id' => $actor->getKey(),
                        'started_at' => $startedAt->toIso8601String(),
                    ],
                );

                return $entry->refresh();
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            $this->fail('time_entries', 'Vous avez déjà une session de chronométrage active.');
        }
    }

    public function stop(WorkOrderOperation $operation, User $actor): OperationTimeEntry
    {
        return DB::transaction(function () use ($operation, $actor): OperationTimeEntry {
            $workOrder = $this->lockWorkOrder($operation);
            $locked = $this->lockOperation($workOrder, $operation);

            if (! $actor->can('stopTimer', $locked)) {
                $this->fail('operation', 'Vous n\'êtes pas autorisé à arrêter ce chronomètre.');
            }

            $entry = OperationTimeEntry::query()
                ->where('work_order_operation_id', $locked->getKey())
                ->where('user_id', $actor->getKey())
                ->whereNull('ended_at')
                ->lockForUpdate()
                ->first();

            if ($entry === null) {
                $this->fail('time_entries', 'Aucune session de chronométrage active ne vous appartient sur cette opération.');
            }

            $stoppedAt = now();
            $duration = $this->secondsBetween($entry, $stoppedAt);

            $entry->forceFill([
                'ended_at' => $stoppedAt,
                'duration_seconds' => $duration,
            ])->save();

            $this->audit->record(
                $locked,
                $workOrder,
                WorkshopOperationAuditEvent::TimerStopped,
                $actor,
                $locked->status->value,
                $locked->status->value,
                null,
                [
                    'time_entry_id' => $entry->getKey(),
                    'operator_id' => $actor->getKey(),
                    'duration_seconds' => $duration,
                    'ended_at' => $stoppedAt->toIso8601String(),
                ],
            );

            return $entry->refresh();
        }, attempts: 3);
    }

    /**
     * Arrête toutes les sessions actives d'une opération lors d'une pause ou
     * d'un blocage. L'appelant tient déjà les verrous de l'OF et de l'opération.
     *
     * @return Collection<int, OperationTimeEntry>
     */
    public function stopAllForOperation(
        WorkOrderOperation $operation,
        User $actor,
        string $reason,
    ): Collection {
        return DB::transaction(function () use ($operation, $actor, $reason): Collection {
            $entries = OperationTimeEntry::query()
                ->where('work_order_operation_id', $operation->getKey())
                ->whereNull('ended_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $stoppedAt = now();
            $workOrder = $operation->workOrder;

            foreach ($entries as $entry) {
                $duration = $this->secondsBetween($entry, $stoppedAt);

                $entry->forceFill([
                    'ended_at' => $stoppedAt,
                    'duration_seconds' => $duration,
                ])->save();

                $this->audit->record(
                    $operation,
                    $workOrder,
                    WorkshopOperationAuditEvent::TimerAutoStopped,
                    $actor,
                    $operation->status->value,
                    $operation->status->value,
                    $reason,
                    [
                        'time_entry_id' => $entry->getKey(),
                        'operator_id' => $entry->user_id,
                        'duration_seconds' => $duration,
                        'reason' => $reason,
                    ],
                );
            }

            return $entries;
        }, attempts: 3);
    }

    public function activeEntryForUser(
        WorkOrderOperation $operation,
        User $user,
    ): ?OperationTimeEntry {
        return OperationTimeEntry::query()
            ->where('work_order_operation_id', $operation->getKey())
            ->where('user_id', $user->getKey())
            ->whereNull('ended_at')
            ->first();
    }

    public function hasActiveSession(User $user): bool
    {
        return OperationTimeEntry::query()
            ->where('user_id', $user->getKey())
            ->whereNull('ended_at')
            ->exists();
    }

    protected function secondsBetween(OperationTimeEntry $entry, mixed $endedAt): int
    {
        return max(0, (int) $entry->started_at->diffInSeconds($endedAt));
    }

    protected function lockWorkOrder(WorkOrderOperation $operation): WorkOrder
    {
        $workOrder = WorkOrder::query()->lockForUpdate()->find($operation->work_order_id);

        if ($workOrder === null) {
            $this->fail('work_order', 'Cet ordre de fabrication n\'existe plus.');
        }

        return $workOrder;
    }

    protected function lockOperation(
        WorkOrder $workOrder,
        WorkOrderOperation $operation,
    ): WorkOrderOperation {
        $locked = $workOrder->operations()
            ->whereKey($operation->getKey())
            ->lockForUpdate()
            ->first();

        if ($locked === null) {
            $this->fail('operation', 'Cette opération n\'appartient pas à cet ordre de fabrication.');
        }

        return $locked;
    }

    protected function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
