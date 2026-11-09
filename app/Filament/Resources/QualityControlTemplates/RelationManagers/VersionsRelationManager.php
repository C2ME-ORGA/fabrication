<?php

namespace App\Filament\Resources\QualityControlTemplates\RelationManagers;

use App\Filament\Resources\QualityControlTemplateVersions\Actions\QualityControlTemplateVersionActions;
use App\Filament\Resources\QualityControlTemplateVersions\QualityControlTemplateVersionResource;
use App\Models\QualityControlTemplate;
use App\Models\QualityControlTemplateVersion;
use App\Services\QualityControlTemplateService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    protected static ?string $title = 'Versions';

    protected static ?bool $isLazyLoaded = false;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof QualityControlTemplate
            && (bool) auth()->user()?->can('view', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('points'))
            ->defaultSort('version_number', 'desc')
            ->paginated(false)
            ->columns([
                TextColumn::make('version_number')
                    ->label('Version')
                    ->formatStateUsing(fn (int $state): string => 'v'.$state)
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->sortable(),
                TextColumn::make('points_count')
                    ->label('Points')
                    ->numeric(),
                TextColumn::make('change_note')
                    ->label('Motif de changement')
                    ->limit(60)
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('activated_at')
                    ->label('Activée le')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),
                TextColumn::make('retired_at')
                    ->label('Retirée le')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),
            ])
            ->headerActions([
                Action::make('createVersion')
                    ->label('Nouvelle version')
                    ->icon(Heroicon::OutlinedDocumentDuplicate)
                    ->color('primary')
                    ->visible(fn (): bool => $this->canManage()
                        && $this->template()->activeVersion() !== null
                        && $this->template()->draftVersion() === null)
                    ->schema([
                        Textarea::make('change_note')
                            ->label('Motif de changement')
                            ->required()
                            ->rows(3)
                            ->maxLength(2000),
                    ])
                    ->modalHeading('Créer une nouvelle version')
                    ->action(function (array $data) {
                        try {
                            $version = app(QualityControlTemplateService::class)->startNewVersion(
                                $this->template(),
                                auth()->user(),
                                (string) $data['change_note'],
                            );

                            Notification::make()
                                ->success()
                                ->title('Version brouillon créée')
                                ->send();

                            return redirect(QualityControlTemplateVersionResource::getUrl('view', ['record' => $version]));
                        } catch (ValidationException $exception) {
                            $this->notifyRefusal($exception);

                            return null;
                        }
                    }),
            ])
            ->recordActions([
                Action::make('managePoints')
                    ->label('Points')
                    ->icon(Heroicon::OutlinedClipboardDocumentList)
                    ->color('gray')
                    ->url(fn (QualityControlTemplateVersion $record): string => QualityControlTemplateVersionResource::getUrl('view', ['record' => $record]))
                    ->visible(fn (): bool => $this->canManage()),
                QualityControlTemplateVersionActions::activate(),
                QualityControlTemplateVersionActions::deleteDraft(),
            ])
            ->toolbarActions([]);
    }

    protected function template(): QualityControlTemplate
    {
        /** @var QualityControlTemplate $template */
        $template = $this->getOwnerRecord();

        return $template;
    }

    protected function canManage(): bool
    {
        return (bool) auth()->user()?->can('manageVersions', $this->template());
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
