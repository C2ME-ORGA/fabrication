<?php

namespace Database\Factories;

use App\Enums\DocumentVersionStatus;
use App\Models\Document;
use App\Models\DocumentVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DocumentVersion>
 */
class DocumentVersionFactory extends Factory
{
    protected $model = DocumentVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'revision' => 'A',
            'version_number' => 1,
            'original_filename' => 'plan.pdf',
            'storage_disk' => 'documents',
            'storage_path' => 'documents/'.Str::uuid().'.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 1024,
            'sha256' => str_repeat('a', 64),
            'status' => DocumentVersionStatus::Draft,
            'uploaded_by' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (): array => [
            'status' => DocumentVersionStatus::Approved,
            'validated_at' => now(),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (): array => ['status' => DocumentVersionStatus::PendingValidation]);
    }
}
