<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's database queue driver uses a table named `jobs`, which collides
 * with the business table `jobs` (affaires). The queue table is moved to
 * `queue_jobs` (see config/queue.php) before the business table is created.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('jobs') && ! Schema::hasTable('queue_jobs')) {
            Schema::rename('jobs', 'queue_jobs');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('queue_jobs') && ! Schema::hasTable('jobs')) {
            Schema::rename('queue_jobs', 'jobs');
        }
    }
};
