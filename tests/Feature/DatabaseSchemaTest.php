<?php

namespace Tests\Feature;

use App\Models\Job;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_queue_table_does_not_collide_with_the_business_jobs_table(): void
    {
        $this->assertSame('queue_jobs', config('queue.connections.database.table'));

        $this->assertTrue(Schema::hasTable('jobs'));
        $this->assertTrue(Schema::hasColumn('jobs', 'code'));

        $this->assertTrue(Schema::hasTable('queue_jobs'));
        $this->assertTrue(Schema::hasColumn('queue_jobs', 'payload'));
    }

    public function test_work_order_status_and_priority_default_to_draft_and_normal(): void
    {
        $job = Job::factory()->create();

        DB::table('work_orders')->insert([
            'job_id' => $job->id,
            'number' => 'OF-2026-0500',
            'title' => 'Valeurs par défaut',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $workOrder = WorkOrder::query()->where('number', 'OF-2026-0500')->firstOrFail();

        $this->assertSame('draft', $workOrder->getRawOriginal('status'));
        $this->assertSame('normal', $workOrder->getRawOriginal('priority'));
    }
}
