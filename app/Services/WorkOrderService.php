<?php

namespace App\Services;

use App\Enums\JobStatus;
use App\Enums\WorkOrderPriority;
use App\Enums\WorkOrderStatus;
use App\Models\Job;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Single entry point for work order mutations.
 *
 * Filament forms/actions call this service; they never write the number,
 * status, release fields or dates directly. Every rule is enforced here so a
 * Livewire payload cannot bypass it.
 */
class WorkOrderService
{
    public function __construct(
        protected WorkOrderNumberGenerator $numbers,
        protected DocumentSnapshotService $documentSnapshots,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor = null): WorkOrder
    {
        $this->assertNoProtectedFields($data);

        return DB::transaction(function () use ($data, $actor): WorkOrder {
            $data = $this->validate($data);

            $job = Job::query()->lockForUpdate()->find($data['job_id']);

            if ($job === null) {
                $this->fail('job_id', "L'affaire sélectionnée est introuvable.");
            }

            if (! $job->acceptsNewWorkOrders()) {
                $this->fail(
                    'job_id',
                    "Impossible de créer un ordre de fabrication pour l'affaire {$job->code} : son statut est « {$job->status->getLabel()} ».",
                );
            }

            $workOrder = new WorkOrder;

            $workOrder->fill(Arr::only($data, [
                'job_id',
                'title',
                'description',
                'priority',
                'planned_start_at',
                'planned_end_at',
                'notes',
            ]));

            $workOrder->number = $this->numbers->nextNumber();
            $workOrder->status = WorkOrderStatus::Draft;
            $workOrder->priority ??= WorkOrderPriority::Normal;
            $workOrder->created_by = $actor?->getKey() ?? Auth::id();
            $workOrder->save();

            return $workOrder->refresh();
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(WorkOrder $workOrder, array $data, ?User $actor = null): WorkOrder
    {
        $this->assertNoProtectedFields($data);

        return DB::transaction(function () use ($workOrder, $data): WorkOrder {
            $locked = $this->lock($workOrder);

            $this->assertEditable($locked);

            if (array_key_exists('job_id', $data) && (int) $data['job_id'] !== (int) $locked->job_id) {
                $this->fail('job_id', "L'affaire d'un ordre de fabrication ne peut pas être changée.");
            }

            $data = $this->validate($data, partial: true);

            $locked->fill(Arr::only($data, [
                'title',
                'description',
                'priority',
                'planned_start_at',
                'planned_end_at',
                'notes',
            ]));

            $this->assertChronology(
                $locked->planned_start_at,
                $locked->planned_end_at,
                'planned_end_at',
                'La fin prévisionnelle ne peut pas précéder le début prévisionnel.',
            );

            $locked->save();

            return $locked->refresh();
        }, attempts: 3);
    }

    public function transitionTo(WorkOrder $workOrder, WorkOrderStatus $target, ?User $actor = null): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $target, $actor): WorkOrder {
            $locked = $this->lock($workOrder);
            $from = $locked->status;

            if (! $from->canTransitionTo($target)) {
                $this->fail(
                    'status',
                    "Transition de statut interdite : « {$from->getLabel()} » vers « {$target->getLabel()} ».",
                );
            }

            if ($target === WorkOrderStatus::Released) {
                $job = Job::query()->lockForUpdate()->find($locked->job_id);

                if ($job === null || $job->status !== JobStatus::Active) {
                    $this->fail(
                        'status',
                        'Un ordre de fabrication ne peut être libéré que si son affaire est active.',
                    );
                }

                $locked->released_by = $actor?->getKey() ?? Auth::id();
                $locked->released_at = now();

                // Freeze the approved document versions applicable at release.
                // The snapshot rows are written in this same transaction.
                $this->documentSnapshots->captureForRelease($locked, $actor);
            }

            if ($target === WorkOrderStatus::InProgress && $locked->actual_start_at === null) {
                $locked->actual_start_at = now();
            }

            if ($target === WorkOrderStatus::Completed) {
                $locked->actual_end_at ??= now();
            }

            $locked->status = $target;
            $locked->save();

            return $locked->refresh();
        }, attempts: 3);
    }

    /**
     * Only drafts can be deleted: any later state carries history.
     */
    public function delete(WorkOrder $workOrder): void
    {
        DB::transaction(function () use ($workOrder): void {
            $locked = $this->lock($workOrder);

            if ($locked->status !== WorkOrderStatus::Draft) {
                $this->fail(
                    'status',
                    'Seul un ordre de fabrication au statut « Brouillon » peut être supprimé.',
                );
            }

            if ($locked->documents()->exists() || $locked->documentSnapshots()->exists()) {
                $this->fail(
                    'status',
                    'Un ordre de fabrication rattaché à des documents ou à des références documentaires ne peut pas être supprimé.',
                );
            }

            $locked->delete();
        });
    }

    protected function lock(WorkOrder $workOrder): WorkOrder
    {
        $locked = WorkOrder::query()->lockForUpdate()->find($workOrder->getKey());

        if ($locked === null) {
            $this->fail('id', "Cet ordre de fabrication n'existe plus.");
        }

        return $locked;
    }

    protected function assertEditable(WorkOrder $workOrder): void
    {
        if ($workOrder->isLocked()) {
            $this->fail(
                'status',
                "Un ordre de fabrication « {$workOrder->status->getLabel()} » ne peut plus être modifié.",
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function validate(array $data, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        $rules = [
            'job_id' => [$required, 'integer', Rule::exists('jobs', 'id')],
            'title' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            // On create the column default (`normal`) applies when omitted.
            // On update an explicit null would violate the NOT NULL column,
            // so it is rejected as a validation error instead of a DB error.
            'priority' => $partial
                ? ['sometimes', 'required', Rule::enum(WorkOrderPriority::class)]
                : ['nullable', Rule::enum(WorkOrderPriority::class)],
            'planned_start_at' => ['nullable', 'date'],
            'planned_end_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];

        $validated = Validator::make($data, $rules)->validate();

        $start = Arr::get($validated, 'planned_start_at', $data['planned_start_at'] ?? null);
        $end = Arr::get($validated, 'planned_end_at', $data['planned_end_at'] ?? null);

        if ($start !== null && $end !== null) {
            $this->assertChronology(
                Carbon::parse($start),
                Carbon::parse($end),
                'planned_end_at',
                'La fin prévisionnelle ne peut pas précéder le début prévisionnel.',
            );
        }

        return $validated;
    }

    protected function assertChronology(?Carbon $start, ?Carbon $end, string $field, string $message): void
    {
        if ($start !== null && $end !== null && $end->lt($start)) {
            $this->fail($field, $message);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function assertNoProtectedFields(array $data): void
    {
        $forbidden = array_values(array_filter(
            WorkOrder::PROTECTED_FIELDS,
            fn (string $field): bool => array_key_exists($field, $data),
        ));

        if ($forbidden !== []) {
            throw ValidationException::withMessages([
                'work_order' => 'Champ(s) non modifiable(s) directement : '.implode(', ', $forbidden).'.',
            ]);
        }
    }

    protected function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
