<?php

namespace Database\Factories;

use App\Models\NonConformity;
use App\Models\NonConformityAttachment;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<NonConformityAttachment>
 */
class NonConformityAttachmentFactory extends Factory
{
    protected $model = NonConformityAttachment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'non_conformity_id' => NonConformity::factory(),
            'work_order_id' => WorkOrder::factory(),
            'disk' => 'documents',
            'path' => 'quality/non-conformities/'.Str::uuid()->toString().'.png',
            'original_filename' => 'preuve-nc.png',
            'extension' => 'png',
            'mime_type' => 'image/png',
            'size_bytes' => 1024,
            'sha256' => hash('sha256', Str::uuid()->toString()),
            'uploaded_by' => User::factory(),
            'created_at' => now(),
        ];
    }
}
