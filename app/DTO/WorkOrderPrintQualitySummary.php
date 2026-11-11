<?php

namespace App\DTO;

/**
 * Résumé d'un contrôle qualité dans le document OF.
 */
final readonly class WorkOrderPrintQualitySummary
{
    public function __construct(
        public string $templateCode,
        public string $templateName,
        public int $templateVersion,
        public string $controlTypeLabel,
        public string $scopeLabel,
        public bool $isMandatory,
        public string $statusLabel,
        public int $requiredResults,
        public int $conformingResults,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'template_code' => $this->templateCode,
            'template_name' => $this->templateName,
            'template_version' => $this->templateVersion,
            'control_type' => $this->controlTypeLabel,
            'scope' => $this->scopeLabel,
            'is_mandatory' => $this->isMandatory,
            'status' => $this->statusLabel,
            'required_results' => $this->requiredResults,
            'conforming_results' => $this->conformingResults,
        ];
    }
}
