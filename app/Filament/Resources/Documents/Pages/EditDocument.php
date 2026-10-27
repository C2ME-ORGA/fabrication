<?php

namespace App\Filament\Resources\Documents\Pages;

use App\Filament\Resources\Documents\Actions\DocumentActions;
use App\Filament\Resources\Documents\DocumentResource;
use App\Services\DocumentService;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditDocument extends EditRecord
{
    protected static string $resource = DocumentResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(DocumentService::class)->update($record, $data, auth()->user());
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DocumentActions::uploadVersion(),
            DocumentActions::archive(),
        ];
    }
}
