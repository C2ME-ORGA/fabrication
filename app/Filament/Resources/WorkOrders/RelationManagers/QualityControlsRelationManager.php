<?php

namespace App\Filament\Resources\WorkOrders\RelationManagers;

use App\Enums\NonConformityCategory;
use App\Enums\NonConformitySeverity;
use App\Enums\QualityControlResultType;
use App\Enums\QualityControlSamplingMode;
use App\Enums\QualityControlStatus;
use App\Enums\QualityRequirementScope;
use App\Enums\QualityRequirementTrigger;
use App\Enums\QualityResultDecision;
use App\Models\NonConformity;
use App\Models\QualityControlResult;
use App\Models\QualityControlTemplate;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderQualityControl;
use App\Models\WorkOrderQualityControlPoint;
use App\Services\NonConformityService;
use App\Services\QualityControlEvaluationService;
use App\Services\QualityControlResultService;
use App\Services\WorkOrderQualityPlanService;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Plan qualité généré sur la fiche OF.
 *
 * L'écran ne fait qu'appeler les services : saisie d'un résultat, validation
 * (y compris seconde validation d'un point critique) et annulation d'un
 * contrôle. Aucune règle métier n'est dupliquée ici.
 */
class QualityControlsRelationManager extends RelationManager
{
    protected static string $relationship = 'qualityControls';

    protected static ?string $title = 'Contrôles qualité';

    protected static ?bool $isLazyLoaded = false;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth()->user()?->can('view', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('template_name')
            ->modifyQueryUsing(fn ($query) => $query->with([
                'points.results.validations',
                'operation',
                'item',
            ]))
            ->defaultSort('position')
            ->paginated([10, 25, 50])
            ->columns([
                TextColumn::make('position')
                    ->label('Ordre')
                    ->numeric()
                    ->width('70px')
                    ->sortable(),
                TextColumn::make('template_name')
                    ->label('Modèle')
                    ->description(fn (WorkOrderQualityControl $record): string => $record->template_code.' / v'.$record->template_version_number)
                    ->searchable()
                    ->wrap()
                    ->weight('bold'),
                TextColumn::make('control_type')
                    ->label('Type')
                    ->badge(),
                TextColumn::make('scope')
                    ->label('Portée')
                    ->badge(),
                TextColumn::make('trigger')
                    ->label('Déclencheur')
                    ->badge(),
                TextColumn::make('operation.code')
                    ->label('Opération')
                    ->placeholder('—'),
                TextColumn::make('item.mark')
                    ->label('Repère ciblé')
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label('Statut')
                    ->badge(),
                TextColumn::make('progress')
                    ->label('Avancement')
                    ->state(fn (WorkOrderQualityControl $record): string => app(QualityControlEvaluationService::class)->summary($record)),
                IconColumn::make('is_mandatory')
                    ->label('Obligatoire')
                    ->boolean(),
                TextColumn::make('completed_at')
                    ->label('Clôturé le')
                    ->dateTime('d/m/Y H:i')
                    ->timezone(config('quality.display_timezone', 'Europe/Paris'))
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->headerActions([
                Action::make('addTemplate')
                    ->label('Ajouter un modèle')
                    ->icon(Heroicon::OutlinedPlus)
                    ->color('primary')
                    ->modalHeading('Ajouter un modèle de contrôle')
                    ->modalDescription('Possible uniquement avant la libération de l\'OF. Le contrôle copie les points de la version active.')
                    ->modalSubmitActionLabel('Ajouter le contrôle')
                    ->schema([
                        Select::make('quality_control_template_id')
                            ->label('Modèle de contrôle')
                            ->options(fn (): array => QualityControlTemplate::query()
                                ->where('is_active', true)
                                ->orderBy('code')
                                ->get()
                                ->mapWithKeys(fn (QualityControlTemplate $template): array => [
                                    $template->getKey() => $template->code.' — '.$template->name,
                                ])
                                ->all())
                            ->searchable()
                            ->required()
                            ->native(false),
                        Select::make('scope')
                            ->label('Portée')
                            ->options(QualityRequirementScope::options())
                            ->default(QualityRequirementScope::Operation->value)
                            ->required()
                            ->native(false),
                        Select::make('trigger')
                            ->label('Déclencheur')
                            ->options(QualityRequirementTrigger::options())
                            ->default(QualityRequirementTrigger::OnCompletion->value)
                            ->required()
                            ->native(false),
                        Select::make('work_order_operation_id')
                            ->label('Opération rattachée')
                            ->options(fn (): array => $this->workOrder()->operations()
                                ->orderBy('position')
                                ->get()
                                ->mapWithKeys(fn ($operation): array => [
                                    $operation->getKey() => $operation->code.' — '.$operation->name,
                                ])
                                ->all())
                            ->searchable()
                            ->native(false),
                        Toggle::make('is_mandatory')
                            ->label('Contrôle obligatoire')
                            ->default(true),
                    ])
                    ->visible(fn (): bool => $this->canManageTemplates() && $this->workOrder()->isRoutingEditable())
                    ->authorize(fn (): bool => $this->canManageTemplates())
                    ->action(function (array $data): void {
                        $template = QualityControlTemplate::query()->find($data['quality_control_template_id'] ?? null);

                        if ($template === null) {
                            $this->notifyMessage('danger', 'Modèle introuvable', 'Le modèle de contrôle sélectionné n\'existe pas.');

                            return;
                        }

                        try {
                            app(WorkOrderQualityPlanService::class)->addTemplate(
                                $this->workOrder(),
                                $template,
                                $data,
                                $this->actor(),
                            );

                            $this->resetTable();

                            Notification::make()
                                ->success()
                                ->title('Contrôle ajouté')
                                ->body("Le modèle {$template->code} a été copié dans l'OF.")
                                ->send();
                        } catch (ValidationException $exception) {
                            $this->notifyRefusal($exception);
                        }
                    }),
            ])
            ->recordActions([
                Action::make('recordResult')
                    ->label('Saisir un résultat')
                    ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                    ->color('primary')
                    ->modalHeading('Saisir un résultat de contrôle')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalWidth('2xl')
                    ->schema($this->resultFormSchema())
                    ->visible(fn (WorkOrderQualityControl $record): bool => $this->canOnControl('recordResult', $record))
                    ->authorize(fn (WorkOrderQualityControl $record): bool => $this->canOnControl('recordResult', $record))
                    ->action(function (WorkOrderQualityControl $record, array $data): void {
                        $point = $record->points->firstWhere('id', (int) ($data['point_id'] ?? 0));

                        if (! $point instanceof WorkOrderQualityControlPoint) {
                            $this->notifyMessage('danger', 'Point introuvable', 'Le point de contrôle sélectionné est introuvable.');

                            return;
                        }

                        try {
                            app(QualityControlResultService::class)->record($point, $data, $this->actor());

                            $this->resetTable();

                            Notification::make()
                                ->success()
                                ->title('Résultat enregistré')
                                ->body("Le résultat du point « {$point->label} » a été enregistré.")
                                ->send();
                        } catch (ValidationException $exception) {
                            $this->notifyRefusal($exception);
                        }
                    }),
                Action::make('validateResult')
                    ->label('Valider / rejeter')
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->color('success')
                    ->modalHeading('Validation d\'un résultat')
                    ->modalDescription('Le contrôleur ne peut jamais valider son propre résultat. Un point critique exige deux validateurs distincts.')
                    ->modalSubmitActionLabel('Enregistrer la décision')
                    ->schema([
                        Select::make('result_id')
                            ->label('Résultat')
                            ->options(fn (WorkOrderQualityControl $record): array => $this->resultOptions($record))
                            ->searchable()
                            ->required()
                            ->native(false),
                        Select::make('decision')
                            ->label('Décision')
                            ->options(QualityResultDecision::options())
                            ->required()
                            ->native(false)
                            ->live(),
                        Textarea::make('comment')
                            ->label('Commentaire')
                            ->rows(3)
                            ->required(fn (Get $get): bool => $get('decision') === QualityResultDecision::Rejected->value),
                    ])
                    ->visible(fn (WorkOrderQualityControl $record): bool => $this->canOnControl('validateResult', $record)
                        && $this->resultOptions($record) !== [])
                    ->authorize(fn (WorkOrderQualityControl $record): bool => $this->canOnControl('validateResult', $record))
                    ->action(function (WorkOrderQualityControl $record, array $data): void {
                        $result = $record->results()->whereKey($data['result_id'] ?? null)->first();

                        if (! $result instanceof QualityControlResult) {
                            $this->notifyMessage('danger', 'Résultat introuvable', 'Le résultat sélectionné est introuvable.');

                            return;
                        }

                        try {
                            app(QualityControlResultService::class)->validate(
                                $result,
                                [
                                    'decision' => $data['decision'] ?? null,
                                    'comment' => $data['comment'] ?? null,
                                ],
                                $this->actor(),
                            );

                            $this->resetTable();

                            Notification::make()
                                ->success()
                                ->title('Décision enregistrée')
                                ->body('La décision de validation a été ajoutée à l\'historique.')
                                ->send();
                        } catch (ValidationException $exception) {
                            $this->notifyRefusal($exception);
                        }
                    }),
                Action::make('declareNonConformity')
                    ->label('Déclarer une NC')
                    ->icon(Heroicon::OutlinedExclamationTriangle)
                    ->color('danger')
                    ->modalHeading('Déclarer une non-conformité')
                    ->modalDescription('Sélectionnez un résultat non conforme ou laissez vide pour déclarer depuis un contrôle en échec.')
                    ->modalSubmitActionLabel('Déclarer la NC')
                    ->schema([
                        Select::make('quality_control_result_id')
                            ->label('Résultat non conforme (facultatif)')
                            ->options(fn (WorkOrderQualityControl $record): array => $record->results()
                                ->where('is_conform', false)
                                ->orderByDesc('id')
                                ->get()
                                ->mapWithKeys(fn (QualityControlResult $result): array => [
                                    $result->getKey() => sprintf(
                                        '#%d — %s — %s',
                                        $result->getKey(),
                                        $result->point?->label ?? 'Point',
                                        $result->displayValue(),
                                    ),
                                ])
                                ->all())
                            ->searchable()
                            ->native(false),
                        Select::make('severity')
                            ->label('Gravité')
                            ->options(NonConformitySeverity::options())
                            ->required()
                            ->native(false),
                        Select::make('category')
                            ->label('Catégorie')
                            ->options(NonConformityCategory::options())
                            ->required()
                            ->native(false),
                        DateTimePicker::make('detected_at')
                            ->label('Détectée le')
                            ->default(now())
                            ->required()
                            ->maxDate(now()),
                        Textarea::make('description')
                            ->label('Description factuelle')
                            ->required()
                            ->minLength(10)
                            ->rows(3),
                        Textarea::make('criterion')
                            ->label('Exigence ou critère non respecté')
                            ->rows(2),
                        Textarea::make('immediate_action')
                            ->label('Action immédiate / confinement')
                            ->rows(2),
                    ])
                    ->visible(fn (WorkOrderQualityControl $record): bool => $this->canDeclareNonConformity($record))
                    ->authorize(fn (WorkOrderQualityControl $record): bool => $this->canDeclareNonConformity($record))
                    ->action(function (WorkOrderQualityControl $record, array $data): void {
                        $resultId = $data['quality_control_result_id'] ?? null;

                        try {
                            if ($resultId !== null && $resultId !== '') {
                                $result = $record->results()->whereKey((int) $resultId)->first();

                                if (! $result instanceof QualityControlResult) {
                                    $this->notifyMessage('danger', 'Résultat introuvable', 'Le résultat sélectionné est introuvable.');

                                    return;
                                }

                                $nonConformity = app(NonConformityService::class)->createFromResult(
                                    $result,
                                    $data,
                                    $this->actor(),
                                );
                            } else {
                                $nonConformity = app(NonConformityService::class)->createFromControl(
                                    $record,
                                    $data,
                                    $this->actor(),
                                );
                            }

                            $this->resetTable();

                            Notification::make()
                                ->success()
                                ->title('Non-conformité déclarée')
                                ->body("La non-conformité {$nonConformity->reference} a été créée.")
                                ->send();
                        } catch (ValidationException $exception) {
                            $this->notifyRefusal($exception);
                        }
                    }),
                Action::make('cancelControl')
                    ->label('Annuler le contrôle')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->modalHeading('Annuler le contrôle qualité')
                    ->modalDescription('L\'annulation est définitive et tracée. Les résultats éventuels restent conservés.')
                    ->modalSubmitActionLabel('Annuler le contrôle')
                    ->schema([
                        Textarea::make('reason')
                            ->label('Motif d\'annulation')
                            ->required()
                            ->rows(3)
                            ->maxLength(2000),
                    ])
                    ->visible(fn (WorkOrderQualityControl $record): bool => $this->canOnControl('cancel', $record))
                    ->authorize(fn (WorkOrderQualityControl $record): bool => $this->canOnControl('cancel', $record))
                    ->action(function (WorkOrderQualityControl $record, array $data): void {
                        try {
                            app(WorkOrderQualityPlanService::class)->cancel(
                                $record,
                                $this->actor(),
                                (string) ($data['reason'] ?? ''),
                            );

                            $this->resetTable();

                            Notification::make()
                                ->success()
                                ->title('Contrôle annulé')
                                ->send();
                        } catch (ValidationException $exception) {
                            $this->notifyRefusal($exception);
                        }
                    }),
                Action::make('deleteManualControl')
                    ->label('Supprimer')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Supprimer le contrôle manuel')
                    ->modalDescription('Suppression physique autorisée uniquement avant libération et sans résultat. L\'opération est auditée.')
                    ->modalSubmitActionLabel('Supprimer définitivement')
                    ->visible(fn (WorkOrderQualityControl $record): bool => $this->canDeleteManualControl($record))
                    ->authorize(fn (WorkOrderQualityControl $record): bool => $this->canDeleteManualControl($record))
                    ->action(function (WorkOrderQualityControl $record): void {
                        try {
                            app(WorkOrderQualityPlanService::class)->deleteManualControl($record, $this->actor());

                            $this->resetTable();

                            Notification::make()
                                ->success()
                                ->title('Contrôle supprimé')
                                ->body('La suppression a été tracée dans le journal qualité.')
                                ->send();
                        } catch (ValidationException $exception) {
                            $this->notifyRefusal($exception);
                        }
                    }),
            ])
            ->toolbarActions([]);
    }

    /**
     * @return array<int, mixed>
     */
    protected function resultFormSchema(): array
    {
        return [
            Select::make('point_id')
                ->label('Point de contrôle')
                ->options(fn (WorkOrderQualityControl $record): array => $record->points
                    ->mapWithKeys(fn (WorkOrderQualityControlPoint $point): array => [
                        $point->getKey() => $point->label,
                    ])
                    ->all())
                ->required()
                ->native(false)
                ->live(),
            Select::make('work_order_item_id')
                ->label('Repère / pièce')
                ->options(fn (Get $get, WorkOrderQualityControl $record): array => $this->itemOptions($record, $get))
                ->searchable()
                ->native(false)
                ->visible(fn (WorkOrderQualityControl $record): bool => $record->scope === QualityRequirementScope::Item)
                ->required(fn (WorkOrderQualityControl $record): bool => $record->scope === QualityRequirementScope::Item),
            Select::make('value_boolean')
                ->label('Conformité')
                ->options(['1' => 'Conforme', '0' => 'Non conforme'])
                ->native(false)
                ->visible(fn (Get $get, WorkOrderQualityControl $record): bool => $this->selectedPoint($record, $get)?->result_type === QualityControlResultType::Conformity)
                ->required(fn (Get $get, WorkOrderQualityControl $record): bool => $this->selectedPoint($record, $get)?->result_type === QualityControlResultType::Conformity),
            TextInput::make('value_numeric')
                ->label('Mesure')
                ->numeric()
                ->suffix(fn (Get $get, WorkOrderQualityControl $record): ?string => $this->selectedPoint($record, $get)?->unit)
                ->helperText(fn (Get $get, WorkOrderQualityControl $record): ?string => $this->toleranceHint($record, $get))
                ->visible(fn (Get $get, WorkOrderQualityControl $record): bool => $this->selectedPoint($record, $get)?->result_type === QualityControlResultType::Numeric)
                ->required(fn (Get $get, WorkOrderQualityControl $record): bool => $this->selectedPoint($record, $get)?->result_type === QualityControlResultType::Numeric),
            Textarea::make('value_text')
                ->label('Texte constaté')
                ->rows(3)
                ->visible(fn (Get $get, WorkOrderQualityControl $record): bool => $this->selectedPoint($record, $get)?->result_type === QualityControlResultType::Text)
                ->required(fn (Get $get, WorkOrderQualityControl $record): bool => $this->selectedPoint($record, $get)?->result_type === QualityControlResultType::Text),
            Select::make('is_conform')
                ->label('Conformité constatée')
                ->options(['1' => 'Conforme', '0' => 'Non conforme'])
                ->native(false)
                ->helperText('Le texte ne vaut pas conformité : la qualification est obligatoire.')
                ->visible(fn (Get $get, WorkOrderQualityControl $record): bool => $this->selectedPoint($record, $get)?->result_type === QualityControlResultType::Text)
                ->required(fn (Get $get, WorkOrderQualityControl $record): bool => $this->selectedPoint($record, $get)?->result_type === QualityControlResultType::Text),
            Select::make('value_choice')
                ->label('Option constatée')
                ->options(fn (Get $get, WorkOrderQualityControl $record): array => collect($this->selectedPoint($record, $get)?->options ?? [])
                    ->mapWithKeys(fn (array $option): array => [
                        (string) ($option['value'] ?? '') => (string) ($option['label'] ?? ($option['value'] ?? '')),
                    ])
                    ->all())
                ->native(false)
                ->visible(fn (Get $get, WorkOrderQualityControl $record): bool => $this->selectedPoint($record, $get)?->result_type === QualityControlResultType::Choice)
                ->required(fn (Get $get, WorkOrderQualityControl $record): bool => $this->selectedPoint($record, $get)?->result_type === QualityControlResultType::Choice),
            Textarea::make('comment')
                ->label('Commentaire')
                ->rows(2)
                ->maxLength(2000),
            FileUpload::make('evidence')
                ->label('Preuves')
                ->multiple()
                ->storeFiles(false)
                ->maxSize((int) config('quality.evidence.max_size_kb', 25600)),
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function itemOptions(WorkOrderQualityControl $control, Get $get): array
    {
        $point = $this->selectedPoint($control, $get);
        $sampledIds = $point?->sampling_mode === QualityControlSamplingMode::EveryN
            ? $point->sampledItemIds()
            : [];

        $query = $this->workOrder()->items()->orderBy('mark');

        if ($sampledIds !== []) {
            $query->whereIn('id', $sampledIds);
        }

        return $query->get()->mapWithKeys(fn ($item): array => [
            $item->getKey() => $item->mark.' — '.$item->designation,
        ])->all();
    }

    protected function selectedPoint(WorkOrderQualityControl $control, Get $get): ?WorkOrderQualityControlPoint
    {
        return $control->points->firstWhere('id', (int) ($get('point_id') ?? 0));
    }

    protected function toleranceHint(WorkOrderQualityControl $control, Get $get): ?string
    {
        $point = $this->selectedPoint($control, $get);

        if ($point === null || $point->result_type !== QualityControlResultType::Numeric) {
            return null;
        }

        $min = $point->tolerance_min ?? '−∞';
        $max = $point->tolerance_max ?? '+∞';

        return 'Tolérance : ['.$min.' ; '.$max.'] '.($point->unit ?? '');
    }

    /**
     * @return array<int, string>
     */
    protected function resultOptions(WorkOrderQualityControl $control): array
    {
        return $control->results()
            ->with('point')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->mapWithKeys(fn (QualityControlResult $result): array => [
                $result->getKey() => sprintf(
                    '#%d — %s — %s — %s',
                    $result->getKey(),
                    $result->point?->label ?? 'Point',
                    $result->displayValue(),
                    $result->created_at?->timezone(config('quality.display_timezone', 'Europe/Paris'))->format('d/m/Y H:i') ?? '',
                ),
            ])
            ->all();
    }

    protected function canManageTemplates(): bool
    {
        return auth()->user()?->canManageQualityTemplates() === true;
    }

    protected function canDeleteManualControl(WorkOrderQualityControl $control): bool
    {
        return $this->canManageTemplates()
            && $this->workOrder()->isRoutingEditable()
            && $control->source_type === 'manual'
            && $control->source_requirement_id === null
            && ! $control->hasResults();
    }

    protected function canDeclareNonConformity(WorkOrderQualityControl $control): bool
    {
        if (! auth()->user()?->can('create', NonConformity::class)) {
            return false;
        }

        if ($control->status === QualityControlStatus::Cancelled) {
            return false;
        }

        return $control->status === QualityControlStatus::Failed
            || $control->results()->where('is_conform', false)->exists();
    }

    protected function canOnControl(string $ability, WorkOrderQualityControl $control): bool
    {
        return (bool) auth()->user()?->can($ability, $control);
    }

    protected function actor(): User
    {
        $user = auth()->user();

        abort_if($user === null, 403);

        return $user;
    }

    protected function workOrder(): WorkOrder
    {
        /** @var WorkOrder $workOrder */
        $workOrder = $this->getOwnerRecord();

        return $workOrder;
    }

    protected function notifyRefusal(ValidationException $exception): void
    {
        $this->notifyMessage(
            'danger',
            'Action refusée',
            (string) collect($exception->errors())->flatten()->first(),
        );
    }

    protected function notifyMessage(string $level, string $title, string $body): void
    {
        Notification::make()
            ->{$level}()
            ->title($title)
            ->body($body)
            ->send();
    }
}
