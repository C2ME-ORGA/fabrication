<?php

namespace App\Filament\Resources\QualityControlTemplateVersions\Actions;

use App\Models\QualityControlTemplateVersion;
use App\Services\QualityControlTemplateService;
use App\Services\QualityControlTemplateVersionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

class QualityControlTemplateVersionActions
{
    public static function activate(): Action
    {
        return Action::make('activateQualityTemplateVersion')
            ->label('Activer')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (QualityControlTemplateVersion $record): bool => $record->isDraft()
                && (bool) auth()->user()?->can('manageVersions', $record->template))
            ->authorize(fn (QualityControlTemplateVersion $record): bool => (bool) auth()->user()?->can('manageVersions', $record->template))
            ->schema([
                Textarea::make('change_note')
                    ->label('Motif de changement')
                    ->required(fn (QualityControlTemplateVersion $record): bool => (int) $record->version_number > 1)
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->modalHeading('Activer la version')
            ->modalDescription('La version doit contenir au moins un point obligatoire et des points cohérents.')
            ->action(function (QualityControlTemplateVersion $record, array $data): void {
                try {
                    app(QualityControlTemplateService::class)->activate(
                        $record->template,
                        $record,
                        auth()->user(),
                        isset($data['change_note']) ? (string) $data['change_note'] : null,
                    );

                    Notification::make()
                        ->success()
                        ->title('Version activée')
                        ->body("La version v{$record->version_number} est désormais active.")
                        ->send();
                } catch (ValidationException $exception) {
                    self::notifyRefusal($exception);
                }
            });
    }

    public static function deleteDraft(): Action
    {
        return Action::make('deleteQualityTemplateDraft')
            ->label('Supprimer le brouillon')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (QualityControlTemplateVersion $record): bool => $record->isDraft()
                && (bool) auth()->user()?->can('manageVersions', $record->template)
                && $record->template->versions()->count() > 1)
            ->action(function (QualityControlTemplateVersion $record): void {
                try {
                    app(QualityControlTemplateVersionService::class)->deleteDraft($record, auth()->user());

                    Notification::make()
                        ->success()
                        ->title('Version brouillon supprimée')
                        ->send();
                } catch (ValidationException $exception) {
                    self::notifyRefusal($exception);
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
