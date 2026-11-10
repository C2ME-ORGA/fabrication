<?php

namespace App\Filament\Resources\QualityControlTemplateVersions\RelationManagers;

use App\Enums\QualityControlResultType;
use App\Enums\QualityControlSamplingMode;
use App\Models\QualityControlPoint;
use App\Models\QualityControlTemplateVersion;
use App\Services\QualityControlPointService;
use App\Services\QualityControlSamplingService;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
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

class PointsRelationManager extends RelationManager
{
    protected static string $relationship = 'points';

    protected static ?string $title = 'Points de contrôle';

    protected static ?bool $isLazyLoaded = false;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof QualityControlTemplateVersion
            && (bool) auth()->user()?->can('view', $ownerRecord->template);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->paginated(false)
            ->columns([
                TextColumn::make('position')
                    ->label('Ordre')
                    ->numeric()
                    ->width('70px'),
                TextColumn::make('label')
                    ->label('Point de contrôle')
                    ->searchable()
                    ->wrap()
                    ->weight('bold'),
                TextColumn::make('result_type')
                    ->label('Type')
                    ->badge(),
                TextColumn::make('values')
                    ->label('Unité / tolérance')
                    ->state(fn (QualityControlPoint $record): string => $this->formatValues($record))
                    ->placeholder('—'),
                TextColumn::make('sampling_mode')
                    ->label('Échantillonnage')
                    ->state(fn (QualityControlPoint $record): string => $this->formatSampling($record)),
                IconColumn::make('is_mandatory')
                    ->label('Obligatoire')
                    ->boolean(),
                IconColumn::make('is_critical')
                    ->label('Critique')
                    ->boolean(),
                IconColumn::make('allow_deviation')
                    ->label('Dérogation')
                    ->boolean(),
            ])
            ->headerActions([
                Action::make('createPoint')
                    ->label('Ajouter un point')
                    ->icon(Heroicon::OutlinedPlus)
                    ->color('primary')
                    ->visible(fn (): bool => $this->canManage())
                    ->authorize(fn (): bool => $this->canManage())
                    ->schema($this->pointFormSchema())
                    ->modalHeading('Ajouter un point de contrôle')
                    ->modalSubmitActionLabel('Ajouter')
                    ->action(function (array $data): void {
                        try {
                            $point = app(QualityControlPointService::class)->create($this->version(), $data);

                            Notification::make()
                                ->success()
                                ->title('Point ajouté')
                                ->body("Le point « {$point->label} » a été ajouté.")
                                ->send();
                        } catch (ValidationException $exception) {
                            $this->notifyRefusal($exception);
                        }
                    }),
            ])
            ->recordActions([
                Action::make('editPoint')
                    ->label('Modifier')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->visible(fn (QualityControlPoint $record): bool => $record->isEditable() && $this->canManage())
                    ->authorize(fn (QualityControlPoint $record): bool => (bool) auth()->user()?->can('update', $record))
                    ->schema($this->pointFormSchema())
                    ->fillForm(fn (QualityControlPoint $record): array => [
                        'label' => $record->label,
                        'instructions' => $record->instructions,
                        'result_type' => $record->result_type->value,
                        'unit' => $record->unit,
                        'tolerance_min' => $record->tolerance_min,
                        'tolerance_max' => $record->tolerance_max,
                        'is_mandatory' => $record->is_mandatory,
                        'is_critical' => $record->is_critical,
                        'allow_deviation' => $record->allow_deviation,
                        'sampling_mode' => $record->sampling_mode->value,
                        'sampling_every' => $record->sampling_every,
                        'required_results' => $record->required_results,
                        'options' => $record->options,
                    ])
                    ->modalHeading('Modifier le point de contrôle')
                    ->action(function (QualityControlPoint $record, array $data): void {
                        try {
                            app(QualityControlPointService::class)->update($record, $data);

                            Notification::make()
                                ->success()
                                ->title('Point mis à jour')
                                ->send();
                        } catch (ValidationException $exception) {
                            $this->notifyRefusal($exception);
                        }
                    }),
                Action::make('deletePoint')
                    ->label('Supprimer')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (QualityControlPoint $record): bool => $record->isEditable() && $this->canManage())
                    ->authorize(fn (QualityControlPoint $record): bool => (bool) auth()->user()?->can('delete', $record))
                    ->action(function (QualityControlPoint $record): void {
                        try {
                            app(QualityControlPointService::class)->delete($record);

                            Notification::make()
                                ->success()
                                ->title('Point supprimé')
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
    protected function pointFormSchema(): array
    {
        return [
            TextInput::make('label')
                ->label('Intitulé')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),
            Textarea::make('instructions')
                ->label('Consigne')
                ->rows(3)
                ->columnSpanFull(),
            Select::make('result_type')
                ->label('Type de résultat')
                ->options(QualityControlResultType::options())
                ->required()
                ->native(false)
                ->live(),
            Select::make('sampling_mode')
                ->label('Échantillonnage')
                ->options(QualityControlSamplingMode::options())
                ->default(QualityControlSamplingMode::All->value)
                ->required()
                ->native(false)
                ->live(),
            TextInput::make('unit')
                ->label('Unité')
                ->maxLength(20)
                ->visible(fn (Get $get): bool => $get('result_type') === QualityControlResultType::Numeric->value)
                ->required(fn (Get $get): bool => $get('result_type') === QualityControlResultType::Numeric->value),
            TextInput::make('sampling_every')
                ->label('Une pièce sur N')
                ->numeric()
                ->minValue(2)
                ->visible(fn (Get $get): bool => $get('sampling_mode') === QualityControlSamplingMode::EveryN->value)
                ->required(fn (Get $get): bool => $get('sampling_mode') === QualityControlSamplingMode::EveryN->value),
            TextInput::make('tolerance_min')
                ->label('Tolérance minimale')
                ->numeric()
                ->visible(fn (Get $get): bool => $get('result_type') === QualityControlResultType::Numeric->value),
            TextInput::make('tolerance_max')
                ->label('Tolérance maximale')
                ->numeric()
                ->visible(fn (Get $get): bool => $get('result_type') === QualityControlResultType::Numeric->value),
            TextInput::make('required_results')
                ->label('Nombre de résultats attendus')
                ->numeric()
                ->minValue(1)
                ->default(1)
                ->visible(fn (Get $get): bool => $get('sampling_mode') === QualityControlSamplingMode::Fixed->value)
                ->required(fn (Get $get): bool => $get('sampling_mode') === QualityControlSamplingMode::Fixed->value),
            Toggle::make('is_mandatory')
                ->label('Point obligatoire')
                ->default(true),
            Toggle::make('is_critical')
                ->label('Point critique')
                ->helperText('Un point critique exige deux validations distinctes, hors contrôleur.')
                ->default(false),
            Toggle::make('allow_deviation')
                ->label('Dérogation autorisée')
                ->helperText('Désactivé par défaut. Un contrôle manquant ne peut jamais être dérogé.')
                ->default(false),
            Repeater::make('options')
                ->label('Options de la liste')
                ->schema([
                    TextInput::make('value')
                        ->label('Valeur')
                        ->required()
                        ->maxLength(100),
                    TextInput::make('label')
                        ->label('Libellé')
                        ->required()
                        ->maxLength(255),
                    Toggle::make('conformant')
                        ->label('Conforme'),
                ])
                ->columns(3)
                ->minItems(1)
                ->visible(fn (Get $get): bool => $get('result_type') === QualityControlResultType::Choice->value)
                ->columnSpanFull(),
        ];
    }

    protected function formatValues(QualityControlPoint $point): string
    {
        if ($point->result_type !== QualityControlResultType::Numeric) {
            return '—';
        }

        $band = '';

        if ($point->tolerance_min !== null || $point->tolerance_max !== null) {
            $band = ' ['.($point->tolerance_min ?? '−∞').' ; '.($point->tolerance_max ?? '+∞').']';
        }

        return trim(($point->unit ?? '').$band);
    }

    protected function formatSampling(QualityControlPoint $point): string
    {
        return app(QualityControlSamplingService::class)->describe(
            $point->sampling_mode,
            $point->sampling_every,
            $point->required_results,
        );
    }

    protected function version(): QualityControlTemplateVersion
    {
        /** @var QualityControlTemplateVersion $version */
        $version = $this->getOwnerRecord();

        return $version;
    }

    protected function canManage(): bool
    {
        return $this->version()->isDraft()
            && (bool) auth()->user()?->can('managePoints', $this->version()->template);
    }

    protected function notifyRefusal(ValidationException $exception): void
    {
        Notification::make()
            ->danger()
            ->title('Action refusée')
            ->body((string) collect($exception->errors())->flatten()->first())
            ->send();
    }
}
