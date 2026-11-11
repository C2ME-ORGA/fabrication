<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use RuntimeException;
use Throwable;

class DomPdfRenderer implements PdfRenderer
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $options
     */
    public function render(string $view, array $data, array $options = []): string
    {
        try {
            $pdf = Pdf::loadView($view, $data);

            $pdf->setPaper(
                (string) ($options['paper'] ?? config('pdf.paper', 'a4')),
                (string) ($options['orientation'] ?? config('pdf.orientation', 'portrait')),
            );

            $pdf->setOptions([
                'isRemoteEnabled' => false,
                'isPhpEnabled' => false,
                'isJavascriptEnabled' => false,
                'defaultFont' => (string) config('pdf.dompdf.defaultFont', 'DejaVu Sans'),
                'dpi' => (int) config('pdf.dompdf.dpi', 120),
                'chroot' => base_path(),
                ...(array) config('pdf.dompdf', []),
                ...($options['dompdf'] ?? []),
            ]);

            return $pdf->output();
        } catch (Throwable $exception) {
            report($exception);

            throw new RuntimeException(
                "Le document PDF n'a pas pu être généré.",
                previous: $exception,
            );
        }
    }
}
