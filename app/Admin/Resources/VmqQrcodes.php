<?php

namespace App\Admin\Resources;

use App\Admin\Resources\VmqQrcodes\Pages;
use App\Models\VmqQrcode;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * V免签 固定金额收款码管理
 *
 * 当命中 type + price 的启用收款码时，支付页会直接使用这里配置的 pay_url 生成二维码；
 * 否则走系统自动金额错位（is_auto=1）。
 */
class VmqQrcodes extends Resource
{
    protected static ?string $model = VmqQrcode::class;

    protected static ?string $navigationIcon = 'heroicon-o-qr-code';

    protected static ?string $navigationGroup = '支付配置';

    protected static ?int $navigationSort = 10;

    public static function getNavigationLabel(): string
    {
        return 'V免签 收款码';
    }

    public static function getModelLabel(): string
    {
        return 'V免签 收款码';
    }

    public static function getPluralModelLabel(): string
    {
        return 'V免签 收款码';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('收款码信息')
                ->description('给指定金额+支付类型预置专用收款二维码。当用户付款金额命中此组合时，将直接使用此处的 pay_url 生成二维码，而不是走金额错位。')
                ->schema([
                    Forms\Components\Grid::make(2)->schema([
                        Forms\Components\Select::make('type')
                            ->label('支付类型')
                            ->options(VmqQrcode::getTypeMap())
                            ->required(),

                        Forms\Components\TextInput::make('price')
                            ->label('对应金额（元）')
                            ->numeric()
                            ->step(0.01)
                            ->minValue(0.01)
                            ->required()
                            ->helperText('同一「类型+金额」只能配置一条'),
                    ]),

                    Forms\Components\Textarea::make('pay_url')
                        ->label('扫码支付链接 / 收款二维码内容')
                        ->helperText('粘贴微信/支付宝个人收款码的二维码解析内容（例如 wxp://f2f0xxxx 或 https://qr.alipay.com/xxxx）')
                        ->required()
                        ->rows(3),

                    Forms\Components\TextInput::make('image_path')
                        ->label('收款码图片路径（可选）')
                        ->helperText('留空即可，系统会根据 pay_url 动态生成二维码图片'),

                    Forms\Components\TextInput::make('remark')
                        ->label('备注')
                        ->maxLength(255),

                    Forms\Components\Toggle::make('enable')
                        ->label('启用')
                        ->default(true)
                        ->inline(false),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('ID')->sortable(),

                Tables\Columns\TextColumn::make('type')
                    ->label('类型')
                    ->formatStateUsing(fn ($state) => VmqQrcode::getTypeMap()[$state] ?? $state)
                    ->badge()
                    ->color(fn ($state) => $state == VmqQrcode::TYPE_WECHAT ? 'success' : 'info'),

                Tables\Columns\TextColumn::make('price')
                    ->label('金额')
                    ->money('CNY')
                    ->sortable(),

                Tables\Columns\TextColumn::make('pay_url')
                    ->label('二维码内容')
                    ->limit(40),

                Tables\Columns\ToggleColumn::make('enable')->label('启用'),

                Tables\Columns\TextColumn::make('remark')->label('备注')->limit(20),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('更新时间')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('类型')
                    ->options(VmqQrcode::getTypeMap()),

                Tables\Filters\TernaryFilter::make('enable')->label('启用'),
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
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListVmqQrcodes::route('/'),
            'create' => Pages\CreateVmqQrcode::route('/create'),
            'edit'   => Pages\EditVmqQrcode::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth('admin')->user()?->can('manage_payments') || auth('admin')->user()?->hasRole('super-admin') || false;
    }

    public static function canCreate(): bool { return static::canViewAny(); }
    public static function canEdit($record): bool { return static::canViewAny(); }
    public static function canDelete($record): bool { return static::canViewAny(); }
}
