<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Spatie\Permission\Models\Role;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        $roles = Role::orderBy('name')->pluck('name', 'name')->all();

        return $schema
            ->components([
                Section::make('Account')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->required()->maxLength(255),
                        TextInput::make('email')->email()->required()->maxLength(255)->unique(ignoreRecord: true),
                        TextInput::make('phone')->tel()->maxLength(32)->nullable(),
                        Toggle::make('is_active')->label('Active (can log in)')->default(true),
                    ]),

                Section::make('Platform roles')
                    ->description('Roles grant access to /admin. No role = exporter or no access.')
                    ->schema([
                        CheckboxList::make('roles')
                            ->relationship('roles', 'name')
                            ->options($roles)
                            ->columns(2)
                            ->bulkToggleable(),
                    ]),
            ]);
    }
}
