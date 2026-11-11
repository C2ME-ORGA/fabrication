<?php

namespace Database\Factories;

use App\Enums\GeneratedDocumentStatus;
use App\Enums\GeneratedDocumentType;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GeneratedDocument>
 */
class GeneratedDocumentFactory extends Factory
{
    protected $model = GeneratedDocument::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $reference = 'PDF-'.Str::upper(Str::random(10));

        return [
            'work_order_id' => WorkOrder::factory(),
            'shipment_id' => null,
            'type' => GeneratedDocumentType::WorkOrderPdf,
            'scope_key' => 'test:'.Str::uuid(),
            'version' => 1,
            'reference' => $reference,
            'status' => GeneratedDocumentStatus::Archived,
            'disk' => 'generated_documents',
            'path' => 'work-orders/test/'.Str::uuid().'.pdf',
            'original_filename' => $reference.'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'sha256' => hash('sha256', $reference),
            'template_version' => '1',
            'source_data_hash' => hash('sha256', $reference.'-data'),
            'of_revision' => null,
            'revision_snapshot' => null,
            'visa_snapshot' => null,
            'generated_by' => User::factory(),
            'generated_at' => now(),
            'supersedes_id' => null,
            'superseded_at' => null,
            'notes' => null,
        ];
    }
}
