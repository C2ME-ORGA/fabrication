<?php

namespace App\Services;

use App\Enums\DocumentAuditAction;
use App\Enums\DocumentVersionStatus;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class DocumentVersionService
{
    public function __construct(
        protected DocumentStorageService $storage,
        protected DocumentAuditService $audit,
    ) {}

    public function upload(
        Document $document,
        UploadedFile $file,
        string $revision,
        ?User $actor = null,
        ?string $comment = null,
    ): DocumentVersion {
        $revision = $this->normalizeRevision($revision);

        if ($document->isArchived()) {
            throw ValidationException::withMessages([
                'file' => 'Impossible de téléverser une version sur un document archivé.',
            ]);
        }

        if ($this->revisionExists($document, $revision)) {
            throw ValidationException::withMessages([
                'revision' => "L'indice {$revision} est déjà utilisé pour ce document.",
            ]);
        }

        // The file is written first. If the database transaction fails, the
        // new file is removed; existing version files are never touched.
        $stored = $this->storage->store($file, $document);

        try {
            return DB::transaction(function () use ($document, $stored, $revision, $actor, $comment): DocumentVersion {
                $locked = Document::query()->lockForUpdate()->findOrFail($document->getKey());

                if ($locked->isArchived()) {
                    throw ValidationException::withMessages([
                        'file' => 'Impossible de téléverser une version sur un document archivé.',
                    ]);
                }

                if ($this->revisionExists($locked, $revision)) {
                    throw ValidationException::withMessages([
                        'revision' => "L'indice {$revision} est déjà utilisé pour ce document.",
                    ]);
                }

                $nextNumber = ((int) DocumentVersion::query()
                    ->where('document_id', $locked->getKey())
                    ->max('version_number')) + 1;

                $version = new DocumentVersion;
                $version->document_id = $locked->getKey();
                $version->fill([
                    'revision' => $revision,
                    'original_filename' => $stored['original_filename'],
                    'mime_type' => $stored['mime_type'],
                    'extension' => $stored['extension'],
                    'size_bytes' => $stored['size_bytes'],
                    'sha256' => $stored['sha256'],
                ]);
                $version->version_number = $nextNumber;
                $version->storage_disk = $stored['disk'];
                $version->storage_path = $stored['path'];
                $version->status = DocumentVersionStatus::Draft;
                $version->uploaded_by = $actor?->getKey() ?? Auth::id();
                $version->save();

                $locked->current_version_id = $version->getKey();
                $locked->save();

                $this->audit->log(
                    DocumentAuditAction::VersionUploaded,
                    $locked,
                    $version,
                    actor: $actor,
                    comment: $comment,
                    metadata: [
                        'revision' => $revision,
                        'version_number' => $nextNumber,
                        'sha256' => $stored['sha256'],
                        'size_bytes' => $stored['size_bytes'],
                    ],
                );

                return $version->refresh();
            }, attempts: 3);
        } catch (Throwable $exception) {
            $this->storage->delete($stored['disk'], $stored['path']);

            throw $exception;
        }
    }

    public function submit(DocumentVersion $version, ?User $actor = null): DocumentVersion
    {
        return DB::transaction(function () use ($version, $actor): DocumentVersion {
            // Same lock order as upload/approve: document first, then version.
            $lockedDocument = Document::query()->lockForUpdate()->findOrFail($version->document_id);
            $locked = $this->lock($version);

            if ($locked->status !== DocumentVersionStatus::Draft) {
                $this->failStatus($locked, DocumentVersionStatus::PendingValidation);
            }

            if ($lockedDocument->isArchived()) {
                throw ValidationException::withMessages([
                    'status' => 'Le document est archivé.',
                ]);
            }

            $locked->status = DocumentVersionStatus::PendingValidation;
            $locked->save();

            $this->audit->log(DocumentAuditAction::VersionSubmitted, $locked->document, $locked, actor: $actor);

            return $locked->refresh();
        }, attempts: 3);
    }

    public function approve(DocumentVersion $version, ?User $actor = null, ?string $comment = null): DocumentVersion
    {
        return DB::transaction(function () use ($version, $actor, $comment): DocumentVersion {
            $lockedDocument = Document::query()->lockForUpdate()->findOrFail($version->document_id);
            $locked = DocumentVersion::query()->lockForUpdate()->findOrFail($version->getKey());

            if ($lockedDocument->isArchived()) {
                throw ValidationException::withMessages([
                    'status' => 'Le document est archivé.',
                ]);
            }

            if ($locked->status !== DocumentVersionStatus::PendingValidation) {
                $this->failStatus($locked, DocumentVersionStatus::Approved);
            }

            $newerApproved = DocumentVersion::query()
                ->where('document_id', $locked->document_id)
                ->where('status', DocumentVersionStatus::Approved->value)
                ->where('version_number', '>', $locked->version_number)
                ->exists();

            if ($newerApproved) {
                throw ValidationException::withMessages([
                    'status' => 'Une version plus récente est déjà approuvée : approuvez une nouvelle révision plutôt que cette version.',
                ]);
            }

            $superseded = DocumentVersion::query()
                ->where('document_id', $locked->document_id)
                ->where('status', DocumentVersionStatus::Approved->value)
                ->whereKeyNot($locked->getKey())
                ->get();

            foreach ($superseded as $oldVersion) {
                $oldVersion->status = DocumentVersionStatus::Superseded;
                $oldVersion->save();

                $this->audit->log(
                    DocumentAuditAction::VersionSuperseded,
                    $lockedDocument,
                    $oldVersion,
                    actor: $actor,
                    metadata: ['superseded_by' => $locked->getKey()],
                );
            }

            $locked->status = DocumentVersionStatus::Approved;
            $locked->validated_by = $actor?->getKey() ?? Auth::id();
            $locked->validated_at = now();
            $locked->validation_comment = $comment;
            $locked->save();

            $this->audit->log(
                DocumentAuditAction::VersionApproved,
                $lockedDocument,
                $locked,
                actor: $actor,
                comment: $comment,
                metadata: ['superseded_count' => $superseded->count()],
            );

            return $locked->refresh();
        }, attempts: 3);
    }

    public function reject(DocumentVersion $version, ?User $actor = null, ?string $comment = null): DocumentVersion
    {
        $comment = trim((string) $comment);

        if ($comment === '') {
            throw ValidationException::withMessages([
                'validation_comment' => 'Un motif est obligatoire pour refuser une version.',
            ]);
        }

        return DB::transaction(function () use ($version, $actor, $comment): DocumentVersion {
            Document::query()->lockForUpdate()->findOrFail($version->document_id);
            $locked = $this->lock($version);

            if ($locked->status !== DocumentVersionStatus::PendingValidation) {
                $this->failStatus($locked, DocumentVersionStatus::Rejected);
            }

            $locked->status = DocumentVersionStatus::Rejected;
            $locked->validated_by = $actor?->getKey() ?? Auth::id();
            $locked->validated_at = now();
            $locked->validation_comment = $comment;
            $locked->save();

            $this->audit->log(
                DocumentAuditAction::VersionRejected,
                $locked->document,
                $locked,
                actor: $actor,
                comment: $comment,
            );

            return $locked->refresh();
        }, attempts: 3);
    }

    public function suggestRevision(Document $document): string
    {
        $lastRevision = DocumentVersion::query()
            ->where('document_id', $document->getKey())
            ->orderByDesc('version_number')
            ->value('revision');

        if (! is_string($lastRevision) || $lastRevision === '') {
            return 'A';
        }

        $lastRevision = strtoupper(trim($lastRevision));

        if (preg_match('/^([A-Z]*)(\d+)$/', $lastRevision, $matches) === 1) {
            $number = str_pad((string) ((int) $matches[2] + 1), strlen($matches[2]), '0', STR_PAD_LEFT);

            return $matches[1].$number;
        }

        if (preg_match('/^[A-Z]$/', $lastRevision) === 1) {
            return $lastRevision === 'Z' ? 'AA' : chr(ord($lastRevision) + 1);
        }

        return $lastRevision.'-1';
    }

    protected function lock(DocumentVersion $version): DocumentVersion
    {
        $locked = DocumentVersion::query()->lockForUpdate()->find($version->getKey());

        if ($locked === null) {
            throw ValidationException::withMessages([
                'version' => "Cette version documentaire n'existe plus.",
            ]);
        }

        return $locked;
    }

    protected function revisionExists(Document $document, string $revision): bool
    {
        return DocumentVersion::query()
            ->where('document_id', $document->getKey())
            ->where('revision', $revision)
            ->exists();
    }

    protected function normalizeRevision(string $revision): string
    {
        $revision = strtoupper(trim($revision));
        $pattern = (string) config('documents.revision_pattern');

        if ($revision === '' || preg_match($pattern, $revision) !== 1) {
            throw ValidationException::withMessages([
                'revision' => 'Indice invalide. Utilisez des lettres/chiffres, sans espace (ex. A, 01, A01).',
            ]);
        }

        return $revision;
    }

    protected function failStatus(DocumentVersion $version, DocumentVersionStatus $target): never
    {
        throw ValidationException::withMessages([
            'status' => "Transition de version interdite : « {$version->status->getLabel()} » vers « {$target->getLabel()} ».",
        ]);
    }
}
