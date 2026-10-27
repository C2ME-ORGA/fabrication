<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentAuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentAuditLog>
 */
class DocumentAuditLogFactory extends Factory
{
    protected $model = DocumentAuditLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'document_version_id' => null,
            'work_order_id' => null,
            'action' => 'document_created',
            'comment' => null,
            'metadata' => null,
        ];
    }
}
