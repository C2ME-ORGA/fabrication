<?php

namespace App\Services;

use App\DTO\BomImportError;
use App\DTO\BomImportPlanRow;
use App\DTO\BomImportPreview;
use App\DTO\BomImportRow;
use App\DTO\BomParseResult;
use App\Enums\WorkOrderAuditEvent;
use App\Enums\WorkOrderBomImportStatus;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderBomImport;
use App\Models\WorkOrderItem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Orchestration des imports de nomenclature CSV.
 *
 * L'aperçu ne réalise aucune écriture. La confirmation relit le fichier,
 * reverrouille l'OF et les pièces, recalcule les fusions puis exécute toutes
 * les mutations dans une transaction unique.
 */
class WorkOrderBomImportService
{
    public function __construct(
        protected BomCsvParser $parser,
        protected BomImportStorageService $storage,
        protected WorkOrderItemService $items,
        protected WorkOrderService $workOrders,
        protected WorkOrderAuditService $audit,
    ) {}

    public function preview(WorkOrder $workOrder, UploadedFile $file, User $actor): BomImportPreview
    {
        $this->authorize($workOrder, $actor);

        $sha256 = $this->hashFile($file);
        $this->assertNotAlreadyImported($workOrder, $sha256);

        return $this->buildPreview($workOrder, $file, $this->parser->parse($file), $sha256);
    }

    public function import(WorkOrder $workOrder, UploadedFile $file, User $actor): WorkOrderBomImport
    {
        $this->authorize($workOrder, $actor);

        $sha256 = $this->hashFile($file);
        $this->assertNotAlreadyImported($workOrder, $sha256);

        $parse = $this->parser->parse($file);
        $preview = $this->buildPreview($workOrder, $file, $parse, $sha256);

        if (! $parse->isValid() || ! $preview->isValid()) {
            $this->throwInvalidImport($preview->errors);
        }

        $stored = $this->storage->store(
            $file,
            $workOrder,
            $file->getClientOriginalName(),
            $sha256,
        );

        try {
            return DB::transaction(function () use ($workOrder, $parse, $preview, $stored, $sha256, $actor): WorkOrderBomImport {
                $lockedWorkOrder = $this->workOrders->lockForMutation($workOrder);

                if (WorkOrderBomImport::query()
                    ->where('work_order_id', $lockedWorkOrder->getKey())
                    ->where('sha256', $sha256)
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'file' => 'Ce fichier a déjà été importé dans cet ordre de fabrication.',
                    ]);
                }

                $marks = array_values(array_unique(array_map(
                    fn (BomImportRow $row): string => $row->mark,
                    $parse->rows,
                )));

                // Verrouillage déterministe des pièces existantes concernées.
                $existingItems = WorkOrderItem::query()
                    ->where('work_order_id', $lockedWorkOrder->getKey())
                    ->whereIn('mark', $marks)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $reconciliation = $this->reconcile($lockedWorkOrder, $parse->rows, $existingItems);

                if ($reconciliation['errors'] !== []) {
                    $this->throwInvalidImport($reconciliation['errors']);
                }

                $rows = $parse->rows;
                usort(
                    $rows,
                    fn (BomImportRow $left, BomImportRow $right): int => [$left->mark, $left->lineNumber]
                        <=> [$right->mark, $right->lineNumber],
                );

                foreach ($rows as $row) {
                    $this->items->create($lockedWorkOrder, $row->toAttributes(), $actor);
                }

                $import = WorkOrderBomImport::query()->create([
                    'work_order_id' => $lockedWorkOrder->getKey(),
                    'original_filename' => $stored['original_filename'],
                    'storage_disk' => $stored['disk'],
                    'storage_path' => $stored['path'],
                    'sha256' => $stored['sha256'],
                    'column_mapping' => $preview->columnMapping,
                    'rows_total' => $preview->rowsTotal,
                    'rows_valid' => $preview->rowsValid,
                    'rows_invalid' => $preview->rowsInvalid,
                    'imported_by' => $actor->getKey(),
                    'imported_at' => now(),
                    'status' => WorkOrderBomImportStatus::Completed,
                ]);

                $this->audit->record(
                    $lockedWorkOrder,
                    WorkOrderAuditEvent::BomImported,
                    $actor,
                    metadata: [
                        'import_id' => $import->getKey(),
                        'original_filename' => $import->original_filename,
                        'sha256' => $import->sha256,
                        'rows_total' => $import->rows_total,
                        'rows_valid' => $import->rows_valid,
                        'rows_invalid' => $import->rows_invalid,
                        'new_items' => $reconciliation['new_items'],
                        'merged_rows' => $reconciliation['merged_rows'],
                        'planned_quantity_added' => $reconciliation['planned_quantity_added'],
                    ],
                );

                return $import->refresh();
            }, attempts: 3);
        } catch (Throwable $exception) {
            // Le fichier n'appartient à aucun import réussi : il ne doit pas rester.
            $this->storage->delete($stored['disk'], $stored['path']);

            if ($exception instanceof QueryException && WorkOrderBomImport::query()
                ->where('work_order_id', $workOrder->getKey())
                ->where('sha256', $sha256)
                ->exists()) {
                throw ValidationException::withMessages([
                    'file' => 'Ce fichier a déjà été importé dans cet ordre de fabrication.',
                ]);
            }

            throw $exception;
        }
    }

    protected function buildPreview(
        WorkOrder $workOrder,
        UploadedFile $file,
        BomParseResult $parse,
        string $sha256,
    ): BomImportPreview {
        $reconciliation = $this->reconcile($workOrder, $parse->rows);

        return new BomImportPreview(
            originalFilename: basename($file->getClientOriginalName()),
            sha256: $sha256,
            sizeBytes: (int) ($file->getSize() ?: 0),
            delimiter: $parse->delimiter,
            columnMapping: $parse->columnMapping,
            rowsTotal: $parse->rowsTotal,
            rowsValid: $parse->rowsValid,
            rowsInvalid: $parse->rowsInvalid,
            rows: $parse->rows,
            errors: [...$parse->errors, ...$reconciliation['errors']],
            plans: $reconciliation['plans'],
            newItemsCount: $reconciliation['new_items'],
            mergedRowsCount: $reconciliation['merged_rows'],
            plannedQuantityAdded: $reconciliation['planned_quantity_added'],
        );
    }

    /**
     * @param  array<int, BomImportRow>  $rows
     * @param  Collection<int, WorkOrderItem>|null  $existingItems
     * @return array{
     *     plans: array<int, BomImportPlanRow>,
     *     errors: array<int, BomImportError>,
     *     new_items: int,
     *     merged_rows: int,
     *     planned_quantity_added: int
     * }
     */
    protected function reconcile(WorkOrder $workOrder, array $rows, ?Collection $existingItems = null): array
    {
        $marks = array_values(array_unique(array_map(
            fn (BomImportRow $row): string => $row->mark,
            $rows,
        )));

        $existingItems ??= $workOrder->items()->whereIn('mark', $marks)->get();
        $existingByMark = $existingItems->keyBy('mark');

        $plans = [];
        $errors = [];
        $seenIdentities = [];
        $accumulatedPlanned = [];
        $blockedMarks = [];
        $newItems = 0;
        $mergedRows = 0;
        $plannedQuantityAdded = 0;

        foreach ($rows as $row) {
            $plannedQuantityAdded += $row->quantityPlanned;
            $mark = $row->mark;

            if (isset($blockedMarks[$mark])) {
                $errors[] = new BomImportError(
                    $row->lineNumber,
                    'mark',
                    $mark,
                    "Le repère {$mark} est déjà en conflit dans ce fichier.",
                );

                continue;
            }

            $identity = $this->items->technicalIdentityFromAttributes($row->toAttributes());

            if (isset($seenIdentities[$mark])) {
                if ($seenIdentities[$mark] !== $identity) {
                    $blockedMarks[$mark] = true;
                    $errors[] = new BomImportError(
                        $row->lineNumber,
                        'mark',
                        $mark,
                        "Doublon interne incompatible pour le repère {$mark} : les caractéristiques techniques diffèrent.",
                    );

                    continue;
                }

                $accumulatedPlanned[$mark] += $row->quantityPlanned;
                $mergedRows++;

                $plans[] = new BomImportPlanRow(
                    lineNumber: $row->lineNumber,
                    mark: $mark,
                    action: BomImportPlanRow::ACTION_MERGE_INTERNAL,
                    plannedQuantity: $row->quantityPlanned,
                    existingItemId: null,
                    resultingPlannedQuantity: $accumulatedPlanned[$mark],
                    message: 'Doublon interne compatible : quantité prévue additionnée.',
                );

                continue;
            }

            if ($existingByMark->has($mark)) {
                $existing = $existingByMark->get($mark);
                $differences = $this->items->identityDifferences($existing, $identity);

                if ($differences !== []) {
                    $blockedMarks[$mark] = true;
                    $errors[] = new BomImportError(
                        $row->lineNumber,
                        'mark',
                        $mark,
                        $this->items->mergeConflictMessage($existing, $differences),
                    );

                    continue;
                }

                $accumulatedPlanned[$mark] = $existing->quantity_planned + $row->quantityPlanned;
                $seenIdentities[$mark] = $identity;
                $mergedRows++;

                $plans[] = new BomImportPlanRow(
                    lineNumber: $row->lineNumber,
                    mark: $mark,
                    action: BomImportPlanRow::ACTION_MERGE_EXISTING,
                    plannedQuantity: $row->quantityPlanned,
                    existingItemId: $existing->getKey(),
                    resultingPlannedQuantity: $accumulatedPlanned[$mark],
                    message: 'Fusion avec la pièce existante : production et rebuts conservés.',
                );

                continue;
            }

            $accumulatedPlanned[$mark] = $row->quantityPlanned;
            $seenIdentities[$mark] = $identity;
            $newItems++;

            $plans[] = new BomImportPlanRow(
                lineNumber: $row->lineNumber,
                mark: $mark,
                action: BomImportPlanRow::ACTION_CREATE,
                plannedQuantity: $row->quantityPlanned,
                existingItemId: null,
                resultingPlannedQuantity: $row->quantityPlanned,
                message: 'Nouvelle pièce.',
            );
        }

        return [
            'plans' => $plans,
            'errors' => $errors,
            'new_items' => $newItems,
            'merged_rows' => $mergedRows,
            'planned_quantity_added' => $plannedQuantityAdded,
        ];
    }

    protected function authorize(WorkOrder $workOrder, User $actor): void
    {
        Gate::forUser($actor)->authorize('manageItems', $workOrder);
    }

    protected function assertNotAlreadyImported(WorkOrder $workOrder, string $sha256): void
    {
        if (WorkOrderBomImport::query()
            ->where('work_order_id', $workOrder->getKey())
            ->where('sha256', $sha256)
            ->exists()) {
            throw ValidationException::withMessages([
                'file' => 'Ce fichier a déjà été importé dans cet ordre de fabrication.',
            ]);
        }
    }

    protected function hashFile(UploadedFile $file): string
    {
        $realPath = $file->getRealPath();
        $hash = is_string($realPath) ? hash_file('sha256', $realPath) : false;

        if (! is_string($hash) || $hash === '') {
            throw ValidationException::withMessages([
                'file' => 'Le hash du fichier ne peut pas être calculé.',
            ]);
        }

        return $hash;
    }

    /**
     * @param  array<int, BomImportError>  $errors
     */
    protected function throwInvalidImport(array $errors): never
    {
        $messages = array_map(
            fn (BomImportError $error): string => $this->formatError($error),
            $errors === [] ? [new BomImportError(null, null, null, 'Le fichier est invalide.')] : $errors,
        );

        throw ValidationException::withMessages(['file' => $messages]);
    }

    protected function formatError(BomImportError $error): string
    {
        $prefix = $error->lineNumber !== null ? "Ligne {$error->lineNumber}" : 'Fichier';

        if ($error->column !== null) {
            $prefix .= " [{$error->column}]";
        }

        return $prefix.' : '.$error->message;
    }
}
