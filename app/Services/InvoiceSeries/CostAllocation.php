<?php

namespace App\Services\InvoiceSeries;

use App\Models\BillingInterval;
use App\Models\CostCategory;

/**
 * Ein Anteil einer Rechnung bzw. einer Buchung: Betrag je Kostenkategorie (Konzept.md
 * "### Rechnung": "Der Rechnungsbetrag kann unterschiedlichen Schlüsseln zugeteilt
 * werden", z. B. Zins und Tilgung). Ein optionales Abrechnungsintervall pinnt nur
 * diesen Anteil auf ein bestimmtes Intervall ("Das Splitten muss auch auf
 * unterschiedliche Abrechnungsintervalle sein").
 */
final class CostAllocation
{
    public function __construct(
        public readonly float $amount,
        public readonly CostCategory $category,
        public readonly ?BillingInterval $billingInterval = null,
    ) {}
}
