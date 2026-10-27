<?php

namespace App\Models;

use App\Enums\DocumentCategory;
use App\Enums\DocumentStatus;
use App\Enums\DocumentVersionStatus;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['job_id', 'work_order_id', 'category', 'title', 'reference', 'description'])]
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => DocumentCategory::class,
            'status' => DocumentStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Job, $this>
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    /**
     * @return BelongsTo<WorkOrder, $this>
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<DocumentVersion, $this>
     */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'current_version_id');
    }

    /**
     * @return HasMany<DocumentVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderBy('version_number');
    }

    /**
     * Latest approved revision, used for OF applicability. This is separate
     * from `current_version_id`, which points to the latest uploaded revision
     * even while that revision is still a draft.
     *
     * @return HasOne<DocumentVersion, $this>
     */
    public function latestApprovedVersion(): HasOne
    {
        return $this->hasOne(DocumentVersion::class)
            ->ofMany(
                ['version_number' => 'max'],
                fn (Builder $query): Builder => $query->where('status', DocumentVersionStatus::Approved->value),
            );
    }

    /**
     * @return HasMany<WorkOrderDocumentSnapshot, $this>
     */
    public function snapshots(): HasMany
    {
        return $this->hasMany(WorkOrderDocumentSnapshot::class);
    }

    /**
     * @return HasMany<DocumentAuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(DocumentAuditLog::class)->latest('created_at');
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', DocumentStatus::Active);
    }

    public function isArchived(): bool
    {
        return $this->status === DocumentStatus::Archived;
    }

    /**
     * The affaire a document belongs to: explicit job link, otherwise the
     * affaire of its work order.
     */
    public function resolvedJobId(): ?int
    {
        return $this->job_id ?? $this->workOrder?->job_id;
    }
}
