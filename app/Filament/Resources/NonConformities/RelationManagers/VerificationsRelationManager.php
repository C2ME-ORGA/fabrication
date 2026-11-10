<?php

namespace App\Filament\Resources\NonConformities\RelationManagers;

use App\Enums\NonConformityStatus;
use App\Enums\NonConformityVerificationResult;
use App\Enums\NonConformityVerificationType;
use App\Models\NonConformity;
use App\Models\User;
use App\Models\WorkOrderQualityControl;
use App\Services\NonConformityVerificationService;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class VerificationsRelationManager extends RelationManager
{
    protected static string $relationship = 'verifications';

    protected static ?string $title = 'Vérifications et contre-contrôles';

    protected static ?bool $isLazyLoaded = false;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth()->user()?->can('view', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->paginated(false)
            ->columns([
                TextColumn::make('created_at')
                    ->label('Enregistrée le')
                    ->dateTime('d/m/Y H:i'),
                TextColumn::make('type')
                    ->label('Type')
                    ->badge(),
                TextColumn::make('result')
                    ->label('Résultat')
                    ->badge(),
                TextColumn::make('performer.name')
                    ->label('Réalisée par')
                    ->placeholder('—'),
                TextColumn::make('performed_at')
                    ->label('Réalisée le')
                    ->dateTime('d/m/Y H:i'),
                TextColumn::make('counterControl.template_code')
                    ->label('Contre-contrôle')
                    ->placeholder('—'),
                TextColumn::make('comment')
                    ->label('Commentaire')
                    ->wrap()
                    ->limit(80),
            ])
            ->headerActions([
                Action::make('recordVerification')
                    ->label('Enregistrer une vérification')
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->color('primary')
                    ->modalHeading('Enregistrer une vérification')
                    ->modalDescription('La clôture utilisera les dernières vérifications conformes de chaque type.')
                    ->schema([
                        Select::make('type')
                            ->label('Type')
                            ->options(NonConformityVerificationType::options())
                            ->required()
                            ->native(false),
                        Select::make('result')
                            ->label('Résultat')
                            ->options(NonConformityVerificationResult::options())
                            ->required()
                            ->native(false),
                        DateTimePicker::make('performed_at')
                            ->label('Réalisée le')
                            ->default(now())
                            ->required()
                            ->maxDate(now()),
                        Select::make('counter_control_id')
                            ->label('Contre-contrôle qualité associé')
                            ->options(fn (): array => WorkOrderQualityControl::query()
                                ->where('work_order_id', $this->nonConformity()->work_order_id)
                                ->orderBy('position')
                                ->get()
                                ->mapWithKeys(fn (WorkOrderQualityControl $control): array => [
                                    $control->getKey() => $control->template_code.' — '.$control->template_name,
                                ])
                                ->all())
                            ->searchable()
                            ->native(false),
                        Textarea::make('comment')
                            ->label('Commentaire')
                            ->required()
                            ->rows(3),
                    ])
                    ->visible(fn (): bool => $this->canVerify())
                    ->authorize(fn (): bool => $this->canVerify())
                    ->action(function (array $data): void {
                        try {
                            app(NonConformityVerificationService::class)->record(
                                $this->nonConformity(),
                                $data,
                                $this->actor(),
                            );

                            $this->resetTable();
                            Notification::make()->success()->title('Vérification enregistrée')->send();
                        } catch (ValidationException $exception) {
                            $this->notifyRefusal($exception);
                        }
                    }),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }

    protected function canVerify(): bool
    {
        $nonConformity = $this->nonConformity();

        return $nonConformity->status === NonConformityStatus::PendingVerification
            && (bool) auth()->user()?->can('verify', $nonConformity);
    }

    protected function nonConformity(): NonConformity
    {
        /** @var NonConformity $nonConformity */
        $nonConformity = $this->getOwnerRecord();

        return $nonConformity;
    }

    protected function actor(): User
    {
        $user = auth()->user();
        abort_if($user === null, 403);

        return $user;
    }

    protected function notifyRefusal(ValidationException $exception): void
    {
        Notification::make()
            ->danger()
            ->title('Vérification refusée')
            ->body((string) collect($exception->errors())->flatten()->first())
            ->send();
    }
}
