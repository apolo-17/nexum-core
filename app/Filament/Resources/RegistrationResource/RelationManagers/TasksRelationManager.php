<?php

namespace App\Filament\Resources\RegistrationResource\RelationManagers;

use App\Enums\TaskPriorityEnum;
use App\Enums\TaskTypeEnum;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

/**
 * Manages tasks associated with a registration expedient.
 *
 * Supports both manual tasks created by the notary team and automated tasks
 * completed by system processes. Includes a quick-complete action.
 */
class TasksRelationManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    protected static ?string $title = 'Tareas';

    /**
     * Allow mutations (create, edit, delete) even when rendered inside a ViewRecord page.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    /**
     * Define the form schema for creating and editing tasks.
     */
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->label('Título')->required(),
            Textarea::make('description')->label('Descripción')->nullable(),
            Select::make('priority')
                ->label('Prioridad')
                ->options(
                    collect(TaskPriorityEnum::cases())
                        ->mapWithKeys(fn ($case) => [$case->value => $case->label()])
                )
                ->default(TaskPriorityEnum::MEDIUM->value)
                ->required(),
            Select::make('assigned_to')
                ->label('Asignada a')
                ->options(User::pluck('name', 'id'))
                ->searchable()
                ->nullable(),
            DatePicker::make('due_date')->label('Fecha límite')->nullable(),
        ]);
    }

    /**
     * Define the table columns and actions for the tasks list.
     */
    public function table(Table $table): Table
    {
        return $table
            ->columns([
                IconColumn::make('completed_at')
                    ->label('')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-clock'),
                TextColumn::make('title')->label('Tarea'),
                BadgeColumn::make('priority')
                    ->label('Prioridad')
                    ->formatStateUsing(fn (TaskPriorityEnum $state) => $state->label())
                    ->colors([
                        'gray' => TaskPriorityEnum::LOW->value,
                        'warning' => TaskPriorityEnum::MEDIUM->value,
                        'danger' => TaskPriorityEnum::HIGH->value,
                    ]),
                BadgeColumn::make('type')
                    ->label('Tipo')
                    ->formatStateUsing(fn (TaskTypeEnum $state) => $state->label())
                    ->colors([
                        'gray' => TaskTypeEnum::MANUAL->value,
                        'info' => TaskTypeEnum::AUTOMATED->value,
                    ]),
                TextColumn::make('assignee.name')->label('Asignada a')->placeholder('—'),
                TextColumn::make('due_date')->label('Límite')->date('d/m/Y')->placeholder('—'),
            ])
            ->actions([
                Action::make('complete')
                    ->label('Completar')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn ($record) => $record->completed_at === null)
                    ->action(function ($record) {
                        $record->update([
                            'completed_at' => Carbon::now(),
                            'completed_by' => auth()->id(),
                        ]);
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Nueva tarea')
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['created_by'] = auth()->id();
                        $data['type'] = TaskTypeEnum::MANUAL->value;

                        return $data;
                    }),
            ]);
    }
}
