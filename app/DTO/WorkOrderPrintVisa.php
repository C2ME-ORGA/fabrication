<?php

namespace App\DTO;

/**
 * Visa du document OF.
 *
 * Un visa n'est acquis que si une validation persistée existe. Les états
 * non_effectue et non_renseigne sont explicites et ne doivent jamais être
 * présentés comme une signature.
 */
final readonly class WorkOrderPrintVisa
{
    public const TYPE_BUREAU_ETUDES = 'bureau_etudes';

    public const TYPE_LIBERATION = 'liberation';

    public const TYPE_QUALITE = 'qualite';

    public const TYPE_ATELIER = 'atelier';

    public const STATUS_EFFECTUE = 'effectue';

    public const STATUS_NON_EFFECTUE = 'non_effectue';

    public const STATUS_NON_RENSEIGNE = 'non_renseigne';

    public const EVIDENCE_BE = 'work_order_be_approval';

    public const EVIDENCE_RELEASE = 'work_order_release';

    public const EVIDENCE_QUALITY = 'work_order_quality_release';

    public const EVIDENCE_NONE = 'none';

    public function __construct(
        public string $type,
        public string $status,
        public ?string $personName,
        public ?string $functionLabel,
        public ?string $validatedAt,
        public string $evidenceType,
        public ?int $evidenceId,
        public ?string $evidenceReference,
    ) {}

    public function isAcquired(): bool
    {
        return $this->status === self::STATUS_EFFECTUE;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'status' => $this->status,
            'person_name' => $this->personName,
            'function_label' => $this->functionLabel,
            'validated_at' => $this->validatedAt,
            'evidence_type' => $this->evidenceType,
            'evidence_id' => $this->evidenceId,
            'evidence_reference' => $this->evidenceReference,
        ];
    }
}
