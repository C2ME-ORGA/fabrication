<?php

namespace App\Services;

use App\Enums\GeneratedDocumentType;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Orchestration de la génération d'un PDF d'OF.
 *
 * Le service ne contient pas de règle de rendu : il assemble la construction
 * des données, le rendu et l'archivage.
 */
class WorkOrderPdfGenerationService
{
    public function __construct(
        protected WorkOrderPrintService $print,
        protected PdfRenderer $renderer,
        protected GeneratedDocumentArchiveService $archive,
    ) {}

    public function generate(WorkOrder $workOrder, ?User $actor = null): GeneratedDocument
    {
        // La génération est une action métier sensible : la Policy est
        // appliquée ici, pas seulement dans l'action Filament. Un appel
        // direct au service (commande, job, test) doit être refusé aussi.
        if ($actor === null || ! $actor->exists) {
            throw ValidationException::withMessages([
                'pdf' => 'Un utilisateur autorisé et enregistré est requis pour générer le PDF.',
            ]);
        }

        Gate::forUser($actor)->authorize('generatePdf', $workOrder);

        $data = $this->print->build($workOrder, $actor);

        $contents = $this->renderer->render('pdf.work-order', [
            'data' => $data,
            'companyName' => (string) config('pdf.company_name', 'C2ME'),
        ]);

        return $this->archive->archive(
            $workOrder,
            $contents,
            GeneratedDocumentType::WorkOrderPdf,
            [
                'source_data_hash' => $data->dataHash,
                'of_revision' => $data->ofRevision,
                'revision_snapshot' => $data->revisionSnapshot,
                'visa_snapshot' => $data->visaSnapshot,
                'template_version' => $data->templateVersion,
            ],
            $actor,
        );
    }
}
