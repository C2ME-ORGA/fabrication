<?php

namespace App\Services;

use App\Enums\DocumentSnapshotReason;
use App\Models\Shipment;
use App\Models\ShipmentLine;
use App\Models\ShipmentPackage;

/**
 * Construit et rend le bon de livraison et la liste de colisage à partir des
 * seules données persistées de l'expédition et de l'OF.
 */
class ShipmentPrintService
{
    public function __construct(
        protected PdfRenderer $renderer,
    ) {}

    /**
     * @return array{contents: string, data: array<string, mixed>, source_data_hash: string, template_version: string}
     */
    public function deliveryNote(Shipment $shipment): array
    {
        $data = $this->deliveryNoteData($shipment);

        return [
            'contents' => $this->renderer->render('pdf.delivery-note', ['data' => $data]),
            'data' => $data,
            'source_data_hash' => $this->hash($data),
            'template_version' => (string) config('pdf.template_version', '1'),
        ];
    }

    /**
     * @return array{contents: string, data: array<string, mixed>, source_data_hash: string, template_version: string}
     */
    public function packingList(Shipment $shipment): array
    {
        $data = $this->packingListData($shipment);

        return [
            'contents' => $this->renderer->render('pdf.packing-list', ['data' => $data]),
            'data' => $data,
            'source_data_hash' => $this->hash($data),
            'template_version' => (string) config('pdf.template_version', '1'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function deliveryNoteData(Shipment $shipment): array
    {
        $shipment->loadMissing(['workOrder.job', 'lines', 'packages', 'validator']);

        $lines = $shipment->lines->map(fn (ShipmentLine $line): array => $this->lineData($line))->values()->all();
        $packages = $shipment->packages->map(fn (ShipmentPackage $package): array => [
            'code' => $package->code,
            'type' => $package->package_type->getLabel(),
            'label' => $package->label,
        ])->values()->all();

        return array_merge($this->headerData($shipment), [
            'title' => 'BON DE LIVRAISON',
            'validated_at' => $shipment->validated_at?->toIso8601String() ?? now()->toIso8601String(),
            'validated_by' => $shipment->validator?->name,
            'lines' => $lines,
            'packages' => $packages,
            'package_count' => count($packages),
            'total_quantity' => array_sum(array_column($lines, 'quantity')),
            'total_weight_kg' => $this->totalWeight($lines),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function packingListData(Shipment $shipment): array
    {
        $shipment->loadMissing([
            'workOrder.job',
            'lines',
            'packages.packageLines.shipmentLine',
            'validator',
        ]);

        $packages = $shipment->packages->map(function (ShipmentPackage $package): array {
            $contents = $package->packageLines
                ->map(fn ($allocation): array => [
                    'mark' => $allocation->shipmentLine?->mark_snapshot,
                    'designation' => $allocation->shipmentLine?->designation_snapshot,
                    'quantity' => $allocation->quantity,
                ])
                ->values()
                ->all();

            return [
                'code' => $package->code,
                'type' => $package->package_type->getLabel(),
                'label' => $package->label,
                'position' => $package->position,
                'weight_kg' => $package->weight_kg,
                'length_cm' => $package->length_cm,
                'width_cm' => $package->width_cm,
                'height_cm' => $package->height_cm,
                'seal_number' => $package->seal_number,
                'notes' => $package->notes,
                'contents' => $contents,
                'total_quantity' => array_sum(array_column($contents, 'quantity')),
            ];
        })->values()->all();

        return array_merge($this->headerData($shipment), [
            'title' => 'LISTE DE COLISAGE',
            'validated_at' => $shipment->validated_at?->toIso8601String() ?? now()->toIso8601String(),
            'validated_by' => $shipment->validator?->name,
            'packages' => $packages,
            'package_count' => count($packages),
            'total_quantity' => array_sum(array_column($packages, 'total_quantity')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function headerData(Shipment $shipment): array
    {
        $workOrder = $shipment->workOrder;
        $job = $workOrder?->job;

        $releaseSnapshots = $workOrder === null
            ? collect()
            : $workOrder->documentSnapshots()
                ->whereIn('reason', [
                    DocumentSnapshotReason::Release->value,
                    DocumentSnapshotReason::ReReview->value,
                ])
                ->orderBy('captured_at')
                ->get();

        $revisions = $releaseSnapshots
            ->pluck('revision')
            ->filter()
            ->unique()
            ->values();

        return [
            'company_name' => (string) config('pdf.company_name', 'C2ME'),
            'delivery_note_reference' => $shipment->delivery_note_reference ?: $shipment->number.'-BL',
            'shipment_number' => $shipment->number,
            'work_order_number' => $workOrder?->number,
            'work_order_title' => $workOrder?->title,
            'of_revision' => $revisions->isEmpty() ? 'Non figée' : $revisions->implode(' / '),
            'revision_snapshot' => $releaseSnapshots->map(fn ($snapshot): array => [
                'document_id' => (int) $snapshot->document_id,
                'reference' => $snapshot->reference,
                'revision' => $snapshot->revision,
                'version_number' => $snapshot->version_number,
            ])->values()->all(),
            'job_code' => $job?->code,
            'job_name' => $job?->name,
            'client_name' => $job?->client_name,
            'site_address' => $job?->site_address,
            'delivery_address' => $shipment->delivery_address,
            'carrier_name' => $shipment->carrier_name,
            'carrier_reference' => $shipment->carrier_reference,
            'planned_ship_at' => $shipment->planned_ship_at?->toIso8601String(),
            'notes' => $shipment->notes,
            'template_version' => (string) config('pdf.template_version', '1'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function lineData(ShipmentLine $line): array
    {
        $unitWeight = $line->unit_weight_kg_snapshot === null
            ? null
            : (float) $line->unit_weight_kg_snapshot;

        return [
            'mark' => $line->mark_snapshot,
            'designation' => $line->designation_snapshot,
            'quantity' => $line->quantity_prepared,
            'unit_weight_kg' => $unitWeight,
            'total_weight_kg' => $unitWeight === null ? null : round($unitWeight * $line->quantity_prepared, 3),
            'notes' => $line->notes,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    protected function totalWeight(array $lines): float
    {
        return round(array_sum(array_map(
            fn (array $line): float => (float) ($line['total_weight_kg'] ?? 0),
            $lines,
        )), 3);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hash(array $data): string
    {
        return hash(
            'sha256',
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }
}
