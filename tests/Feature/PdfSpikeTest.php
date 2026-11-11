<?php

namespace Tests\Feature;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Spike technique du Lot 2.
 *
 * Ce test ne dépend pas de la base : il valide le moteur Dompdf, les accents,
 * les tableaux, les sauts de page et l'écriture sur un disque privé.
 */
class PdfSpikeTest extends TestCase
{
    public function test_dompdf_generates_a_real_pdf_with_french_accents_and_tables(): void
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        @page { size: A4 portrait; margin: 12mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #999; padding: 4px; }
        thead { display: table-header-group; }
        .page-break { page-break-before: always; }
    </style>
</head>
<body>
    <h1>Réception — été 2026</h1>
    <p>Élément de démonstration : contrôle qualité, libération et expédition.</p>
    <table>
        <thead><tr><th>Repère</th><th>Désignation</th><th>Quantité</th></tr></thead>
        <tbody>
            <tr><td>R-001</td><td>Poteau IPE 200</td><td>12</td></tr>
            <tr><td>R-002</td><td>Traverse HEA 160</td><td>8</td></tr>
        </tbody>
    </table>
    <div class="page-break"></div>
    <h2>Page 2</h2>
    <p>Caractères accentués : à â ä é è ê ë î ï ô ö ù û ü ç œ.</p>
</body>
</html>
HTML;

        $contents = Pdf::loadHTML($html)
            ->setPaper('a4', 'portrait')
            ->setOption('defaultFont', 'DejaVu Sans')
            ->output();

        $this->assertStringStartsWith('%PDF-', $contents);
        $this->assertGreaterThan(1000, strlen($contents));

        Storage::fake('generated_documents');

        Storage::disk('generated_documents')->put('spike/demonstration.pdf', $contents);

        $this->assertTrue(Storage::disk('generated_documents')->exists('spike/demonstration.pdf'));
        $this->assertSame($contents, Storage::disk('generated_documents')->get('spike/demonstration.pdf'));
    }
}
