<?php

namespace Database\Factories;

use App\Enums\DocumentCategory;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\Job;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_id' => Job::factory(),
            'work_order_id' => null,
            'category' => fake()->randomElement(DocumentCategory::cases()),
            'title' => 'Document '.fake()->words(3, true),
            'reference' => fake()->optional()->bothify('DOC-####-??'),
            'description' => null,
            'status' => DocumentStatus::Active,
            'created_by' => null,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['status' => DocumentStatus::Archived]);
    }
}
