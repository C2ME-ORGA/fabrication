<?php

namespace App\Models;

use App\Enums\WorkOrderItemStatus;
use Database\Factories\WorkOrderItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'work_order_id',
    'mark',
    'designation',
    'profile',
    'steel_grade',
    'length_mm',
    'quantity_planned',
    'unit_weight_kg',
    'operation_group',
    'notes',
])]
class WorkOrderItem extends Model
{
    /** @use HasFactory<WorkOrderItemFactory> */
    use HasFactory;

    /**
     * Champs de production et de poids total : uniquement écrits par le
     * service métier, jamais par un formulaire ou un import.
     */
    public const PROTECTED_FIELDS = [
        'work_order_id',
        'quantity_produced',
        'quantity_rejected',
        'total_weight_kg',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'length_mm' => 'decimal:2',
            'quantity_planned' => 'integer',
            'quantity_produced' => 'integer',
            'quantity_rejected' => 'integer',
            'unit_weight_kg' => 'decimal:3',
            'total_weight_kg' => 'decimal:3',
            'status' => WorkOrderItemStatus::class,
        ];
    }

    /**
     * Le poids total est une donnée dérivée, recalculée à chaque sauvegarde.
     * Règle unique : poids unitaire × quantité prévue.
     */
    protected static function booted(): void
    {
        static::saving(function (WorkOrderItem $item): void {
            $item->recalculateTotalWeight();
        });
    }

    /**
     * @return BelongsTo<WorkOrder, $this>
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /**
     * Quantité acceptée = quantité fabriquée - quantité rebutée.
     */
    protected function acceptedQuantity(): Attribute
    {
        return Attribute::get(
            fn (): int => max(0, $this->quantity_produced - $this->quantity_rejected),
        );
    }

    /**
     * Quantité restant à fabriquer. Les rebuts doivent être refabriqués,
     * donc la quantité acceptée est utilisée et non la quantité produite.
     */
    protected function remainingQuantity(): Attribute
    {
        return Attribute::get(
            fn (): int => max(0, $this->quantity_planned - $this->accepted_quantity),
        );
    }

    public function recalculateTotalWeight(): void
    {
        if ($this->unit_weight_kg === null) {
            $this->total_weight_kg = null;

            return;
        }

        $this->total_weight_kg = round(
            (float) $this->unit_weight_kg * (int) $this->quantity_planned,
            3,
        );
    }

    public function hasRecordedProduction(): bool
    {
        return $this->quantity_produced > 0 || $this->quantity_rejected > 0;
    }

    /**
     * Identité technique utilisée pour la fusion des repères.
     *
     * @return array{designation: ?string, profile: ?string, steel_grade: ?string, length_mm: ?string}
     */
    public function technicalIdentity(): array
    {
        return [
            'designation' => self::normalizeTechnicalText($this->designation),
            'profile' => self::normalizeTechnicalText($this->profile),
            'steel_grade' => self::normalizeTechnicalText($this->steel_grade),
            'length_mm' => $this->length_mm === null
                ? null
                : number_format((float) $this->length_mm, 2, '.', ''),
        ];
    }

    public static function normalizeMark(string $mark): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($mark)) ?? '';
        $normalized = Str::ascii($normalized);

        return mb_strtoupper($normalized);
    }

    public static function normalizeTechnicalText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($value)) ?? '';

        return $normalized === '' ? null : mb_strtolower($normalized);
    }

    /**
     * Normalise le repère avant stockage pour garantir le même comportement
     * d'unicité sous MySQL (collation insensible à la casse) et SQLite.
     */
    protected function mark(): Attribute
    {
        return Attribute::set(
            fn (string $value): string => self::normalizeMark($value),
        );
    }
}
