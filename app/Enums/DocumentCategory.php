<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum DocumentCategory: string implements HasColor, HasLabel
{
    case AssemblyPlan = 'assembly_plan';
    case DetailPlan = 'detail_plan';
    case FabricationPlan = 'fabrication_plan';
    case CuttingPlan = 'cutting_plan';
    case MaterialList = 'material_list';
    case BillOfMaterials = 'bill_of_materials';
    case WeldingDocument = 'welding_document';
    case QualityDocument = 'quality_document';
    case SupplierDocument = 'supplier_document';
    case ShippingDocument = 'shipping_document';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::AssemblyPlan => "Plan d'ensemble",
            self::DetailPlan => 'Plan de détail',
            self::FabricationPlan => 'Plan de fabrication',
            self::CuttingPlan => 'Plan de débit',
            self::MaterialList => 'Liste de matériaux',
            self::BillOfMaterials => 'Nomenclature',
            self::WeldingDocument => 'Documentation de soudage',
            self::QualityDocument => 'Document qualité',
            self::SupplierDocument => 'Document fournisseur',
            self::ShippingDocument => "Document d'expédition",
            self::Other => 'Autre',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::AssemblyPlan, self::DetailPlan, self::FabricationPlan, self::CuttingPlan => 'primary',
            self::MaterialList, self::BillOfMaterials => 'info',
            self::WeldingDocument, self::QualityDocument => 'warning',
            self::SupplierDocument, self::ShippingDocument => 'success',
            self::Other => 'gray',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $options, self $category): array => $options + [$category->value => $category->getLabel()],
            [],
        );
    }
}
