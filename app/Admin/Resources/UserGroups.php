<?php

namespace App\Admin\Resources;

use App\Models\UserGroup;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\ColorColumn;

class UserGroups extends Resource
{
    protected static ?string $model = UserGroup::class;
    protected static ?string $navigationIcon = 'heroicon-o-user-group';
    protected static ?string $navigationLabel = '用户分组';
    protected static ?string $modelLabel = '用户分组';
    protected static ?string $pluralModelLabel = '用户分组';
    protected static ?string $navigationGroup = '用户管理';
    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('name')
                    ->label('分组名称')
                    ->required()
                    ->maxLength(100)
                    ->placeholder('如：批发客户、VIP 客户、推广客户'),

                Textarea::make('description')
                    ->label('分组说明')
                    ->rows(2)
                    ->maxLength(255)
                    ->columnSpanFull()
                    ->helperText('简要描述该分组用途，仅后台展示'),

                ColorPicker::make('color')
                    ->label('分组颜色')
                    ->default('#6366f1')
                    ->helperText('用于后台用户列表的徽标颜色'),

                TextInput::make('sort')
                    ->label('排序')
                    ->numeric()
                    ->default(0)
                    ->helperText('数字越小越靠前'),

                Toggle::make('status')
                    ->label('启用')
                    ->default(true)
                    ->helperText('禁用后用户将无法被分配到此分组'),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),

                TextColumn::make('name')
                    ->label('分组名称')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),

                ColorColumn::make('color')->label('颜色'),

                TextColumn::make('description')
                    ->label('说明')
                    ->limit(40)
                    ->toggleable(),

                TextColumn::make('users_count')
                    ->label('用户数')
                    ->counts('users')
                    ->sortable()
                    ->badge()
                    ->color('primary'),

                BadgeColumn::make('status')
                    ->label('状态')
                    ->getStateUsing(fn ($record) => $record->status_text)
                    ->colors([
                        'success' => '启用',
                        'secondary' => '禁用',
                    ]),

                TextColumn::make('sort')->label('排序')->sortable(),

                TextColumn::make('created_at')
                    ->label('创建时间')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('status')
                    ->label('状态')
                    ->boolean()
                    ->trueLabel('启用')
                    ->falseLabel('禁用')
                    ->native(false),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('sort')
            ->reorderable('sort');
    }

    public static function getPages(): array
    {
        return [
            'index' => \App\Admin\Resources\UserGroups\Pages\ListUserGroups::route('/'),
            'create' => \App\Admin\Resources\UserGroups\Pages\CreateUserGroup::route('/create'),
            'edit' => \App\Admin\Resources\UserGroups\Pages\EditUserGroup::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth('admin')->user()?->can('manage_user_groups')
            || auth('admin')->user()?->hasRole('super-admin')
            || false;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit($record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete($record): bool
    {
        return static::canViewAny();
    }
}
