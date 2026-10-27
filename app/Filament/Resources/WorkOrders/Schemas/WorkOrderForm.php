<?php

namespace App\Filament\Resources\WorkOrders\Schemas;

use App\Enums\JobStatus;
use App\Enums\WorkOrderPriority;
use App\Models\Job;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class WorkOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Ordre de fabrication')
                    ->columns(2)
                    ->schema([
                        TextInput::make('number')
                            ->label("Numéro d'OF")
                            ->disabled()
                            ->dehydrated(false)
                            ->placeholder('Généré automatiquement à la création'),
                        Select::make('job_id')
                            ->label('Affaire')
                            ->relationship('job', 'code')
                            ->getOptionLabelFromRecordUsing(
                                fn (Job $record): string => "{$record->code} — {$record->client_name} — {$record->name}"
                            )
                            ->searchable()
                            ->required()
                            ->disabledOn('edit')
                            ->disableOptionWhen(
                                fn (string $value): bool => Job::query()
                                    ->whereKey($value)
                                    ->whereIn('status', [JobStatus::Closed, JobStatus::OnHold])
                                    ->exists()
                            )
                            ->helperText('Seules les affaires ouvertes acceptent de nouveaux OF.'),
                        TextInput::make('title')
                            ->label('Désignation')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Textarea::make('description')
                            ->label('Description')
                            ->rows(3)
                            ->columnSpanFull(),
                        Select::make('priority')
                            ->label('Priorité')
                            ->options(WorkOrderPriority::options())
                            ->default(WorkOrderPriority::Normal->value)
                            ->required()
                            ->native(false),
                    ]),
                Section::make('Planification')
                    ->columns(2)
                    ->schema([
                        DatePicker::make('planned_start_at')
                            ->label('Début prévisionnel')
                            ->displayFormat('d/m/Y')
                            ->seconds(false),
                        DatePicker::make('planned_end_at')
                            ->label('Fin prévisionnelle')
                            ->displayFormat('d/m/Y')
                            ->seconds(false)
                            ->afterOrEqual('planned_start_at'),
                    ]),
                Section::make('Notes')
                    ->schema([
                        Textarea::make('notes')
                            ->label('Notes internes')
                            ->rows(4),
                    ]),
            ]);
    }
}
