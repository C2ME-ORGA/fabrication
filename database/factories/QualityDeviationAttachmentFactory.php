<?php

namespace Database\Factories;

use App\Models\QualityDeviation;
use App\Models\QualityDeviationAttachment;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<QualityDeviationAttachment>
 */
class QualityDeviationAttachmentFactory extends Factory
{
    protected $model = QualityDeviationAttachment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quality_deviation_id' => QualityDeviation::factory(),
            'work_order_id' => WorkOrder::factory(),
            'disk' => 'documents',
            'path' => 'quality/deviations/'.Str::uuid()->toString().'.pdf',
            'original_filename' => 'preuve-derogation.pdf',
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'sha256' => hash('sha256', Str::uuid()->toString()),
            'uploaded_by' => User::factory(),
            'created_at' => now(),
        ];
    }
}
