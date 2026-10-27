<?php

namespace App\Services;

use App\Enums\DocumentAuditAction;
use App\Enums\DocumentCategory;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\Job;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DocumentService
{
    public function __construct(
        protected DocumentAuditService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor = null): Document
    {
        $data = $this->validate($data);
        $this->assertAssociation($data['job_id'] ?? null, $data['work_order_id'] ?? null);

        return DB::transaction(function () use ($data, $actor): Document {
            $document = new Document;

            $document->fill(Arr::only($data, [
                'job_id',
                'work_order_id',
                'category',
                'title',
                'reference',
                'description',
            ]));

            $document->status = DocumentStatus::Active;
            $document->created_by = $actor?->getKey();
            $document->save();

            $this->audit->log(DocumentAuditAction::DocumentCreated, $document, actor: $actor);

            return $document->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Document $document, array $data, ?User $actor = null): Document
    {
        $data = $this->validate($data);

        return DB::transaction(function () use ($document, $data, $actor): Document {
            $locked = Document::query()->lockForUpdate()->findOrFail($document->getKey());

            if ($locked->isArchived()) {
                throw ValidationException::withMessages([
                    'status' => 'Un document archivé ne peut plus être modifié.',
                ]);
            }

            $jobId = array_key_exists('job_id', $data) ? $data['job_id'] : $locked->job_id;
            $workOrderId = array_key_exists('work_order_id', $data) ? $data['work_order_id'] : $locked->work_order_id;

            $this->assertAssociation($jobId, $workOrderId);

            $associationChanged = (int) ($jobId ?? 0) !== (int) ($locked->job_id ?? 0)
                || (int) ($workOrderId ?? 0) !== (int) ($locked->work_order_id ?? 0);

            if ($associationChanged && ($locked->versions()->exists() || $locked->snapshots()->exists())) {
                throw ValidationException::withMessages([
                    'job_id' => "L'affaire ou l'OF d'un document versionné ne peut plus être changé.",
                ]);
            }

            $locked->fill(Arr::only($data, [
                'job_id',
                'work_order_id',
                'category',
                'title',
                'reference',
                'description',
            ]));

            $dirtyKeys = array_keys($locked->getDirty());
            $locked->save();

            $this->audit->log(
                DocumentAuditAction::DocumentUpdated,
                $locked,
                actor: $actor,
                metadata: ['changed' => $dirtyKeys],
            );

            return $locked->refresh();
        });
    }

    public function archive(Document $document, ?User $actor = null, ?string $comment = null): Document
    {
        return DB::transaction(function () use ($document, $actor, $comment): Document {
            $locked = Document::query()->lockForUpdate()->findOrFail($document->getKey());

            if ($locked->isArchived()) {
                throw ValidationException::withMessages([
                    'status' => 'Ce document est déjà archivé.',
                ]);
            }

            $locked->status = DocumentStatus::Archived;
            $locked->save();

            $this->audit->log(DocumentAuditAction::DocumentArchived, $locked, actor: $actor, comment: $comment);

            return $locked->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function validate(array $data): array
    {
        return Validator::make($data, [
            'job_id' => ['nullable', 'integer', Rule::exists('jobs', 'id')],
            'work_order_id' => ['nullable', 'integer', Rule::exists('work_orders', 'id')],
            'category' => ['required', Rule::enum(DocumentCategory::class)],
            'title' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ])->validate();
    }

    protected function assertAssociation(?int $jobId, ?int $workOrderId): void
    {
        if ($jobId === null && $workOrderId === null) {
            throw ValidationException::withMessages([
                'job_id' => 'Un document doit être rattaché à une affaire, à un OF, ou aux deux.',
            ]);
        }

        if ($workOrderId === null) {
            return;
        }

        $workOrder = WorkOrder::query()->find($workOrderId);

        if ($workOrder === null) {
            throw ValidationException::withMessages([
                'work_order_id' => "L'ordre de fabrication sélectionné est introuvable.",
            ]);
        }

        if ($jobId !== null && (int) $workOrder->job_id !== (int) $jobId) {
            throw ValidationException::withMessages([
                'work_order_id' => "L'ordre de fabrication n'appartient pas à l'affaire sélectionnée.",
            ]);
        }

        if ($jobId === null && ! Job::query()->whereKey($workOrder->job_id)->exists()) {
            throw ValidationException::withMessages([
                'job_id' => "L'affaire de l'ordre de fabrication est introuvable.",
            ]);
        }
    }
}
