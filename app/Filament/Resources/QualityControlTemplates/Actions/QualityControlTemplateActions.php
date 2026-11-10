<?php

namespace App\Filament\Resources\QualityControlTemplates\Actions;

use App\Filament\Resources\QualityControlTemplateVersions\QualityControlTemplateVersionResource;
use App\Models\QualityControlTemplate;
use App\Services\QualityControlTemplateService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

class QualityControlTemplateActions
{
    public static function deactivate(): Action
    {
        return Action::make('deactivateQualityTemplate')
            ->label('Désactiver')
            ->icon(Heroicon::OutlinedPauseCircle)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Désactiver le modèle')
            ->modalDescription('Un modèle désactivé reste consultable et conservé historiquement, mais ne peut plus être exigé dans une gamme.')
            ->visible(fn (QualityControlTemplate $record): bool => (bool) auth()->user()?->can('deactivate', $record))
            ->authorize(fn (QualityControlTemplate $record): bool => (bool) auth()->user()?->can('deactivate', $record))
            ->action(function (QualityControlTemplate $record): void {
                try {
                    app(QualityControlTemplateService::class)->deactivate($record, auth()->user());

                    Notification::make()
                        ->success()
                        ->title('Modèle désactivé')
                        ->body("Le modèle {$record->code} a été désactivé.")
                        ->send();
                } catch (ValidationException $exception) {
                    self::notifyRefusal($exception);
                }
            });
    }

    public static function startNewVersion(): Action
    {
        return Action::make('startQualityTemplateVersion')
            ->label('Nouvelle version')
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->color('primary')
            ->visible(fn (QualityControlTemplate $record): bool => (bool) auth()->user()?->can('manageVersions', $record)
                && $record->activeVersion() !== null
                && $record->draftVersion() === null)
            ->authorize(fn (QualityControlTemplate $record): bool => (bool) auth()->user()?->can('manageVersions', $record))
            ->schema([
                Textarea::make('change_note')
                    ->label('Motif de changement')
                    ->required()
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->modalHeading('Créer une nouvelle version')
            ->modalDescription('La version active est copiée dans un nouveau brouillon. Modifiez ensuite ses points de contrôle.')
            ->action(function (QualityControlTemplate $record, array $data) {
                try {
                    $version = app(QualityControlTemplateService::class)->startNewVersion(
                        $record,
                        auth()->user(),
                        (string) $data['change_note'],
                    );

                    Notification::make()
                        ->success()
                        ->title('Version brouillon créée')
                        ->body("La version v{$version->version_number} est prête à être complétée.")
                        ->send();

                    return redirect(QualityControlTemplateVersionResource::getUrl('view', ['record' => $version]));
                } catch (ValidationException $exception) {
                    self::notifyRefusal($exception);

                    return null;
                }
            });
    }

    protected static function notifyRefusal(ValidationException $exception): void
    {
        Notification::make()
            ->danger()
            ->title('Action refusée')
            ->body((string) collect($exception->errors())->flatten()->first())
            ->send();
    }
}
