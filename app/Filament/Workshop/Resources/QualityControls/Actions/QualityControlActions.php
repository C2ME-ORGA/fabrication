<?php

namespace App\Filament\Workshop\Resources\QualityControls\Actions;

use App\Enums\NonConformityCategory;
use App\Enums\NonConformitySeverity;
use App\Enums\QualityControlStatus;
use App\Enums\QualityResultDecision;
use App\Filament\Workshop\Resources\QualityControls\Schemas\QualityControlResultForm;
use App\Models\NonConformity;
use App\Models\QualityControlResult;
use App\Models\User;
use App\Models\WorkOrderQualityControl;
use App\Models\WorkOrderQualityControlPoint;
use App\Services\NonConformityService;
use App\Services\QualityControlResultService;
use App\Services\WorkOrderQualityPlanService;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class QualityControlActions
{
    public static function recordResult(?WorkOrderQualityControlPoint $fixedPoint = null): Action
    {
        return Action::make('recordQualityResult'.($fixedPoint?->getKey() ?? ''))
            ->label('Saisir un résultat')
            ->icon(Heroicon::OutlinedClipboardDocumentCheck)
            ->color('primary')
            ->modalHeading('Saisir un résultat de contrôle')
            ->modalSubmitActionLabel('Enregistrer')
            ->modalWidth('2xl')
            ->schema(fn (WorkOrderQualityControl $record): array => QualityControlResultForm::schema($record, $fixedPoint))
            ->visible(fn (WorkOrderQualityControl $record): bool => self::canOnControl('recordResult', $record))
            ->authorize(fn (WorkOrderQualityControl $record): bool => self::canOnControl('recordResult', $record))
            ->action(function (WorkOrderQualityControl $record, array $data, Component $livewire): void {
                $pointId = $data['point_id'] ?? null;
                $point = $record->points()->whereKey((int) $pointId)->first();

                if (! $point instanceof WorkOrderQualityControlPoint) {
                    self::notify('danger', 'Point introuvable', 'Le point de contrôle sélectionné est introuvable.');

                    return;
                }

                try {
                    app(QualityControlResultService::class)->record($point, $data, self::actor());
                    self::refreshViewRecord($livewire);
                    self::notify('success', 'Résultat enregistré', "Le résultat du point « {$point->label} » a été enregistré.");
                } catch (ValidationException $exception) {
                    self::notifyRefusal($exception);
                }
            });
    }

    public static function recordResultForPoint(): Action
    {
        return Action::make('recordQualityResultForPoint')
            ->label('Saisir')
            ->icon(Heroicon::OutlinedClipboardDocumentCheck)
            ->color('primary')
            ->modalHeading('Saisir un résultat de contrôle')
            ->modalSubmitActionLabel('Enregistrer')
            ->schema(fn (WorkOrderQualityControlPoint $record): array => QualityControlResultForm::schema($record->control, $record))
            ->visible(fn (WorkOrderQualityControlPoint $record): bool => self::canOnControl('recordResult', $record->control))
            ->authorize(fn (WorkOrderQualityControlPoint $record): bool => self::canOnControl('recordResult', $record->control))
            ->action(function (WorkOrderQualityControlPoint $record, array $data, Component $livewire): void {
                try {
                    app(QualityControlResultService::class)->record($record, $data, self::actor());
                    self::refreshViewRecord($livewire);
                    self::notify('success', 'Résultat enregistré', "Le résultat du point « {$record->label} » a été enregistré.");
                } catch (ValidationException $exception) {
                    self::notifyRefusal($exception);
                }
            });
    }

    public static function validateResult(): Action
    {
        return Action::make('validateQualityResult')
            ->label('Valider / rejeter')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->color('success')
            ->modalHeading('Validation d’un résultat')
            ->schema([
                Select::make('result_id')
                    ->label('Résultat')
                    ->options(fn (WorkOrderQualityControl $record): array => self::resultOptions($record))
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
            ->visible(fn (WorkOrderQualityControl $record): bool => self::canOnControl('validateResult', $record)
                && self::resultOptions($record) !== [])
            ->authorize(fn (WorkOrderQualityControl $record): bool => self::canOnControl('validateResult', $record))
            ->action(function (WorkOrderQualityControl $record, array $data, Component $livewire): void {
                $result = $record->results()->whereKey($data['result_id'] ?? null)->first();

                if (! $result instanceof QualityControlResult) {
                    self::notify('danger', 'Résultat introuvable', 'Le résultat sélectionné est introuvable.');

                    return;
                }

                try {
                    app(QualityControlResultService::class)->validate(
                        $result,
                        [
                            'decision' => $data['decision'] ?? null,
                            'comment' => $data['comment'] ?? null,
                        ],
                        self::actor(),
                    );

                    self::refreshViewRecord($livewire);
                    self::notify('success', 'Décision enregistrée', 'La décision a été ajoutée à l’historique.');
                } catch (ValidationException $exception) {
                    self::notifyRefusal($exception);
                }
            });
    }

    public static function secondValidation(): Action
    {
        return Action::make('secondQualityValidation')
            ->label('2e validation critique')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('warning')
            ->modalHeading('Seconde validation d’un résultat critique')
            ->modalDescription('Vous devez être distinct du contrôleur et du premier valideur.')
            ->schema([
                Select::make('result_id')
                    ->label('Résultat critique')
                    ->options(fn (WorkOrderQualityControl $record): array => self::secondValidationOptions($record))
                    ->required()
                    ->native(false),
                Textarea::make('comment')
                    ->label('Commentaire')
                    ->rows(3),
            ])
            ->visible(fn (WorkOrderQualityControl $record): bool => self::canOnControl('validateResult', $record)
                && self::secondValidationOptions($record) !== [])
            ->authorize(fn (WorkOrderQualityControl $record): bool => self::canOnControl('validateResult', $record))
            ->action(function (WorkOrderQualityControl $record, array $data, Component $livewire): void {
                $result = $record->results()->whereKey($data['result_id'] ?? null)->first();

                if (! $result instanceof QualityControlResult) {
                    self::notify('danger', 'Résultat introuvable', 'Le résultat critique sélectionné est introuvable.');

                    return;
                }

                try {
                    app(QualityControlResultService::class)->validate(
                        $result,
                        ['decision' => 'validated', 'comment' => $data['comment'] ?? null],
                        self::actor(),
                    );

                    self::refreshViewRecord($livewire);
                    self::notify('success', 'Seconde validation enregistrée', 'Le résultat critique a reçu sa seconde validation.');
                } catch (ValidationException $exception) {
                    self::notifyRefusal($exception);
                }
            });
    }

    public static function declareNonConformity(): Action
    {
        return Action::make('workshopDeclareNonConformity')
            ->label('Déclarer une NC')
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->color('danger')
            ->modalHeading('Déclarer une non-conformité')
            ->modalSubmitActionLabel('Déclarer la NC')
            ->schema([
                Select::make('quality_control_result_id')
                    ->label('Résultat non conforme (facultatif)')
                    ->options(fn (WorkOrderQualityControl $record): array => $record->results()
                        ->where('is_conform', false)
                        ->orderByDesc('id')
                        ->get()
                        ->mapWithKeys(fn (QualityControlResult $result): array => [
                            $result->getKey() => '#'.$result->getKey().' — '.$result->displayValue(),
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
            ->visible(fn (WorkOrderQualityControl $record): bool => self::canDeclareNonConformity($record))
            ->authorize(fn (WorkOrderQualityControl $record): bool => self::canDeclareNonConformity($record))
            ->action(function (WorkOrderQualityControl $record, array $data, Component $livewire): void {
                try {
                    $resultId = $data['quality_control_result_id'] ?? null;

                    if ($resultId !== null && $resultId !== '') {
                        $result = $record->results()->whereKey((int) $resultId)->first();

                        if (! $result instanceof QualityControlResult) {
                            self::notify('danger', 'Résultat introuvable', 'Le résultat sélectionné est introuvable.');

                            return;
                        }

                        $nonConformity = app(NonConformityService::class)->createFromResult($result, $data, self::actor());
                    } else {
                        $nonConformity = app(NonConformityService::class)->createFromControl($record, $data, self::actor());
                    }

                    self::refreshViewRecord($livewire);
                    self::notify('success', 'Non-conformité déclarée', "La non-conformité {$nonConformity->reference} a été créée.");
                } catch (ValidationException $exception) {
                    self::notifyRefusal($exception);
                }
            });
    }

    public static function cancel(): Action
    {
        return Action::make('cancelQualityControl')
            ->label('Annuler le contrôle')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->modalHeading('Annuler le contrôle qualité')
            ->schema([
                Textarea::make('reason')
                    ->label('Motif d’annulation')
                    ->required()
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->visible(fn (WorkOrderQualityControl $record): bool => self::canOnControl('cancel', $record))
            ->authorize(fn (WorkOrderQualityControl $record): bool => self::canOnControl('cancel', $record))
            ->action(function (WorkOrderQualityControl $record, array $data, Component $livewire): void {
                try {
                    app(WorkOrderQualityPlanService::class)->cancel(
                        $record,
                        self::actor(),
                        (string) ($data['reason'] ?? ''),
                    );

                    self::refreshViewRecord($livewire);
                    self::notify('success', 'Contrôle annulé', 'L’annulation a été tracée.');
                } catch (ValidationException $exception) {
                    self::notifyRefusal($exception);
                }
            });
    }

    /**
     * @return array<int, string>
     */
    public static function resultOptions(WorkOrderQualityControl $control): array
    {
        return $control->results()
            ->with('point')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->filter(fn (QualityControlResult $result): bool => ! $result->isRejected())
            ->filter(fn (QualityControlResult $result): bool => $result->validatedUsersCount() < $result->point->requiredValidations())
            ->mapWithKeys(fn (QualityControlResult $result): array => [
                $result->getKey() => sprintf(
                    '#%d — %s — %s',
                    $result->getKey(),
                    $result->point?->label ?? 'Point',
                    $result->displayValue(),
                ),
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public static function secondValidationOptions(WorkOrderQualityControl $control): array
    {
        $user = auth()->user();

        if ($user === null) {
            return [];
        }

        return $control->results()
            ->with(['point', 'validations'])
            ->orderByDesc('id')
            ->get()
            ->filter(fn (QualityControlResult $result): bool => $result->point?->is_critical === true)
            ->filter(fn (QualityControlResult $result): bool => ! $result->isRejected())
            ->filter(fn (QualityControlResult $result): bool => $result->validatedUsersCount() === 1)
            ->filter(fn (QualityControlResult $result): bool => (int) $result->performed_by !== (int) $user->getKey())
            ->filter(fn (QualityControlResult $result): bool => ! $result->validations
                ->contains(fn ($validation): bool => (int) $validation->validated_by === (int) $user->getKey()))
            ->mapWithKeys(fn (QualityControlResult $result): array => [
                $result->getKey() => '#'.$result->getKey().' — '.$result->point?->label.' — '.$result->displayValue(),
            ])
            ->all();
    }

    protected static function canOnControl(string $ability, WorkOrderQualityControl $control): bool
    {
        return (bool) auth()->user()?->can($ability, $control);
    }

    protected static function refreshViewRecord(Component $livewire): void
    {
        if ($livewire instanceof ViewRecord) {
            $livewire->record->refresh();
        }
    }

    protected static function canDeclareNonConformity(WorkOrderQualityControl $control): bool
    {
        $user = auth()->user();

        if ($user?->can('create', NonConformity::class) !== true) {
            return false;
        }

        if ($control->status === QualityControlStatus::Cancelled) {
            return false;
        }

        return $control->status === QualityControlStatus::Failed
            || $control->results()->where('is_conform', false)->exists();
    }

    protected static function actor(): User
    {
        $user = auth()->user();
        abort_if($user === null, 403);

        return $user;
    }

    protected static function notify(string $level, string $title, string $body): void
    {
        Notification::make()->{$level}()->title($title)->body($body)->send();
    }

    /**
     * Affiche le refus métier et relance l'exception pour que Filament garde
     * la modale ouverte et conserve les valeurs déjà saisies.
     */
    protected static function notifyRefusal(ValidationException $exception): never
    {
        self::notify('danger', 'Action refusée', (string) collect($exception->errors())->flatten()->first());

        throw $exception;
    }
}
