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
 * V免签 精确金额收款码（高级，可选）
 *
 * 与 V免签 原版一致：绝大多数站长只需要在「V免签 全局设置」里填 1 个微信码 + 1 个支付宝码即可，
 * 系统会通过金额错位区分每一单，不必按金额预置码。
 *
 * 本页只服务于高级场景：
 *   - 某些商户需要给「固定金额」投放专用收款二维码（例如多店铺分账、内部 Bug 排查）
 *   - 录入后会覆盖全局收款码，仅对命中的「类型+金额」生效
 * 如果你不确定是否需要使用这里，请直接去「V免签 全局设置」配置两个收款码即可。
 */
class VmqQrcodes extends Resource
{
    protected static ?string $model = VmqQrcode::class;

    protected static ?string $navigationIcon = 'heroicon-o-qr-code';

    protected static ?string $navigationGroup = '支付配置';

    protected static ?int $navigationSort = 12;

    public static function getNavigationLabel(): string
    {
        return 'V免签 精确金额收款码（高级）';
    }

    public static function getModelLabel(): string
    {
        return 'V免签 精确金额收款码';
    }

    public static function getPluralModelLabel(): string
    {
        return 'V免签 精确金额收款码';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('精确金额收款码')
                ->description(new \Illuminate\Support\HtmlString(
                    '<p style="margin:0;color:#b45309;"><strong>大多数用户不需要使用这里</strong>——请直接到「V免签 全局设置」填 1 个微信码 + 1 个支付宝码。</p>'
                    . '<p style="margin:8px 0 0 0;">仅当你需要给某个<strong>固定金额</strong>投放<strong>独立的收款二维码</strong>时才在这里新增；命中后会覆盖全局收款码，只对此「类型+金额」组合生效。</p>'
                ))
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
                            ->helperText('同一「类型+金额」只能配置一条；如果你想对所有金额生效，请到「V免签 全局设置」。'),
                    ]),

                    Forms\Components\Textarea::make('pay_url')
                        ->label('扫码支付链接 / 收款二维码内容')
                        ->helperText(new \Illuminate\Support\HtmlString(
                            '粘贴微信/支付宝个人收款码的二维码解析结果（例如 <code>wxp://f2f0xxxx</code> 或 <code>https://qr.alipay.com/xxxx</code>）。<br>'
                            . '在线解析工具：<a href="https://www.sojson.com/qr/deqr.html" target="_blank" rel="noopener">https://www.sojson.com/qr/deqr.html</a>'
                        ))
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
