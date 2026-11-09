<?php

namespace Database\Factories;

use App\Enums\QualityAuditEvent;
use App\Enums\QualityAuditSubject;
use App\Models\QualityAuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QualityAuditLog>
 */
class QualityAuditLogFactory extends Factory
{
    protected $model = QualityAuditLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'work_order_id' => null,
            'work_order_number' => null,
            'subject_type' => QualityAuditSubject::Template,
            'subject_id' => null,
            'subject_reference' => 'CTL-TEST',
            'user_id' => null,
            'event' => QualityAuditEvent::TemplateCreated,
            'from_status' => null,
            'to_status' => null,
            'comment' => null,
            'metadata' => null,
        ];
    }
}
