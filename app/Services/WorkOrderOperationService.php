<?php

namespace App\Services;

use App\Enums\WorkshopOperationAuditEvent;
use App\Enums\WorkshopOperationStatus;
use App\Models\OperationTimeEntry;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moteur d'exécution des opérations d'un ordre de fabrication.
 *
 * Toutes les transitions sont centralisées ici, protégées par transaction et
 * verrouillage de l'OF puis de l'opération. Le chronométrage n'est pas géré
 * dans ce service : les actions incompatibles avec une session active sont
 * refusées en attendant le Lot 5.
 */
class WorkOrderOperationService
{
    public function __construct(
        protected OperationDependencyService $dependencies,
        protected WorkOrderOperationAuditService $audit,
        protected OperationTimerService $timers,
    ) {}

    public function start(WorkOrderOperation $operation, User $actor): WorkOrderOperation
    {
        return DB::transaction(function () use ($operation, $actor): WorkOrderOperation {
            [$workOrder, $locked] = $this->lockContext($operation, $actor, 'start');

            if ($locked->status !== WorkshopOperationStatus::Ready) {
                $this->fail(
                    'status',
                    "Une opération « {$locked->status->getLabel()} » ne peut pas être démarrée : elle doit être prête.",
                );
            }

            if (! $locked->dependenciesSatisfied()) {
                $this->fail('dependencies', 'Toutes les dépendances doivent être terminées avant le démarrage.');
            }

            $locked->started_at ??= now();

            return $this->transition(
                $workOrder,
                $locked,
                WorkshopOperationStatus::InProgress,
                WorkshopOperationAuditEvent::Started,
                $actor,
            );
        }, attempts: 3);
    }

    public function pause(WorkOrderOperation $operation, User $actor): WorkOrderOperation
    {
        return DB::transaction(function () use ($operation, $actor): WorkOrderOperation {
            [$workOrder, $locked] = $this->lockContext($operation, $actor, 'pause');

            if ($locked->status !== WorkshopOperationStatus::InProgress) {
                $this->fail('status', 'Seule une opération en cours peut être mise en pause.');
            }

            $this->timers->stopAllForOperation($locked, $actor, 'pause');

            return $this->transition(
                $workOrder,
                $locked,
                WorkshopOperationStatus::Paused,
                WorkshopOperationAuditEvent::Paused,
                $actor,
            );
        }, attempts: 3);
    }

    public function resume(WorkOrderOperation $operation, User $actor): WorkOrderOperation
    {
        return DB::transaction(function () use ($operation, $actor): WorkOrderOperation {
            [$workOrder, $locked] = $this->lockContext($operation, $actor, 'resume');

            if (! in_array($locked->status, [WorkshopOperationStatus::Paused, WorkshopOperationStatus::Blocked], true)) {
                $this->fail('status', 'Seule une opération en pause ou bloquée peut être reprise.');
            }

            if (! $locked->dependenciesSatisfied()) {
                $this->fail('dependencies', 'Toutes les dépendances doivent être terminées avant la reprise.');
            }

            $this->assertNoActiveTimeEntry($locked);

            $wasBlocked = $locked->status === WorkshopOperationStatus::Blocked;
            $previousBlockedReason = $wasBlocked ? $locked->blocked_reason : null;

            if ($wasBlocked) {
                $locked->forceFill([
                    'blocked_reason' => null,
                    'blocked_at' => null,
                    'blocked_by' => null,
                ]);
            }

            return $this->transition(
                $workOrder,
                $locked,
                WorkshopOperationStatus::InProgress,
                WorkshopOperationAuditEvent::Resumed,
                $actor,
                null,
                ['previous_blocked_reason' => $previousBlockedReason],
            );
        }, attempts: 3);
    }

    public function block(WorkOrderOperation $operation, User $actor, string $reason): WorkOrderOperation
    {
        $reason = $this->normalizeReason($reason, 'blocked_reason', 'Le motif de blocage est obligatoire.');

        return DB::transaction(function () use ($operation, $actor, $reason): WorkOrderOperation {
            [$workOrder, $locked] = $this->lockContext($operation, $actor, 'block');

            if (! in_array($locked->status, [WorkshopOperationStatus::InProgress, WorkshopOperationStatus::Paused], true)) {
                $this->fail('status', 'Seule une opération en cours ou en pause peut être bloquée.');
            }

            $this->timers->stopAllForOperation($locked, $actor, 'block');

            $locked->forceFill([
                'blocked_reason' => $reason,
                'blocked_at' => now(),
                'blocked_by' => $actor->getKey(),
            ]);

            return $this->transition(
                $workOrder,
                $locked,
                WorkshopOperationStatus::Blocked,
                WorkshopOperationAuditEvent::Blocked,
                $actor,
                $reason,
            );
        }, attempts: 3);
    }

    public function unblock(WorkOrderOperation $operation, User $actor): WorkOrderOperation
    {
        return DB::transaction(function () use ($operation, $actor): WorkOrderOperation {
            [$workOrder, $locked] = $this->lockContext($operation, $actor, 'unblock');

            if ($locked->status !== WorkshopOperationStatus::Blocked) {
                $this->fail('status', 'Seule une opération bloquée peut être débloquée.');
            }

            $this->assertNoActiveTimeEntry($locked);

            $previousReason = $locked->blocked_reason;
            $target = $locked->dependenciesSatisfied()
                ? WorkshopOperationStatus::Ready
                : WorkshopOperationStatus::Pending;

            $locked->forceFill([
                'blocked_reason' => null,
                'blocked_at' => null,
                'blocked_by' => null,
            ]);

            return $this->transition(
                $workOrder,
                $locked,
                $target,
                WorkshopOperationAuditEvent::Unblocked,
                $actor,
                'Déblocage de l\'opération',
                ['previous_blocked_reason' => $previousReason],
            );
        }, attempts: 3);
    }

    public function complete(WorkOrderOperation $operation, User $actor): WorkOrderOperation
    {
        return DB::transaction(function () use ($operation, $actor): WorkOrderOperation {
            [$workOrder, $locked] = $this->lockContext($operation, $actor, 'complete');

            if ($locked->status !== WorkshopOperationStatus::InProgress) {
                $this->fail('status', 'Seule une opération en cours peut être terminée.');
            }

            if (! $locked->dependenciesSatisfied()) {
                $this->fail('dependencies', 'Toutes les dépendances doivent être terminées avant la terminaison.');
            }

            $this->assertNoActiveTimeEntry($locked);

            $locked->completed_at = now();

            $completed = $this->transition(
                $workOrder,
                $locked,
                WorkshopOperationStatus::Completed,
                WorkshopOperationAuditEvent::Completed,
                $actor,
            );

            $this->dependencies->propagateAfterCompletion($completed, $actor);

            return $completed->refresh();
        }, attempts: 3);
    }

    public function cancel(WorkOrderOperation $operation, User $actor, string $reason): WorkOrderOperation
    {
        $reason = $this->normalizeReason($reason, 'cancel_reason', 'Le motif d\'annulation est obligatoire.');

        return DB::transaction(function () use ($operation, $actor, $reason): WorkOrderOperation {
            [$workOrder, $locked] = $this->lockContext($operation, $actor, 'cancel');

            if (! in_array($locked->status, [
                WorkshopOperationStatus::Ready,
                WorkshopOperationStatus::Paused,
                WorkshopOperationStatus::Blocked,
            ], true)) {
                $this->fail('status', 'Cette opération ne peut pas être annulée depuis son statut actuel.');
            }

            $this->assertNoActiveTimeEntry($locked);

            $previousBlockedReason = $locked->blocked_reason;

            $locked->forceFill([
                'blocked_reason' => null,
                'blocked_at' => null,
                'blocked_by' => null,
            ]);

            return $this->transition(
                $workOrder,
                $locked,
                WorkshopOperationStatus::Cancelled,
                WorkshopOperationAuditEvent::Cancelled,
                $actor,
                $reason,
                ['previous_blocked_reason' => $previousBlockedReason],
            );
        }, attempts: 3);
    }

    /**
     * @return array{0: WorkOrder, 1: WorkOrderOperation}
     */
    protected function lockContext(
        WorkOrderOperation $operation,
        User $actor,
        string $ability,
    ): array {
        $workOrder = WorkOrder::query()->lockForUpdate()->find($operation->work_order_id);

        if ($workOrder === null) {
            $this->fail('work_order', 'Cet ordre de fabrication n\'existe plus.');
        }

        if (! $workOrder->allowsOperationExecution()) {
            $this->fail(
                'status',
                "L'ordre de fabrication {$workOrder->number} doit être libéré pour exécuter ses opérations.",
            );
        }

        $locked = $workOrder->operations()
            ->whereKey($operation->getKey())
            ->lockForUpdate()
            ->first();

        if ($locked === null) {
            $this->fail('operation', 'Cette opération n\'appartient pas à cet ordre de fabrication.');
        }

        if (! $actor->can($ability, $locked)) {
            $this->fail('operation', 'Vous n\'êtes pas autorisé à effectuer cette action sur cette opération.');
        }

        return [$workOrder, $locked];
    }

    protected function transition(
        WorkOrder $workOrder,
        WorkOrderOperation $operation,
        WorkshopOperationStatus $target,
        WorkshopOperationAuditEvent $event,
        User $actor,
        ?string $comment = null,
        array $metadata = [],
    ): WorkOrderOperation {
        $from = $operation->status;

        if (! $from->canTransitionTo($target)) {
            $this->fail(
                'status',
                "Transition de statut interdite : « {$from->getLabel()} » vers « {$target->getLabel()} ».",
            );
        }

        $operation->status = $target;
        $operation->save();

        $this->audit->record(
            $operation,
            $workOrder,
            $event,
            $actor,
            $from->value,
            $target->value,
            $comment,
            $metadata,
        );

        return $operation->refresh();
    }

    protected function assertNoActiveTimeEntry(WorkOrderOperation $operation): void
    {
        if (OperationTimeEntry::query()
            ->where('work_order_operation_id', $operation->getKey())
            ->whereNull('ended_at')
            ->exists()) {
            $this->fail(
                'time_entries',
                'Une session de chronométrage est active sur cette opération. Arrêtez-la avant de poursuivre.',
            );
        }
    }

    protected function normalizeReason(string $reason, string $field, string $message): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            $this->fail($field, $message);
        }

        if (mb_strlen($reason) > 2000) {
            $this->fail($field, 'Le motif ne peut pas dépasser 2 000 caractères.');
        }

        return $reason;
    }

    protected function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
