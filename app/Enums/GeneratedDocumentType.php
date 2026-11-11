<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum GeneratedDocumentType: string implements HasColor, HasLabel
{
    case WorkOrderPdf = 'work_order_pdf';
    case DeliveryNote = 'delivery_note';
    case PackingList = 'packing_list';

    public function getLabel(): string
    {
        return match ($this) {
            self::WorkOrderPdf => 'Ordre de fabrication',
            self::DeliveryNote => 'Bon de livraison',
            self::PackingList => 'Liste de colisage',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::WorkOrderPdf => 'primary',
            self::DeliveryNote => 'success',
            self::PackingList => 'info',
        };
    }

    public function getReferencePrefix(): string
    {
        return match ($this) {
            self::WorkOrderPdf => 'PDF',
            self::DeliveryNote => 'BL',
            self::PackingList => 'COL',
        };
    }
}
