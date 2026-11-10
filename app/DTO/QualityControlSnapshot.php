<?php

namespace App\DTO;

use App\Enums\QualityControlType;
use App\Enums\QualityRequirementScope;
use App\Enums\QualityRequirementTrigger;

/**
 * Photographie du modèle de contrôle au moment de la génération.
 */
final class QualityControlSnapshot
{
    public function __construct(
        public readonly string $templateCode,
        public readonly string $templateName,
        public readonly int $templateVersionNumber,
        public readonly QualityControlType $controlType,
        public readonly QualityRequirementScope $scope,
        public readonly QualityRequirementTrigger $trigger,
        public readonly bool $isMandatory,
        public readonly bool $allowDeviation,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'template_code' => $this->templateCode,
            'template_name' => $this->templateName,
            'template_version_number' => $this->templateVersionNumber,
            'control_type' => $this->controlType->value,
            'scope' => $this->scope->value,
            'trigger' => $this->trigger->value,
            'is_mandatory' => $this->isMandatory,
            'allow_deviation' => $this->allowDeviation,
        ];
    }
}
