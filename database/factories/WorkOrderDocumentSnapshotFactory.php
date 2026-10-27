<?php

namespace Database\Factories;

use App\Enums\DocumentCategory;
use App\Enums\DocumentSnapshotReason;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\WorkOrder;
use App\Models\WorkOrderDocumentSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkOrderDocumentSnapshot>
 */
class WorkOrderDocumentSnapshotFactory extends Factory
{
    protected $model = WorkOrderDocumentSnapshot::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'work_order_id' => WorkOrder::factory(),
            'document_id' => Document::factory(),
            'document_version_id' => DocumentVersion::factory(),
            'category' => DocumentCategory::Other,
            'title' => 'Document figé',
            'reference' => null,
            'revision' => 'A',
            'version_number' => 1,
            'sha256' => str_repeat('a', 64),
            'captured_at' => now(),
            'reason' => DocumentSnapshotReason::Release,
        ];
    }
}
