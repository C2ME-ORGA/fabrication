<?php

namespace App\Services;

use App\Enums\JobStatus;
use App\Enums\NonConformityStatus;
use App\Enums\QualityAuditEvent;
use App\Enums\QualityAuditSubject;
use App\Enums\WorkOrderAuditEvent;
use App\Enums\WorkOrderPriority;
use App\Enums\WorkOrderStatus;
use App\Enums\WorkshopOperationStatus;
use App\Models\Document;
use App\Models\Job;
use App\Models\NonConformity;
use App\Models\OperationTimeEntry;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Single entry point for work order mutations.
 *
 * Filament forms/actions call this service; they never write the number,
 * status, release fields or dates directly. Every rule is enforced here so a
 * Livewire payload cannot bypass it.
 */
class WorkOrderService
{
    public function __construct(
        protected WorkOrderNumberGenerator $numbers,
        protected DocumentSnapshotService $documentSnapshots,
        protected DocumentApplicabilityService $applicability,
        protected WorkOrderAuditService $auditLog,
        protected QualityClosureEligibilityService $qualityClosure,
        protected QualityAuditService $qualityAudit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor = null): WorkOrder
    {
        $this->assertNoProtectedFields($data);

        return DB::transaction(function () use ($data, $actor): WorkOrder {
            $data = $this->validate($data);

            $job = Job::query()->lockForUpdate()->find($data['job_id']);

            if ($job === null) {
                $this->fail('job_id', "L'affaire sélectionnée est introuvable.");
            }

            if (! $job->acceptsNewWorkOrders()) {
                $this->fail(
                    'job_id',
                    "Impossible de créer un ordre de fabrication pour l'affaire {$job->code} : son statut est « {$job->status->getLabel()} ».",
                );
            }

            $workOrder = new WorkOrder;

            $workOrder->fill(Arr::only($data, [
                'job_id',
                'title',
                'description',
                'priority',
                'planned_start_at',
                'planned_end_at',
                'notes',
                'responsible_id',
            ]));

            $workOrder->number = $this->numbers->nextNumber();
            $workOrder->status = WorkOrderStatus::Draft;
            $workOrder->priority ??= WorkOrderPriority::Normal;
            $workOrder->created_by = $actor?->getKey() ?? Auth::id();
            $workOrder->save();

            // Journal de création (état initial Draft, pas d'état précédent).
            $this->audit($workOrder, WorkOrderAuditEvent::Created, $actor, null, WorkOrderStatus::Draft->value);

            return $workOrder->refresh();
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(WorkOrder $workOrder, array $data, ?User $actor = null): WorkOrder
    {
        $this->assertNoProtectedFields($data);

        return DB::transaction(function () use ($workOrder, $data, $actor): WorkOrder {
            $locked = $this->lock($workOrder);

            $this->assertEditable($locked);

            if (array_key_exists('job_id', $data) && (int) $data['job_id'] !== (int) $locked->job_id) {
                $this->fail('job_id', "L'affaire d'un ordre de fabrication ne peut pas être changée.");
            }

            $data = $this->validate($data, partial: true);

            $previousTechnicalValues = [];
            foreach (self::TECHNICAL_FIELDS as $field) {
                $previousTechnicalValues[$field] = $locked->{$field};
            }

            $locked->fill(Arr::only($data, [
                'title',
                'description',
                'priority',
                'planned_start_at',
                'planned_end_at',
                'notes',
                'responsible_id',
            ]));

            // Invalidation conditionnelle de l'approbation BE : uniquement si une
            // donnée technique a réellement changé.
            if ($locked->be_approved_by !== null && $locked->be_approved_at !== null) {
                $technicalChanged = false;
                foreach (self::TECHNICAL_FIELDS as $field) {
                    if ($locked->{$field} !== $previousTechnicalValues[$field]) {
                        $technicalChanged = true;
                        break;
                    }
                }

                if ($technicalChanged) {
                    $locked->be_approved_by = null;
                    $locked->be_approved_at = null;

                    // Tracer l'invalidation dans le journal d'audit.
                    $this->audit(
                        $locked,
                        WorkOrderAuditEvent::BeApprovalInvalidated,
                        $actor,
                        null,
                        $locked->status->value,
                        'Invalidation de l\'approbation BE suite à modification technique.',
                    );
                }
            }

            $this->assertChronology(
                $locked->planned_start_at,
                $locked->planned_end_at,
                'planned_end_at',
                'La fin prévisionnelle ne peut pas précéder le début prévisionnel.',
            );

            $locked->save();

            return $locked->refresh();
        }, attempts: 3);
    }

    /**
     * @param  string|null  $comment  Motif ou commentaire associé à la transition (ex: annulation).
     */
    public function transitionTo(WorkOrder $workOrder, WorkOrderStatus $target, ?User $actor = null, ?string $comment = null): WorkOrder
    {
        // L'obsolescence BE doit être auditée après le rollback de la transition
        // refusée : un audit écrit dans la transaction métier serait annulé.
        $obsoleteApproval = null;
        $qualityBlocked = null;

        try {
            return DB::transaction(function () use ($workOrder, $target, $actor, $comment, &$obsoleteApproval, &$qualityBlocked): WorkOrder {
                $locked = $this->lock($workOrder);
                $from = $locked->status;

                if (! $from->canTransitionTo($target)) {
                    // Transition interdite : aucune entrée d'audit n'est enregistrée.
                    $this->fail(
                        'status',
                        "Transition de statut interdite : « {$from->getLabel()} » vers « {$target->getLabel()} ».",
                    );
                }

                // Une clôture ou une annulation ne doit pas laisser de session
                // de chronométrage orpheline : l'opération disparaîtrait alors
                // de l'interface atelier et l'opérateur resterait bloqué par
                // la contrainte d'unicité de sa session active.
                if (in_array($target, [WorkOrderStatus::Completed, WorkOrderStatus::Cancelled], true)) {
                    $this->assertNoActiveTimeEntries($locked);
                    $this->assertNoInProgressOperations($locked);
                }

                if ($target === WorkOrderStatus::Released) {
                    if ($locked->be_approved_by === null || $locked->be_approved_at === null) {
                        $this->fail(
                            'status',
                            'Un ordre de fabrication ne peut être libéré sans approbation du bureau d\'études.',
                        );
                    }

                    // Une approbation BE antérieure à l'introduction des snapshots
                    // documentaires (be_approval_batch_id = null) doit être renouvelée.
                    // Un OF historique approuvé sans snapshot ne peut pas être libéré
                    // sans une nouvelle approbation explicite.
                    if ($locked->be_approval_batch_id === null) {
                        $this->fail(
                            'status',
                            'L\'approbation du bureau d\'études n\'est pas associée à un snapshot documentaire. Une nouvelle approbation est requise.',
                        );
                    }

                    // Verrouiller l'affaire parent avant les documents applicables
                    // pour sérialiser avec les créations de documents concurrentes
                    // et prévenir les interblocages (même ordre que dans
                    // DocumentService::create() et approveByBE()).
                    $job = Job::query()->lockForUpdate()->find($locked->job_id);

                    if ($job === null || $job->status !== JobStatus::Active) {
                        $this->fail(
                            'status',
                            'Un ordre de fabrication ne peut être libéré que si son affaire est active.',
                        );
                    }

                    // Verrouiller les documents applicables par identifiant croissant
                    // pour sérialiser avec les approbations de versions documentaires
                    // concurrentes et prévenir les interblocages.
                    $applicableDocumentIds = $this->applicability->applicableDocumentsFor($locked)
                        ->sortBy('id')
                        ->pluck('id')
                        ->toArray();

                    foreach ($applicableDocumentIds as $documentId) {
                        Document::query()->lockForUpdate()->findOrFail($documentId);
                    }

                    // Vérifier que l'état documentaire n'a pas évolué depuis
                    // l'approbation BE. Les documents sont verrouillés, donc aucune
                    // approbation concurrente ne peut modifier l'état entre la
                    // vérification et la capture du snapshot de libération.
                    $batchId = $this->getBEApprovalBatchId($locked);

                    if ($batchId !== null && ! $this->documentSnapshots->isBEApprovalStillValid($locked, $batchId)) {
                        // L'audit est écrit après le rollback de cette transaction.
                        $obsoleteApproval = [
                            'work_order_id' => $locked->getKey(),
                            'status' => $locked->status->value,
                        ];

                        $this->fail(
                            'status',
                            'L\'approbation du bureau d\'études est obsolète : les références documentaires ont évolué depuis l\'approbation. Une nouvelle approbation est requise.',
                        );
                    }

                    $this->assertQualityEligible(
                        $locked,
                        QualityClosureEligibilityService::PHASE_RELEASE,
                        $from,
                        $target,
                        $actor,
                        $qualityBlocked,
                    );

                    $locked->released_by = $actor?->getKey() ?? Auth::id();
                    $locked->released_at = now();

                    // Freeze the approved document versions applicable at release.
                    // The snapshot rows are written in this same transaction, on
                    // documents already locked above.
                    $this->documentSnapshots->captureForRelease($locked, $actor);
                }

                if ($target === WorkOrderStatus::InProgress && $locked->actual_start_at === null) {
                    $locked->actual_start_at = now();
                }

                if ($target === WorkOrderStatus::Completed) {
                    $this->assertQualityEligible(
                        $locked,
                        QualityClosureEligibilityService::PHASE_CLOSURE,
                        $from,
                        $target,
                        $actor,
                        $qualityBlocked,
                    );

                    $locked->actual_end_at ??= now();
                }

                $locked->status = $target;
                $locked->save();

                // Journal de la transition (dans la même transaction, annulé si le métier échoue).
                $this->audit(
                    $locked,
                    $this->eventForTransition($target),
                    $actor,
                    $from->value,
                    $target->value,
                    $comment,
                );

                if ($target === WorkOrderStatus::Cancelled) {
                    $this->auditCancellationWithOpenQualityIssues($locked, $actor);
                }

                return $locked->refresh();
            }, attempts: 3);
        } catch (ValidationException $exception) {
            if ($obsoleteApproval !== null) {
                $workOrderForAudit = WorkOrder::query()->findOrFail($obsoleteApproval['work_order_id']);

                DB::transaction(function () use ($workOrderForAudit, $actor, $obsoleteApproval): void {
                    $this->audit(
                        $workOrderForAudit,
                        WorkOrderAuditEvent::BeApprovalObsolete,
                        $actor,
                        $obsoleteApproval['status'],
                        $obsoleteApproval['status'],
                        'Approbation BE obsolète : les références documentaires ont évolué.',
                    );
                });
            }

            if ($qualityBlocked !== null) {
                $workOrderForAudit = WorkOrder::query()->findOrFail($qualityBlocked['work_order_id']);

                DB::transaction(function () use ($workOrderForAudit, $actor, $qualityBlocked): void {
                    $message = $qualityBlocked['eligibility']->message();

                    $this->audit(
                        $workOrderForAudit,
                        WorkOrderAuditEvent::QualityClosureBlocked,
                        $actor,
                        $qualityBlocked['from_status'],
                        $qualityBlocked['to_status'],
                        $message,
                        ['quality_eligibility' => $qualityBlocked['eligibility']->toArray()],
                    );

                    $this->qualityAudit->record(
                        QualityAuditEvent::ClosureBlocked,
                        QualityAuditSubject::WorkOrder,
                        (int) $workOrderForAudit->getKey(),
                        $workOrderForAudit->number,
                        $actor,
                        $workOrderForAudit,
                        $qualityBlocked['from_status'],
                        $qualityBlocked['to_status'],
                        $message,
                        ['eligibility' => $qualityBlocked['eligibility']->toArray()],
                    );
                });
            }

            throw $exception;
        }
    }

    protected function assertQualityEligible(
        WorkOrder $workOrder,
        string $phase,
        WorkOrderStatus $from,
        WorkOrderStatus $to,
        ?User $actor,
        ?array &$qualityBlocked,
    ): void {
        $eligibility = $this->qualityClosure->evaluate($workOrder, $phase);

        if (! $eligibility->eligible) {
            $qualityBlocked = [
                'work_order_id' => $workOrder->getKey(),
                'phase' => $phase,
                'from_status' => $from->value,
                'to_status' => $to->value,
                'eligibility' => $eligibility,
            ];

            $this->fail('quality', $eligibility->message());
        }

        $this->qualityAudit->record(
            QualityAuditEvent::ClosureEvaluated,
            QualityAuditSubject::WorkOrder,
            (int) $workOrder->getKey(),
            $workOrder->number,
            $actor,
            $workOrder,
            $from->value,
            $to->value,
            null,
            ['phase' => $phase, 'eligibility' => $eligibility->toArray()],
        );
    }

    protected function auditCancellationWithOpenQualityIssues(WorkOrder $workOrder, ?User $actor): void
    {
        $openCount = NonConformity::query()
            ->where('work_order_id', $workOrder->getKey())
            ->whereNotIn('status', [
                NonConformityStatus::Closed->value,
                NonConformityStatus::Cancelled->value,
            ])
            ->count();

        if ($openCount === 0) {
            return;
        }

        $this->qualityAudit->record(
            QualityAuditEvent::WorkOrderCancelledWithOpenQualityIssues,
            QualityAuditSubject::WorkOrder,
            (int) $workOrder->getKey(),
            $workOrder->number,
            $actor,
            $workOrder,
            WorkOrderStatus::Cancelled->value,
            WorkOrderStatus::Cancelled->value,
            "Annulation de l'OF avec {$openCount} non-conformité(s) encore ouverte(s).",
            ['open_non_conformities' => $openCount],
        );
    }

    /**
     * Approuve un OF par le bureau d'études avant sa libération.
     *
     * L'approbation peut être invalidée par une modification ultérieure de l'OF
     * (voir update()).
     *
     * @throws ValidationException
     */
    public function approveByBE(WorkOrder $workOrder, User $approver): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $approver): WorkOrder {
            $locked = $this->lock($workOrder);

            // Seuls les administrateurs peuvent approuver.
            if (! $approver->isAdmin()) {
                $this->fail(
                    'user',
                    'Seul un administrateur peut approuver un OF pour le bureau d\'études.',
                );
            }

            // Un OF terminé ou annulé est gelé : impossible à approuver.
            if ($locked->isLocked()) {
                $this->fail(
                    'status',
                    'Un OF terminé ou annulé ne peut pas être approuvé par le bureau d\'études.',
                );
            }

            // L'approbation est possible en brouillon ou prêt (avant libération).
            if (! in_array($locked->status, [WorkOrderStatus::Draft, WorkOrderStatus::Ready], true)) {
                $this->fail(
                    'status',
                    'L\'approbation du bureau d\'études n\'est possible que pour un OF au statut Brouillon ou Prêt.',
                );
            }

            // Verrouiller l'affaire parent avant les documents applicables
            // pour sérialiser avec les libérations et créations de documents
            // concurrentes. Même ordre de verrouillage (Job, puis documents
            // par ID croissant) que dans transitionTo() pour prévenir les
            // interblocages.
            $job = Job::query()->lockForUpdate()->find($locked->job_id);

            if ($job === null || $job->status !== JobStatus::Active) {
                $this->fail(
                    'status',
                    'L\'affaire de cet ordre de fabrication est introuvable ou inactif.',
                );
            }

            // Verrouiller les documents applicables par identifiant croissant
            // pour sérialiser avec les approbations de versions documentaires
            // concurrentes et prévenir les interblocages.
            $applicableDocumentIds = $this->applicability->applicableDocumentsFor($locked)
                ->sortBy('id')
                ->pluck('id')
                ->toArray();

            foreach ($applicableDocumentIds as $documentId) {
                Document::query()->lockForUpdate()->findOrFail($documentId);
            }

            $locked->be_approved_by = $approver->getKey();
            $locked->be_approved_at = now();

            // Capture the document state at BE approval time.
            [$snapshots, $batchId] = $this->documentSnapshots->captureForBEApproval($locked, $approver);

            $locked->be_approval_batch_id = $batchId;

            // Link the first snapshot as the approval reference.
            $firstSnapshot = $snapshots->first();
            if ($firstSnapshot !== null) {
                $locked->be_approval_snapshot_id = $firstSnapshot->getKey();
            }

            $locked->save();

            // Tracer l'approbation dans le journal d'audit.
            $this->audit(
                $locked,
                WorkOrderAuditEvent::BeApproved,
                $approver,
                null,
                $locked->status->value,
                'Approbation bureau d\'études',
                ['be_approval_batch_id' => $batchId],
            );

            return $locked->refresh();
        }, attempts: 3);
    }

    /**
     * Only drafts can be deleted: any later state carries history.
     */
    public function delete(WorkOrder $workOrder): void
    {
        DB::transaction(function () use ($workOrder): void {
            $locked = $this->lock($workOrder);

            if ($locked->status !== WorkOrderStatus::Draft) {
                $this->fail(
                    'status',
                    'Seul un ordre de fabrication au statut « Brouillon » peut être supprimé.',
                );
            }

            if ($locked->documents()->exists() || $locked->documentSnapshots()->exists()) {
                $this->fail(
                    'status',
                    'Un ordre de fabrication rattaché à des documents ou à des références documentaires ne peut pas être supprimé.',
                );
            }

            if ($locked->bomImports()->exists()) {
                $this->fail(
                    'status',
                    'Un ordre de fabrication possédant un historique d\'import de nomenclature ne peut pas être supprimé.',
                );
            }

            if ($locked->hasRecordedItemProduction()) {
                $this->fail(
                    'status',
                    'Un ordre de fabrication dont des pièces ont une production ou des rebuts ne peut pas être supprimé.',
                );
            }

            if ($locked->qualityResults()->exists()) {
                $this->fail(
                    'status',
                    'Un ordre de fabrication possédant des résultats de contrôle qualité ne peut pas être supprimé.',
                );
            }

            $locked->delete();
        });
    }

    protected function lock(WorkOrder $workOrder): WorkOrder
    {
        $locked = WorkOrder::query()->lockForUpdate()->find($workOrder->getKey());

        if ($locked === null) {
            $this->fail('id', "Cet ordre de fabrication n'existe plus.");
        }

        return $locked;
    }

    protected function assertEditable(WorkOrder $workOrder): void
    {
        if ($workOrder->isLocked()) {
            $this->fail(
                'status',
                "Un ordre de fabrication « {$workOrder->status->getLabel()} » ne peut plus être modifié.",
            );
        }
    }

    /**
     * Interdit la clôture ou l'annulation d'un OF tant qu'un chronomètre est
     * actif sur l'une de ses opérations.
     */
    protected function assertNoActiveTimeEntries(WorkOrder $workOrder): void
    {
        if (OperationTimeEntry::query()
            ->whereIn('work_order_operation_id', $workOrder->operations()->select('id'))
            ->whereNull('ended_at')
            ->exists()) {
            $this->fail(
                'time_entries',
                'Des sessions de chronométrage sont actives sur cet ordre de fabrication. Arrêtez-les avant de changer son statut.',
            );
        }
    }

    /**
     * Interdit la clôture ou l'annulation d'un OF tant qu'une opération est
     * encore en cours. L'annulation des opérations doit rester une action
     * explicite et auditée du moteur d'exécution.
     */
    protected function assertNoInProgressOperations(WorkOrder $workOrder): void
    {
        if ($workOrder->operations()
            ->where('status', WorkshopOperationStatus::InProgress->value)
            ->exists()) {
            $this->fail(
                'operations',
                'Des opérations sont encore en cours sur cet ordre de fabrication. Annulez-les explicitement avant de changer son statut.',
            );
        }
    }

    /**
     * Verrouille un OF et vérifie qu'il accepte encore des modifications.
     *
     * Point d'entrée public minimal pour les services satellites (pièces,
     * nomenclature) qui doivent réutiliser exactement les règles de FC-4
     * sans dupliquer le contrôle de verrouillage.
     */
    public function lockForMutation(WorkOrder $workOrder): WorkOrder
    {
        $locked = $this->lock($workOrder);

        $this->assertEditable($locked);

        return $locked;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function validate(array $data, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        $rules = [
            'job_id' => [$required, 'integer', Rule::exists('jobs', 'id')],
            'title' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            // On create the column default (`normal`) applies when omitted.
            // On update an explicit null would violate the NOT NULL column,
            // so it is rejected as a validation error instead of a DB error.
            'priority' => $partial
                ? ['sometimes', 'required', Rule::enum(WorkOrderPriority::class)]
                : ['nullable', Rule::enum(WorkOrderPriority::class)],
            'planned_start_at' => ['nullable', 'date'],
            'planned_end_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'responsible_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')],
        ];

        $validated = Validator::make($data, $rules)->validate();

        $start = Arr::get($validated, 'planned_start_at', $data['planned_start_at'] ?? null);
        $end = Arr::get($validated, 'planned_end_at', $data['planned_end_at'] ?? null);

        if ($start !== null && $end !== null) {
            $this->assertChronology(
                Carbon::parse($start),
                Carbon::parse($end),
                'planned_end_at',
                'La fin prévisionnelle ne peut pas précéder le début prévisionnel.',
            );
        }

        return $validated;
    }

    protected function assertChronology(?Carbon $start, ?Carbon $end, string $field, string $message): void
    {
        if ($start !== null && $end !== null && $end->lt($start)) {
            $this->fail($field, $message);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    /**
     * Seul champ protégé que le service accepte depuis l'entrée appelante :
     * le responsable opérationnel, assigné via la liste blanche de create()
     * et update().
     *
     * Les champs d'approbation BE (be_approved_by, be_approved_at,
     * be_approval_batch_id, be_approval_snapshot_id) sont écrits directement
     * par le service dans approveByBE() et ne doivent jamais pouvoir être
     * fournis par un formulaire ou un payload : ils restent donc rejetés par
     * assertNoProtectedFields().
     *
     * @var array<string>
     */
    protected const SERVICE_ASSIGNABLE_FIELDS = [
        'responsible_id',
    ];

    /**
     * Champs techniques dont la modification invalide l'approbation BE.
     * Les champs administratifs (title, priority, planned dates, notes,
     * responsible_id) ne l'invalident pas.
     *
     * @var array<string>
     */
    protected const TECHNICAL_FIELDS = ['description'];

    protected function assertNoProtectedFields(array $data): void
    {
        $forbidden = array_values(array_filter(
            WorkOrder::PROTECTED_FIELDS,
            fn (string $field): bool => array_key_exists($field, $data)
                && ! in_array($field, self::SERVICE_ASSIGNABLE_FIELDS, true),
        ));

        if ($forbidden !== []) {
            throw ValidationException::withMessages([
                'work_order' => 'Champ(s) non modifiable(s) directement : '.implode(', ', $forbidden).'.',
            ]);
        }
    }

    /**
     * Écrit une entrée d'audit immuable pour un événement sur un OF.
     *
     * Appelée dans la même transaction que l'action métier : si l'action
     * échoue et rollback, l'entrée d'audit est également annulée.
     *
     * @param  array<string, mixed>  $metadata
     */
    protected function audit(
        WorkOrder $workOrder,
        WorkOrderAuditEvent $event,
        ?User $actor = null,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        ?string $comment = null,
        array $metadata = [],
    ): void {
        $this->auditLog->record(
            $workOrder,
            $event,
            $actor,
            $fromStatus,
            $toStatus,
            $comment,
            $metadata,
        );
    }

    /**
     * Associe un type d'événement d'audit à une transition de statut cible.
     */
    protected function eventForTransition(WorkOrderStatus $target): WorkOrderAuditEvent
    {
        return match ($target) {
            WorkOrderStatus::Ready => WorkOrderAuditEvent::Ready,
            WorkOrderStatus::Released => WorkOrderAuditEvent::Released,
            WorkOrderStatus::InProgress => WorkOrderAuditEvent::Started,
            WorkOrderStatus::QualityCheck => WorkOrderAuditEvent::QualityCheck,
            WorkOrderStatus::Completed => WorkOrderAuditEvent::Completed,
            WorkOrderStatus::Cancelled => WorkOrderAuditEvent::Cancelled,
            WorkOrderStatus::Draft => WorkOrderAuditEvent::Created,
        };
    }

    /**
     * Récupère le batch UUID de l'approbation BE actuelle, stocké directement
     * sur l'OF. Permet de détecter l'obsolescence même quand aucun document
     * n'était applicable au moment de l'approbation (aucun snapshot créé).
     */
    protected function getBEApprovalBatchId(WorkOrder $workOrder): ?string
    {
        return $workOrder->be_approval_batch_id;
    }

    protected function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
