<?php

namespace App\DTO;

use App\Enums\ClosureBlockerCode;

/**
 * Blocage explicite du calcul d'éligibilité qualité.
 */
final class ClosureBlocker
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly ClosureBlockerCode $code,
        public readonly string $message,
        public readonly ?int $controlId = null,
        public readonly ?int $pointId = null,
        public readonly ?int $nonConformityId = null,
        public readonly ?int $deviationId = null,
        public readonly array $metadata = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code->value,
            'label' => $this->code->getLabel(),
            'message' => $this->message,
            'control_id' => $this->controlId,
            'point_id' => $this->pointId,
            'non_conformity_id' => $this->nonConformityId,
            'deviation_id' => $this->deviationId,
            'metadata' => $this->metadata,
        ];
    }
}
