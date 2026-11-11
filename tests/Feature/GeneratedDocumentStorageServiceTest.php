<?php

namespace Tests\Feature;

use App\Services\GeneratedDocumentStorageService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class GeneratedDocumentStorageServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::forgetDisk('generated_documents');
        config(['pdf.disk' => 'generated_documents']);
    }

    protected function tearDown(): void
    {
        Storage::forgetDisk('generated_documents');
        Mockery::close();

        parent::tearDown();
    }

    public function test_partial_file_is_deleted_when_size_fails_after_a_successful_write(): void
    {
        $disk = Mockery::mock(FilesystemAdapter::class);

        $disk->shouldReceive('put')
            ->once()
            ->andReturnTrue();

        $disk->shouldReceive('size')
            ->once()
            ->andThrow(new RuntimeException('Lecture de la taille impossible.'));

        $disk->shouldReceive('delete')
            ->once()
            ->andReturnTrue();

        Storage::set('generated_documents', $disk);

        try {
            app(GeneratedDocumentStorageService::class)->store(
                '%PDF-1.4 contenu',
                'work-orders/1/work_order_pdf',
                'OF-1-PDF.pdf',
            );

            $this->fail('Une ValidationException était attendue.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('file', $exception->errors());
        }
    }

    public function test_a_failed_cleanup_is_logged_without_masking_the_business_error(): void
    {
        Log::spy();

        $disk = Mockery::mock(FilesystemAdapter::class);

        $disk->shouldReceive('put')->once()->andReturnTrue();
        $disk->shouldReceive('size')->once()->andThrow(new RuntimeException('Lecture de la taille impossible.'));
        $disk->shouldReceive('delete')->once()->andReturnFalse();

        Storage::set('generated_documents', $disk);

        try {
            app(GeneratedDocumentStorageService::class)->store(
                '%PDF-1.4 contenu',
                'work-orders/1/work_order_pdf',
                'OF-1-PDF.pdf',
            );

            $this->fail('Une ValidationException était attendue.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('file', $exception->errors());
        }

        Log::shouldHaveReceived('error')->atLeast()->once();
    }
}
