<?php

namespace Tests\Unit;

use App\Enums\GeneratedDocumentStatus;
use PHPUnit\Framework\TestCase;

class GeneratedDocumentStatusTest extends TestCase
{
    public function test_only_archived_documents_are_current(): void
    {
        $this->assertTrue(GeneratedDocumentStatus::Archived->isCurrent());
        $this->assertFalse(GeneratedDocumentStatus::Superseded->isCurrent());
        $this->assertFalse(GeneratedDocumentStatus::Cancelled->isCurrent());
    }

    public function test_every_status_has_a_label_and_a_color(): void
    {
        foreach (GeneratedDocumentStatus::cases() as $status) {
            $this->assertNotSame('', $status->getLabel());
            $this->assertNotSame('', $status->getColor());
        }
    }
}
