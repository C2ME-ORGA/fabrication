<?php

namespace App\Services;

use App\Enums\ShipmentAuditEvent;
use App\Enums\ShipmentPackageType;
use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use App\Models\ShipmentLine;
use App\Models\ShipmentPackage;
use App\Models\ShipmentPackageLine;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Colis et répartition physique des quantités préparées.
 *
 * Une ligne peut être répartie sur plusieurs colis et un colis peut contenir
 * plusieurs lignes. La somme répartie ne dépasse jamais la quantité préparée
 * de la ligne ; l'égalité complète est contrôlée à la validation (Lot 4).
 */
class ShipmentPackagingService
{
    public function __construct(
        protected ShipmentAuditService $audit,
        protected ShipmentQuantityGuard $quantities,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function addPackage(Shipment $shipment, array $data = [], ?User $actor = null): ShipmentPackage
    {
        $this->ensureActor($actor);
        Gate::forUser($actor)->authorize('update', $shipment);

        return DB::transaction(function () use ($shipment, $data, $actor): ShipmentPackage {
            $locked = $this->lockShipment($shipment);
            $this->assertEditable($locked);

            $position = (int) ($locked->packages()->max('position') ?? 0) + 1;
            $code = $this->normalize($data['code'] ?? null)
                ?? 'COLIS-'.str_pad((string) $position, 2, '0', STR_PAD_LEFT);

            $this->assertCodeAvailable($locked, $code);

            $package = ShipmentPackage::query()->create([
                'shipment_id' => $locked->getKey(),
                'code' => $code,
                'label' => $this->normalize($data['label'] ?? null),
                'package_type' => $data['package_type'] ?? ShipmentPackageType::Pallet,
                'position' => $position,
                'weight_kg' => $data['weight_kg'] ?? null,
                'length_cm' => $data['length_cm'] ?? null,
                'width_cm' => $data['width_cm'] ?? null,
                'height_cm' => $data['height_cm'] ?? null,
                'seal_number' => $this->normalize($data['seal_number'] ?? null),
                'notes' => $this->normalize($data['notes'] ?? null),
            ]);

            $this->audit->record(
                $locked,
                ShipmentAuditEvent::PackageCreated,
                $actor,
                ShipmentStatus::Draft->value,
                ShipmentStatus::Draft->value,
                null,
                ['shipment_package_id' => (int) $package->getKey(), 'code' => $package->code],
            );

            return $package->refresh();
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updatePackage(
        ShipmentPackage $package,
        array $data,
        ?User $actor = null,
    ): ShipmentPackage {
        $this->ensureActor($actor);
        Gate::forUser($actor)->authorize('update', $package);

        return DB::transaction(function () use ($package, $data, $actor): ShipmentPackage {
            $shipment = $this->lockShipment($package->shipment);
            $this->assertEditable($shipment);

            $locked = ShipmentPackage::query()->lockForUpdate()->findOrFail($package->getKey());

            if (array_key_exists('code', $data)) {
                $code = $this->normalize($data['code']);

                if ($code === null) {
                    throw ValidationException::withMessages([
                        'code' => 'Le code du colis est obligatoire.',
                    ]);
                }

                $this->assertCodeAvailable($shipment, $code, $locked->getKey());
                $locked->code = $code;
            }

            foreach ([
                'label',
                'weight_kg',
                'length_cm',
                'width_cm',
                'height_cm',
                'seal_number',
                'notes',
            ] as $field) {
                if (array_key_exists($field, $data)) {
                    $locked->{$field} = $this->normalize($data[$field]);
                }
            }

            if (array_key_exists('package_type', $data)) {
                $locked->package_type = $data['package_type'];
            }

            $locked->save();

            $this->audit->record(
                $shipment,
                ShipmentAuditEvent::PackageUpdated,
                $actor,
                ShipmentStatus::Draft->value,
                ShipmentStatus::Draft->value,
                null,
                ['shipment_package_id' => (int) $locked->getKey(), 'code' => $locked->code],
            );

            return $locked->refresh();
        }, attempts: 3);
    }

    public function removePackage(ShipmentPackage $package, ?User $actor = null): void
    {
        $this->ensureActor($actor);
        Gate::forUser($actor)->authorize('delete', $package);

        DB::transaction(function () use ($package, $actor): void {
            $shipment = $this->lockShipment($package->shipment);
            $this->assertEditable($shipment);

            $locked = ShipmentPackage::query()->lockForUpdate()->findOrFail($package->getKey());
            $metadata = [
                'shipment_package_id' => (int) $locked->getKey(),
                'code' => $locked->code,
                'allocated_lines' => $locked->packageLines()->count(),
            ];

            $locked->delete();

            $this->audit->record(
                $shipment,
                ShipmentAuditEvent::PackageRemoved,
                $actor,
                ShipmentStatus::Draft->value,
                ShipmentStatus::Draft->value,
                null,
                $metadata,
            );
        }, attempts: 3);
    }

    public function assign(
        ShipmentPackage $package,
        ShipmentLine $line,
        int $quantity,
        ?User $actor = null,
    ): ShipmentPackageLine {
        $this->ensureActor($actor);
        Gate::forUser($actor)->authorize('update', $package);

        return DB::transaction(function () use ($package, $line, $quantity, $actor): ShipmentPackageLine {
            $shipment = $this->lockShipment($package->shipment);
            $this->assertEditable($shipment);

            $lockedPackage = ShipmentPackage::query()->lockForUpdate()->findOrFail($package->getKey());
            $lockedLine = ShipmentLine::query()->lockForUpdate()->findOrFail($line->getKey());

            if ((int) $lockedPackage->shipment_id !== (int) $lockedLine->shipment_id) {
                throw ValidationException::withMessages([
                    'shipment_line_id' => 'Le colis et la ligne doivent appartenir à la même expédition.',
                ]);
            }

            if ($quantity < 1) {
                throw ValidationException::withMessages([
                    'quantity' => 'La quantité colisée doit être strictement positive.',
                ]);
            }

            $existing = ShipmentPackageLine::query()
                ->where('shipment_package_id', $lockedPackage->getKey())
                ->where('shipment_line_id', $lockedLine->getKey())
                ->first();

            $otherAllocated = (int) ShipmentPackageLine::query()
                ->where('shipment_line_id', $lockedLine->getKey())
                ->when($existing !== null, fn ($query) => $query->where('id', '!=', $existing->getKey()))
                ->sum('quantity');

            if ($otherAllocated + $quantity > $lockedLine->quantity_prepared) {
                throw ValidationException::withMessages([
                    'quantity' => "La répartition dépasse la quantité préparée de la ligne ({$lockedLine->quantity_prepared}).",
                ]);
            }

            $before = $existing?->quantity;
            $allocation = $existing ?? new ShipmentPackageLine([
                'shipment_package_id' => $lockedPackage->getKey(),
                'shipment_line_id' => $lockedLine->getKey(),
            ]);
            $allocation->quantity = $quantity;
            $allocation->save();

            $this->audit->record(
                $shipment,
                ShipmentAuditEvent::PackagingAssigned,
                $actor,
                ShipmentStatus::Draft->value,
                ShipmentStatus::Draft->value,
                null,
                [
                    'shipment_package_id' => (int) $lockedPackage->getKey(),
                    'shipment_line_id' => (int) $lockedLine->getKey(),
                    'mark' => $lockedLine->mark_snapshot,
                    'before' => $before,
                    'after' => $allocation->quantity,
                ],
            );

            return $allocation->refresh();
        }, attempts: 3);
    }

    public function removeAllocation(ShipmentPackageLine $allocation, ?User $actor = null): void
    {
        $this->ensureActor($actor);
        Gate::forUser($actor)->authorize('update', $allocation->shipmentPackage);

        DB::transaction(function () use ($allocation, $actor): void {
            $shipment = $this->lockShipment($allocation->shipmentPackage->shipment);
            $this->assertEditable($shipment);

            $locked = ShipmentPackageLine::query()->lockForUpdate()->findOrFail($allocation->getKey());
            $metadata = [
                'shipment_package_id' => (int) $locked->shipment_package_id,
                'shipment_line_id' => (int) $locked->shipment_line_id,
                'quantity' => $locked->quantity,
            ];

            $locked->delete();

            $this->audit->record(
                $shipment,
                ShipmentAuditEvent::PackagingRemoved,
                $actor,
                ShipmentStatus::Draft->value,
                ShipmentStatus::Draft->value,
                null,
                $metadata,
            );
        }, attempts: 3);
    }

    /**
     * Contrôle réservé à la validation du Lot 4 : chaque quantité préparée
     * doit être intégralement couverte par les colis.
     */
    public function assertCompleteForValidation(Shipment $shipment): void
    {
        foreach ($shipment->lines()->get() as $line) {
            $packaged = $this->quantities->packagedForLine($line);

            if ($packaged !== $line->quantity_prepared) {
                throw ValidationException::withMessages([
                    'packaging' => "Le colisage est incomplet pour le repère {$line->mark_snapshot} : {$packaged}/{$line->quantity_prepared}.",
                ]);
            }
        }
    }

    protected function lockShipment(Shipment $shipment): Shipment
    {
        $workOrder = WorkOrder::query()->lockForUpdate()->findOrFail($shipment->work_order_id);

        $locked = Shipment::query()->lockForUpdate()->findOrFail($shipment->getKey());
        $locked->setRelation('workOrder', $workOrder);

        return $locked;
    }

    protected function assertEditable(Shipment $shipment): void
    {
        if (! $shipment->isEditable()) {
            throw ValidationException::withMessages([
                'status' => 'Seule une expédition brouillon peut être modifiée. Rouvrez la préparation.',
            ]);
        }
    }

    protected function assertCodeAvailable(Shipment $shipment, string $code, ?int $ignoreId = null): void
    {
        $exists = ShipmentPackage::query()
            ->where('shipment_id', $shipment->getKey())
            ->where('code', $code)
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'code' => "Le code de colis « {$code} » existe déjà dans cette expédition.",
            ]);
        }
    }

    protected function ensureActor(?User $actor): void
    {
        if ($actor === null || ! $actor->exists) {
            throw ValidationException::withMessages([
                'user' => 'Un utilisateur autorisé et enregistré est requis.',
            ]);
        }
    }

    protected function normalize(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value === '' ? null : $value;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
