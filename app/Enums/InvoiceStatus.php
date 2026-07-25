<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Processed            = 'processed';
    case SupplementaryInvoice = 'supplementary_invoice';

    public function label(): string
    {
        return match ($this) {
            self::Processed            => 'أساسية',
            self::SupplementaryInvoice => 'إلحاقية (ترميم نواقص)',
        };
    }
}
