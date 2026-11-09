<?php

namespace App\DTO;

readonly class BomImportPlanRow
{
    public const ACTION_CREATE = 'create';

    public const ACTION_MERGE_INTERNAL = 'merge_internal';

    public const ACTION_MERGE_EXISTING = 'merge_existing';

    public function __construct(
        public int $lineNumber,
        public string $mark,
        public string $action,
        public int $plannedQuantity,
        public ?int $existingItemId,
        public int $resultingPlannedQuantity,
        public string $message,
    ) {}

    public function actionLabel(): string
    {
        return match ($this->action) {
            self::ACTION_CREATE => 'Nouvelle pièce',
            self::ACTION_MERGE_INTERNAL => 'Fusion interne',
            self::ACTION_MERGE_EXISTING => 'Fusion avec l’existant',
            default => $this->action,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'line_number' => $this->lineNumber,
            'mark' => $this->mark,
            'action' => $this->action,
            'action_label' => $this->actionLabel(),
            'planned_quantity' => $this->plannedQuantity,
            'existing_item_id' => $this->existingItemId,
            'resulting_planned_quantity' => $this->resultingPlannedQuantity,
            'message' => $this->message,
        ];
    }
}
