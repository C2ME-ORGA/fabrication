<?php

namespace Tests\Feature;

use App\Enums\WorkshopOperationAuditEvent;
use App\Models\WorkOrderOperation;
use App\Models\WorkOrderOperationAuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class WorkshopOperationAuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_audit_entry_cannot_be_updated(): void
    {
        $entry = $this->entry();

        $this->expectException(RuntimeException::class);

        $entry->update(['comment' => 'modifié']);
    }

    public function test_an_audit_entry_cannot_be_deleted(): void
    {
        $entry = $this->entry();

        $this->expectException(RuntimeException::class);

        $entry->delete();
    }

    public function test_an_audit_entry_keeps_metadata_and_is_linked_to_its_operation(): void
    {
        $entry = $this->entry();

        $entry->refresh();

        $this->assertSame(WorkshopOperationAuditEvent::Blocked, $entry->event);
        $this->assertSame(['reason' => 'Pièce manquante'], $entry->metadata);
        $this->assertSame('OF-2026-1234', $entry->work_order_number);
        $this->assertSame('PERCAGE', $entry->operation_code);
        $this->assertNotNull($entry->operation);
    }

    protected function entry(): WorkOrderOperationAuditLog
    {
        $operation = WorkOrderOperation::factory()->create([
            'code' => 'PERCAGE',
        ]);

        return WorkOrderOperationAuditLog::factory()
            ->create([
                'work_order_operation_id' => $operation->getKey(),
                'work_order_id' => $operation->work_order_id,
                'work_order_number' => 'OF-2026-1234',
                'operation_code' => 'PERCAGE',
                'event' => WorkshopOperationAuditEvent::Blocked->value,
                'comment' => 'Bloquée',
                'metadata' => ['reason' => 'Pièce manquante'],
            ]);
    }
}
