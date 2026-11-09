<?php

namespace App\Services;

use App\Enums\OperationTimeEntryType;
use App\Enums\WorkshopOperationAuditEvent;
use App\Models\OperationTimeEntry;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderOperation;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saisie manuelle et corrections append-only des temps.
 *
 * Une correction crée une nouvelle entrée `adjustment` reliée à l'entrée
 * d'origine. L'entrée d'origine n'est jamais modifiée ni supprimée.
 */
class OperationTimeEntryService
{
    protected const MAX_DURATION_SECONDS = 31_536_000;

    public function __construct(
        protected WorkOrderOperationAuditService $audit,
    ) {}

    public function recordManual(
        WorkOrderOperation $operation,
        User $operator,
        int $durationSeconds,
        ?CarbonInterface $startedAt,
        ?string $notes,
        User $actor,
    ): OperationTimeEntry {
        if ($durationSeconds <= 0) {
            $this->fail('duration_seconds', 'La durée doit être strictement positive.');
        }

        if ($durationSeconds > self::MAX_DURATION_SECONDS) {
            $this->fail('duration_seconds', 'La durée saisie est incohérente.');
        }

        if (! $operator->exists) {
            $this->fail('user_id', 'L\'opérateur sélectionné est introuvable.');
        }

        $notes = $this->normalizeOptionalText($notes, 'notes');

        return DB::transaction(function () use (
            $operation,
            $operator,
            $durationSeconds,
            $startedAt,
            $notes,
            $actor,
        ): OperationTimeEntry {
            $workOrder = $this->lockWorkOrder($operation);
            $lockedOperation = $this->lockOperation($workOrder, $operation);

            if (! $actor->can('recordManualTime', $lockedOperation)) {
                $this->fail('time_entries', 'Vous n\'êtes pas autorisé à saisir manuellement un temps.');
            }

            $endedAt = $startedAt === null
                ? now()
                : $startedAt->copy()->addSeconds($durationSeconds);
            $startedAt ??= $endedAt->copy()->subSeconds($durationSeconds);

            $entry = OperationTimeEntry::query()->create([
                'work_order_operation_id' => $lockedOperation->getKey(),
                'original_entry_id' => null,
                'user_id' => $operator->getKey(),
                'started_at' => $startedAt,
                'ended_at' => $endedAt,
                'duration_seconds' => $durationSeconds,
                'entry_type' => OperationTimeEntryType::Manual,
                'created_by' => $actor->getKey(),
                'corrected_by' => null,
                'correction_reason' => null,
                'notes' => $notes,
            ]);

            $this->audit->record(
                $lockedOperation,
                $workOrder,
                WorkshopOperationAuditEvent::ManualTimeRecorded,
                $actor,
                $lockedOperation->status->value,
                $lockedOperation->status->value,
                $notes,
                [
                    'time_entry_id' => $entry->getKey(),
                    'operator_id' => $operator->getKey(),
                    'duration_seconds' => $durationSeconds,
                    'started_at' => $startedAt->toIso8601String(),
                    'ended_at' => $endedAt->toIso8601String(),
                ],
            );

            return $entry->refresh();
        }, attempts: 3);
    }

    public function correct(
        OperationTimeEntry $entry,
        int $correctedDurationSeconds,
        string $reason,
        User $actor,
    ): OperationTimeEntry {
        if ($correctedDurationSeconds < 0) {
            $this->fail('corrected_duration_seconds', 'La durée corrigée ne peut pas être négative.');
        }

        if ($correctedDurationSeconds > self::MAX_DURATION_SECONDS) {
            $this->fail('corrected_duration_seconds', 'La durée corrigée est incohérente.');
        }

        $reason = $this->normalizeReason($reason);

        return DB::transaction(function () use (
            $entry,
            $correctedDurationSeconds,
            $reason,
            $actor,
        ): OperationTimeEntry {
            $workOrderId = WorkOrderOperation::query()
                ->whereKey($entry->work_order_operation_id)
                ->value('work_order_id');

            if ($workOrderId === null) {
                $this->fail('operation', 'Cette entrée de temps n\'appartient pas à un OF existant.');
            }

            $workOrder = WorkOrder::query()->lockForUpdate()->find($workOrderId);

            if ($workOrder === null) {
                $this->fail('work_order', 'Cet ordre de fabrication n\'existe plus.');
            }

            $lockedOperation = $workOrder->operations()
                ->whereKey($entry->work_order_operation_id)
                ->lockForUpdate()
                ->first();

            if ($lockedOperation === null) {
                $this->fail('operation', 'Cette entrée de temps n\'appartient pas à cet OF.');
            }

            $original = OperationTimeEntry::query()
                ->whereKey($entry->getKey())
                ->lockForUpdate()
                ->first();

            if ($original === null) {
                $this->fail('time_entry', 'Cette entrée de temps n\'existe plus.');
            }

            if (! $actor->can('correctTime', $lockedOperation)) {
                $this->fail('time_entries', 'Vous n\'êtes pas autorisé à corriger un temps.');
            }

            if ($original->entry_type === OperationTimeEntryType::Adjustment) {
                $this->fail('time_entry', 'Une correction ne peut pas corriger une autre correction.');
            }

            if ($original->ended_at === null || $original->duration_seconds === null) {
                $this->fail('time_entry', 'Une session active doit être arrêtée avant d\'être corrigée.');
            }

            $correction = OperationTimeEntry::query()->create([
                'work_order_operation_id' => $original->work_order_operation_id,
                'original_entry_id' => $original->getKey(),
                'user_id' => $original->user_id,
                'started_at' => $original->started_at,
                'ended_at' => $original->ended_at,
                'duration_seconds' => $correctedDurationSeconds,
                'entry_type' => OperationTimeEntryType::Adjustment,
                'created_by' => $actor->getKey(),
                'corrected_by' => $actor->getKey(),
                'correction_reason' => $reason,
                'notes' => $original->notes,
            ]);

            $this->audit->record(
                $lockedOperation,
                $workOrder,
                WorkshopOperationAuditEvent::TimeCorrected,
                $actor,
                $lockedOperation->status->value,
                $lockedOperation->status->value,
                $reason,
                [
                    'original_entry_id' => $original->getKey(),
                    'correction_entry_id' => $correction->getKey(),
                    'operator_id' => $original->user_id,
                    'original_duration_seconds' => $original->duration_seconds,
                    'corrected_duration_seconds' => $correctedDurationSeconds,
                ],
            );

            return $correction->refresh();
        }, attempts: 3);
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

    protected function normalizeReason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            $this->fail('correction_reason', 'Le motif de correction est obligatoire.');
        }

        if (mb_strlen($reason) > 2000) {
            $this->fail('correction_reason', 'Le motif ne peut pas dépasser 2 000 caractères.');
        }

        return $reason;
    }

    protected function normalizeOptionalText(?string $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > 2000) {
            $this->fail($field, 'Le texte ne peut pas dépasser 2 000 caractères.');
        }

        return $value;
    }

    protected function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
