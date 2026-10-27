<?php

namespace App\Models;

use App\Enums\JobStatus;
use Database\Factories\JobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'client_name', 'name', 'site_address', 'status', 'notes'])]
class Job extends Model
{
    /** @use HasFactory<JobFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => JobStatus::class,
        ];
    }

    /**
     * @return HasMany<WorkOrder, $this>
     */
    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereIn('status', [JobStatus::Draft, JobStatus::Active]);
    }

    public function acceptsNewWorkOrders(): bool
    {
        return $this->status->acceptsNewWorkOrders();
    }

    public function isClosed(): bool
    {
        return $this->status === JobStatus::Closed;
    }
}
