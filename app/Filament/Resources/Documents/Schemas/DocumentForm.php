<?php

namespace App\Filament\Resources\Documents\Schemas;

use App\Enums\DocumentCategory;
use App\Models\Document;
use App\Models\Job;
use App\Models\WorkOrder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class DocumentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Document')
                    ->columns(2)
                    ->schema([
                        TextInput::make('title')
                            ->label('Titre')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        TextInput::make('reference')
                            ->label('Référence')
                            ->maxLength(255),
                        Select::make('category')
                            ->label('Catégorie')
                            ->options(DocumentCategory::options())
                            ->default(DocumentCategory::Other->value)
                            ->required()
                            ->native(false),
                        Textarea::make('description')
                            ->label('Description')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
                Section::make('Rattachement')
                    ->columns(2)
                    ->description('Un document doit être rattaché à une affaire, à un OF, ou aux deux. L’association est figée dès qu’une version existe.')
                    ->schema([
                        Select::make('job_id')
                            ->label('Affaire')
                            ->relationship('job', 'code')
                            ->getOptionLabelFromRecordUsing(
                                fn (Job $record): string => "{$record->code} — {$record->client_name}"
                            )
                            ->searchable()
                            ->disabled(fn (?Document $record): bool => $record !== null
                                && ($record->versions()->exists() || $record->snapshots()->exists()))
                            ->helperText('Laisser vide si le document est rattaché uniquement à un OF.'),
                        Select::make('work_order_id')
                            ->label('Ordre de fabrication')
                            ->relationship(
                                name: 'workOrder',
                                titleAttribute: 'number',
                                modifyQueryUsing: fn (Builder $query, Get $get): Builder => $query
                                    ->when($get('job_id'), fn (Builder $query, $jobId): Builder => $query->where('job_id', $jobId)),
                            )
                            ->getOptionLabelFromRecordUsing(
                                fn (WorkOrder $record): string => "{$record->number} — {$record->title}"
                            )
                            ->searchable()
                            ->disabled(fn (?Document $record): bool => $record !== null
                                && ($record->versions()->exists() || $record->snapshots()->exists())),
                    ]),
            ]);
    }
}
