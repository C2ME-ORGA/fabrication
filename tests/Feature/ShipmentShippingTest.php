<?php

namespace Tests\Feature;

use App\Enums\GeneratedDocumentStatus;
use App\Enums\ShipmentStatus;
use App\Models\GeneratedDocument;
use App\Models\ShipmentAuditLog;
use App\Models\User;
use App\Services\ShipmentQuantityGuard;
use App\Services\ShipmentService;
use App\Services\ShipmentValidationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Concerns\BuildsPreparedShipment;
use Tests\TestCase;

class ShipmentShippingTest extends TestCase
{
    use BuildsPreparedShipment;
    use RefreshDatabase;

    protected User $admin;

    protected User $workshop;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('generated_documents');

        $this->admin = User::factory()->admin()->create();
        $this->workshop = User::factory()->workshopSupervisor()->create();
    }

    public function test_ship_records_the_departure_and_shipped_quantities(): void
    {
        [$workOrder, $item, $shipment] = $this->validationReadyShipment(5, 3);
        $validated = app(ShipmentValidationService::class)->validate($shipment, $this->admin);

        $shipped = app(ShipmentService::class)->ship($validated, $this->admin);

        $this->assertSame(ShipmentStatus::Shipped, $shipped->status);
        $this->assertNotNull($shipped->actual_ship_at);
        $this->assertSame($this->admin->getKey(), $shipped->shipped_by);
        $this->assertSame(3, (int) $shipped->lines()->sum('quantity_shipped'));
        $this->assertSame(3, (int) $shipped->lines()->sum('quantity_prepared'));

        $guard = app(ShipmentQuantityGuard::class);
        $this->assertSame(0, $guard->validatedForItem($item));
        $this->assertSame(3, $guard->shippedForItem($item));
        $this->assertSame(2, $guard->availableForItem($item));

        $this->assertDatabaseHas('shipment_audit_logs', [
            'shipment_id' => $shipped->getKey(),
            'event' => 'shipped',
            'user_id' => $this->admin->getKey(),
            'comment' => null,
        ]);
    }

    public function test_ship_accepts_and_audits_a_departure_comment(): void
    {
        [, , $shipment] = $this->validationReadyShipment(5, 3);
        $validated = app(ShipmentValidationService::class)->validate($shipment, $this->admin);

        $shipped = app(ShipmentService::class)->ship(
            $validated,
            $this->admin,
            'Départ camion complet, quai 2.',
        );

        $this->assertDatabaseHas('shipment_audit_logs', [
            'shipment_id' => $shipped->getKey(),
            'event' => 'shipped',
            'comment' => 'Départ camion complet, quai 2.',
        ]);
    }

    public function test_ship_rejects_a_too_long_departure_comment(): void
    {
        [, , $shipment] = $this->validationReadyShipment(5, 3);
        $validated = app(ShipmentValidationService::class)->validate($shipment, $this->admin);

        try {
            app(ShipmentService::class)->ship($validated, $this->admin, str_repeat('a', 10001));
            $this->fail('Le commentaire trop long aurait dû être refusé.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('comment', $exception->errors());
        }

        $this->assertSame(ShipmentStatus::Validated, $validated->fresh()->status);
        $this->assertNull($validated->fresh()->actual_ship_at);
    }

    public function test_ship_refuses_a_non_validated_shipment(): void
    {
        [, , $shipment] = $this->validationReadyShipment(5, 3);
        $prepared = $shipment; // still prepared

        $this->expectException(AuthorizationException::class);

        app(ShipmentService::class)->ship($prepared, $this->admin);
    }

    public function test_ship_refuses_a_second_departure(): void
    {
        [, , $shipment] = $this->validationReadyShipment(5, 3);
        app(ShipmentValidationService::class)->validate($shipment, $this->admin);
        app(ShipmentService::class)->ship($shipment->fresh(), $this->admin);

        $this->assertFalse($this->admin->can('ship', $shipment->fresh()));

        $this->expectException(AuthorizationException::class);
        app(ShipmentService::class)->ship($shipment->fresh(), $this->admin);
    }

    public function test_cancelling_a_validated_shipment_releases_reserved_quantities_and_cancels_documents(): void
    {
        [, $item, $shipment] = $this->validationReadyShipment(5, 3);
        $validated = app(ShipmentValidationService::class)->validate($shipment, $this->admin);

        $cancelled = app(ShipmentService::class)->cancel(
            $validated,
            $this->admin,
            'Annulation de test avec un motif suffisamment long.',
        );

        $this->assertSame(ShipmentStatus::Cancelled, $cancelled->status);
        $this->assertSame($this->admin->getKey(), $cancelled->cancelled_by);
        $this->assertNotNull($cancelled->cancelled_at);

        $documents = GeneratedDocument::query()->where('shipment_id', $cancelled->getKey())->get();
        $this->assertCount(2, $documents);
        $this->assertTrue($documents->every(
            fn (GeneratedDocument $document): bool => $document->status === GeneratedDocumentStatus::Cancelled,
        ));

        foreach ($documents as $document) {
            $this->assertTrue(Storage::disk('generated_documents')->exists($document->path));
        }

        $guard = app(ShipmentQuantityGuard::class);
        $this->assertSame(0, $guard->validatedForItem($item));
        $this->assertSame(5, $guard->availableForItem($item));

        $this->assertDatabaseHas('shipment_audit_logs', [
            'shipment_id' => $cancelled->getKey(),
            'event' => 'cancelled',
        ]);
        $this->assertDatabaseHas('shipment_audit_logs', [
            'shipment_id' => $cancelled->getKey(),
            'event' => 'document_cancelled',
        ]);
    }

    public function test_cancellation_requires_a_reason(): void
    {
        [, , $shipment] = $this->validationReadyShipment(5, 3);
        $validated = app(ShipmentValidationService::class)->validate($shipment, $this->admin);

        $this->expectException(ValidationException::class);

        app(ShipmentService::class)->cancel($validated, $this->admin, '   ');
    }

    public function test_a_validated_shipment_requires_a_long_cancellation_reason(): void
    {
        [, , $shipment] = $this->validationReadyShipment(5, 3);
        $validated = app(ShipmentValidationService::class)->validate($shipment, $this->admin);

        try {
            app(ShipmentService::class)->cancel($validated, $this->admin, 'Trop court.');
            $this->fail('Le motif court aurait dû être refusé.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cancellation_reason', $exception->errors());
        }

        $this->assertSame(ShipmentStatus::Validated, $validated->fresh()->status);
    }

    public function test_cancelling_a_shipped_shipment_is_refused(): void
    {
        [, , $shipment] = $this->validationReadyShipment(5, 3);
        app(ShipmentValidationService::class)->validate($shipment, $this->admin);
        $shipped = app(ShipmentService::class)->ship($shipment->fresh(), $this->admin);

        $this->assertFalse($this->admin->can('cancel', $shipped->fresh()));

        $this->expectException(AuthorizationException::class);
        app(ShipmentService::class)->cancel(
            $shipped->fresh(),
            $this->admin,
            'Annulation après départ avec un motif suffisamment long.',
        );
    }

    public function test_workshop_supervisor_cannot_ship_or_cancel(): void
    {
        [, , $shipment] = $this->validationReadyShipment(5, 3);
        app(ShipmentValidationService::class)->validate($shipment, $this->admin);
        $validated = $shipment->fresh();

        $this->assertFalse($this->workshop->can('ship', $validated));
        $this->assertFalse($this->workshop->can('cancel', $validated));
        $this->assertFalse($this->workshop->can('validate', $validated));
    }

    public function test_audit_logs_are_appended_for_validation_and_departure(): void
    {
        [, , $shipment] = $this->validationReadyShipment(5, 3);
        app(ShipmentValidationService::class)->validate($shipment, $this->admin);
        app(ShipmentService::class)->ship($shipment->fresh(), $this->admin);

        $events = ShipmentAuditLog::query()
            ->where('shipment_id', $shipment->getKey())
            ->pluck('event')
            ->map(fn ($event): string => $event->value)
            ->all();

        $this->assertContains('validated', $events);
        $this->assertContains('shipped', $events);
        $this->assertContains('document_generated', $events);
    }
}
