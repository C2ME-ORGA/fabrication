<?php

namespace Database\Factories;

use App\Models\QualityControlResult;
use App\Models\QualityControlResultAttachment;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<QualityControlResultAttachment>
 */
class QualityControlResultAttachmentFactory extends Factory
{
    protected $model = QualityControlResultAttachment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quality_control_result_id' => QualityControlResult::factory(),
            'work_order_id' => WorkOrder::factory(),
            'disk' => 'documents',
            'path' => 'quality/'.Str::uuid()->toString().'.png',
            'original_filename' => 'preuve.png',
            'extension' => 'png',
            'mime_type' => 'image/png',
            'size_bytes' => 1024,
            'sha256' => hash('sha256', Str::uuid()->toString()),
            'uploaded_by' => User::factory(),
            'created_at' => now(),
        ];
    }
}
