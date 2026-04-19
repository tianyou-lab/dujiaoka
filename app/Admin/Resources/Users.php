<?php

namespace App\Admin\Resources;

use App\Models\User;
use App\Models\UserLevel;
use App\Models\UserGroup;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\Section;
use Filament\Tables\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Actions\BulkAction;
use Illuminate\Database\Eloquent\Collection;
use Filament\Notifications\Notification;

class Users extends Resource
{
    protected static ?string $model = User::class;
    protected static ?string $navigationIcon = 'heroicon-o-users';
    protected static ?string $navigationLabel = '用户';
    protected static ?string $modelLabel = '用户';
    protected static ?string $pluralModelLabel = '用户';
    protected static ?string $navigationGroup = '用户管理';
    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('基本信息')
                    ->schema([
                        TextInput::make('email')
                            ->label('邮箱')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true),
                        
                        TextInput::make('nickname')
                            ->label('昵称')
                            ->maxLength(50),
                        
                        TextInput::make('phone')
                            ->label('手机号码')
                            ->tel()
                            ->maxLength(20),
                        
                        Select::make('level_id')
                            ->label('用户等级')
                            ->options(UserLevel::getActiveLevels()->pluck('name', 'id'))
                            ->required()
                            ->helperText('按累计消费自动升级'),

                        Select::make('group_id')
                            ->label('用户分组')
                            ->options(UserGroup::getActiveGroups()->pluck('name', 'id'))
                            ->placeholder('未分组')
                            ->helperText('手动分配，享受商品分组专属价'),

                        Select::make('status')
                            ->label('状态')
                            ->options(User::getStatusMap())
                            ->required(),
                    ])->columns(2),

                Section::make('账户信息')
                    ->schema([
                        TextInput::make('balance')
                            ->label('账户余额')
                            ->numeric()
                            ->step(0.01)
                            ->prefix('¥'),
                        
                        TextInput::make('total_spent')
                            ->label('累计消费')
                            ->numeric()
                            ->step(0.01)
                            ->prefix('¥'),
                    ])->columns(2),

                Section::make('密码设置')
                    ->schema([
                        TextInput::make('password')
                            ->label('密码')
                            ->password()
                            ->dehydrateStateUsing(fn ($state) => filled($state) ? bcrypt($state) : null)
                            ->dehydrated(fn ($state) => filled($state))
                            ->required(fn (string $context): bool => $context === 'create')
                            ->helperText('留空则不修改密码'),
                    ])->hiddenOn('view'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                
                TextColumn::make('email')
                    ->label('邮箱')
                    ->searchable()
                    ->sortable(),
                
                TextColumn::make('nickname')
                    ->label('昵称')
                    ->searchable()
                    ->default('未设置'),
                
                BadgeColumn::make('level.name')
                    ->label('等级')
                    ->colors([
                        'secondary' => '普通用户',
                        'warning' => 'VIP用户',
                        'success' => '钻石用户',
                    ]),

                TextColumn::make('group.name')
                    ->label('分组')
                    ->badge()
                    ->default('未分组')
                    ->formatStateUsing(fn ($state) => $state ?: '未分组')
                    ->color(fn ($record) => $record?->group?->color ? 'gray' : 'gray')
                    ->extraAttributes(function ($record) {
                        $color = $record?->group?->color;
                        if (! $color) {
                            return [];
                        }
                        return [
                            'style' => 'background-color: ' . $color . '20; color: ' . $color . '; border: 1px solid ' . $color . '40;',
                        ];
                    }),
                
                TextColumn::make('balance')
                    ->label('余额')
                    ->money('CNY')
                    ->sortable(),
                
                TextColumn::make('total_spent')
                    ->label('累计消费')
                    ->money('CNY')
                    ->sortable(),
                
                TextColumn::make('orders_count')
                    ->label('订单数量')
                    ->counts('orders')
                    ->sortable(),
                
                BadgeColumn::make('status')
                    ->label('状态')
                    ->getStateUsing(fn ($record) => $record->status_text)
                    ->colors([
                        'success' => '正常',
                        'danger' => '禁用',
                    ]),
                
                TextColumn::make('last_login_at')
                    ->label('最后登录')
                    ->formatStateUsing(fn ($state) => $state ? $state->format('Y-m-d H:i:s') : '从未登录')
                    ->sortable(),
                
                TextColumn::make('created_at')
                    ->label('注册时间')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('level_id')
                    ->label('用户等级')
                    ->options(UserLevel::getActiveLevels()->pluck('name', 'id')),

                SelectFilter::make('group_id')
                    ->label('用户分组')
                    ->options(UserGroup::getActiveGroups()->pluck('name', 'id'))
                    ->placeholder('全部'),

                SelectFilter::make('status')
                    ->label('状态')
                    ->options(User::getStatusMap()),
            ])
            ->actions([
                Action::make('adjustBalance')
                    ->label('调整余额')
                    ->icon('heroicon-o-banknotes')
                    ->form([
                        Select::make('type')
                            ->label('操作类型')
                            ->options([
                                'add' => '增加余额',
                                'subtract' => '扣除余额',
                                'set' => '设置余额',
                            ])
                            ->required(),
                        
                        TextInput::make('amount')
                            ->label('金额')
                            ->numeric()
                            ->step(0.01)
                            ->required()
                            ->prefix('¥'),
                        
                        TextInput::make('description')
                            ->label('操作说明')
                            ->required()
                            ->default('管理员调整'),
                    ])
                    ->action(function (User $record, array $data) {
                        \Illuminate\Support\Facades\DB::transaction(function () use ($record, $data) {
                            $user = User::lockForUpdate()->find($record->id);
                            $balanceBefore = $user->balance;

                            switch ($data['type']) {
                                case 'add':
                                    $balanceAfter = $balanceBefore + $data['amount'];
                                    $amount = $data['amount'];
                                    break;
                                case 'subtract':
                                    $balanceAfter = max(0, $balanceBefore - $data['amount']);
                                    $amount = -$data['amount'];
                                    break;
                                case 'set':
                                    $balanceAfter = $data['amount'];
                                    $amount = $balanceAfter - $balanceBefore;
                                    break;
                            }

                            $user->balance = $balanceAfter;
                            $user->save();

                            $user->balanceRecords()->create([
                                'type' => 'admin',
                                'amount' => $amount,
                                'balance_before' => $balanceBefore,
                                'balance_after' => $balanceAfter,
                                'description' => $data['description'],
                                'admin_id' => auth()->id(),
                            ]);
                        });

                        Notification::make()
                            ->title('余额调整成功')
                            ->success()
                            ->send();
                    }),
                
                Tables\Actions\EditAction::make(),
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                BulkAction::make('enable')
                    ->label('批量启用')
                    ->icon('heroicon-o-check-circle')
                    ->action(function (Collection $records) {
                        $records->each->update(['status' => User::STATUS_ACTIVE]);

                        Notification::make()
                            ->title('批量启用成功')
                            ->success()
                            ->send();
                    }),

                BulkAction::make('disable')
                    ->label('批量禁用')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->action(function (Collection $records) {
                        $records->each->update(['status' => User::STATUS_DISABLED]);

                        Notification::make()
                            ->title('批量禁用成功')
                            ->warning()
                            ->send();
                    }),

                BulkAction::make('assignGroup')
                    ->label('批量分配分组')
                    ->icon('heroicon-o-user-group')
                    ->color('primary')
                    ->form([
                        Select::make('group_id')
                            ->label('目标分组')
                            ->options(
                                UserGroup::getActiveGroups()
                                    ->pluck('name', 'id')
                                    ->toArray()
                            )
                            ->placeholder('未分组（留空则清除分组）'),
                    ])
                    ->action(function (Collection $records, array $data) {
                        $groupId = empty($data['group_id']) ? null : (int) $data['group_id'];
                        $records->each(fn ($u) => $u->update(['group_id' => $groupId]));
                        $name = $groupId
                            ? (UserGroup::find($groupId)?->name ?? '未知')
                            : '未分组';

                        Notification::make()
                            ->title('已为 ' . $records->count() . ' 个用户分配分组：' . $name)
                            ->success()
                            ->send();
                    }),

                Tables\Actions\DeleteBulkAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => \App\Admin\Resources\Users\Pages\ListUsers::route('/'),
            'create' => \App\Admin\Resources\Users\Pages\CreateUser::route('/create'),
            'edit' => \App\Admin\Resources\Users\Pages\EditUser::route('/{record}/edit'),
            'view' => \App\Admin\Resources\Users\Pages\ViewUser::route('/{record}'),
        ];
    }


    public static function getGlobalSearchEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['level', 'group']);
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->with(['level', 'group']);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['email', 'nickname'];
    }
    public static function canViewAny(): bool
    {
        return auth('admin')->user()?->can('manage_users') || auth('admin')->user()?->hasRole('super-admin') || false;
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
