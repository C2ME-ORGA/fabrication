<?php

namespace App\Services;

use App\Enums\GeneratedDocumentStatus;
use App\Enums\GeneratedDocumentType;
use App\Models\GeneratedDocument;
use App\Models\Shipment;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class GeneratedDocumentArchiveService
{
    public function __construct(
        protected GeneratedDocumentStorageService $storage,
        protected GeneratedDocumentAuditService $audit,
    ) {}

    /**
     * Archive des documents déjà stockés pour une expédition.
     *
     * Cette méthode ne gère ni le stockage ni les transactions : elle doit
     * être appelée dans la transaction de validation, après verrouillage de
     * l'OF et de l'expédition. Le stockage et la compensation des fichiers
     * sont sous la responsabilité de l'appelant.
     *
     * @param  array<int, array{type: GeneratedDocumentType, stored: array{disk: string, path: string, original_filename: string, mime_type: string, size_bytes: int, sha256: string}, metadata: array<string, mixed>}>  $documents
     * @return Collection<int, GeneratedDocument>
     */
    public function archiveStoredForShipment(
        Shipment $shipment,
        array $documents,
        ?User $actor = null,
    ): Collection {
        $shipment->loadMissing('workOrder');
        $created = new Collection;

        foreach ($documents as $entry) {
            $type = $entry['type'];
            $stored = $entry['stored'];
            $metadata = $entry['metadata'] ?? [];
            $scopeKey = 'shp:'.$shipment->getKey().':'.$type->value;

            $current = GeneratedDocument::query()
                ->where('scope_key', $scopeKey)
                ->where('status', GeneratedDocumentStatus::Archived->value)
                ->orderByDesc('version')
                ->lockForUpdate()
                ->first();

            $version = ((int) ($current?->version ?? 0)) + 1;
            $reference = $shipment->number
                .'-'.$type->getReferencePrefix()
                .'-'.str_pad((string) $version, 4, '0', STR_PAD_LEFT);

            if ($current !== null) {
                $current->forceFill([
                    'status' => GeneratedDocumentStatus::Superseded,
                    'superseded_at' => now(),
                ])->save();
            }

            $document = GeneratedDocument::query()->create([
                'work_order_id' => $shipment->work_order_id,
                'shipment_id' => $shipment->getKey(),
                'type' => $type,
                'scope_key' => $scopeKey,
                'version' => $version,
                'reference' => $reference,
                'status' => GeneratedDocumentStatus::Archived,
                'disk' => $stored['disk'],
                'path' => $stored['path'],
                'original_filename' => $reference.'.pdf',
                'mime_type' => $stored['mime_type'],
                'size_bytes' => $stored['size_bytes'],
                'sha256' => $stored['sha256'],
                'template_version' => (string) ($metadata['template_version'] ?? config('pdf.template_version', '1')),
                'source_data_hash' => (string) ($metadata['source_data_hash'] ?? hash('sha256', $stored['sha256'])),
                'of_revision' => $metadata['of_revision'] ?? null,
                'revision_snapshot' => $metadata['revision_snapshot'] ?? null,
                'visa_snapshot' => $metadata['visa_snapshot'] ?? null,
                'generated_by' => $actor?->getKey(),
                'generated_at' => now(),
                'supersedes_id' => $current?->getKey(),
                'superseded_at' => null,
                'notes' => $metadata['notes'] ?? null,
            ]);

            $this->audit->recordGenerated($document, $actor);

            $created->push($document->refresh());
        }

        return $created;
    }

    /**
     * Archive un PDF d'OF et supersède la génération courante précédente.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function archive(
        WorkOrder $workOrder,
        string $contents,
        GeneratedDocumentType $type,
        array $metadata = [],
        ?User $actor = null,
    ): GeneratedDocument {
        $directory = 'work-orders/'.$workOrder->getKey().'/'.$type->value;

        $stored = $this->storage->store(
            $contents,
            $directory,
            $workOrder->number.'-'.$type->getReferencePrefix().'.pdf',
        );

        try {
            return DB::transaction(function () use ($workOrder, $type, $stored, $metadata, $actor): GeneratedDocument {
                $locked = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->getKey());
                $scopeKey = 'wo:'.$locked->getKey().':'.$type->value;

                $current = GeneratedDocument::query()
                    ->where('scope_key', $scopeKey)
                    ->where('status', GeneratedDocumentStatus::Archived->value)
                    ->orderByDesc('version')
                    ->lockForUpdate()
                    ->first();

                $version = ((int) ($current?->version ?? 0)) + 1;
                $reference = $locked->number.'-'.$type->getReferencePrefix().'-'.str_pad((string) $version, 4, '0', STR_PAD_LEFT);

                if ($current !== null) {
                    $current->forceFill([
                        'status' => GeneratedDocumentStatus::Superseded,
                        'superseded_at' => now(),
                    ])->save();
                }

                $document = GeneratedDocument::query()->create([
                    'work_order_id' => $locked->getKey(),
                    'shipment_id' => null,
                    'type' => $type,
                    'scope_key' => $scopeKey,
                    'version' => $version,
                    'reference' => $reference,
                    'status' => GeneratedDocumentStatus::Archived,
                    'disk' => $stored['disk'],
                    'path' => $stored['path'],
                    'original_filename' => $reference.'.pdf',
                    'mime_type' => $stored['mime_type'],
                    'size_bytes' => $stored['size_bytes'],
                    'sha256' => $stored['sha256'],
                    'template_version' => (string) ($metadata['template_version'] ?? config('pdf.template_version', '1')),
                    'source_data_hash' => (string) ($metadata['source_data_hash'] ?? hash('sha256', $contents)),
                    'of_revision' => $metadata['of_revision'] ?? null,
                    'revision_snapshot' => $metadata['revision_snapshot'] ?? null,
                    'visa_snapshot' => $metadata['visa_snapshot'] ?? null,
                    'generated_by' => $actor?->getKey(),
                    'generated_at' => now(),
                    'supersedes_id' => $current?->getKey(),
                    'superseded_at' => null,
                    'notes' => $metadata['notes'] ?? null,
                ]);

                $this->audit->recordGenerated($document, $actor);

                return $document->refresh();
            }, attempts: 3);
        } catch (Throwable $exception) {
            $this->storage->delete($stored['disk'], $stored['path']);

            if ($exception instanceof ValidationException) {
                throw $exception;
            }

            report($exception);

            throw ValidationException::withMessages([
                'pdf' => "Le PDF n'a pas pu être archivé.",
            ]);
        }
    }
}
