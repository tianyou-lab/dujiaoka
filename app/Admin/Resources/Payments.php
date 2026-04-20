<?php

namespace App\Admin\Resources;

use App\Admin\Resources\Payments\Pages;
use App\Models\Pay;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class Payments extends Resource
{
    protected static ?string $model = Pay::class;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationGroup = '支付配置';

    protected static ?int $navigationSort = 1;

    public static function getNavigationLabel(): string
    {
        return __('pay.labels.pay');
    }

    public static function getModelLabel(): string
    {
        return __('pay.labels.pay');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('pay.labels.section_basic'))
                    ->description(__('pay.helps.section_basic'))
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('pay_name')
                            ->label(__('pay.fields.pay_name'))
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('pay_check')
                            ->label(__('pay.fields.pay_check'))
                            ->required()
                            ->maxLength(255),

                        Forms\Components\Select::make('pay_method')
                            ->label(__('pay.fields.pay_method'))
                            ->options(Pay::getMethodMap())
                            ->required(),

                        Forms\Components\Select::make('pay_client')
                            ->label(__('pay.fields.pay_client'))
                            ->options(Pay::getClientMap())
                            ->required(),

                        Forms\Components\TextInput::make('pay_handleroute')
                            ->label(__('pay.fields.pay_handleroute'))
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('pay_fee')
                            ->label(__('pay.fields.pay_fee'))
                            ->helperText(__('pay.helps.pay_fee'))
                            ->numeric()
                            ->step(0.01)
                            ->default(0),

                        Forms\Components\Toggle::make('china_only')
                            ->label(__('pay.fields.china_only'))
                            ->default(false)
                            ->inline(false),

                        Forms\Components\Toggle::make('enable')
                            ->label(__('pay.fields.enable'))
                            ->default(true)
                            ->inline(false),
                    ]),

                Forms\Components\Section::make(__('pay.labels.section_credentials'))
                    ->description(__('pay.helps.section_credentials'))
                    ->schema([
                        Forms\Components\TextInput::make('merchant_id')
                            ->label(__('pay.fields.merchant_id'))
                            ->maxLength(255),

                        Forms\Components\Textarea::make('merchant_key')
                            ->label(__('pay.fields.merchant_key'))
                            ->helperText(__('pay.helps.merchant_key'))
                            ->rows(3),

                        Forms\Components\Textarea::make('merchant_pem')
                            ->label(__('pay.fields.merchant_pem'))
                            ->helperText(__('pay.helps.merchant_pem'))
                            ->rows(5),
                    ]),

                Forms\Components\Section::make(__('pay.labels.alipay_cert_section'))
                    ->description(__('pay.helps.alipay_cert_section'))
                    ->collapsible()
                    ->collapsed(fn ($record) => !$record || $record->pay_handleroute !== 'alipay')
                    ->schema([
                        Forms\Components\Textarea::make('app_public_cert')
                            ->label(__('pay.fields.app_public_cert'))
                            ->helperText(__('pay.helps.app_public_cert'))
                            ->placeholder("-----BEGIN CERTIFICATE-----\n...\n-----END CERTIFICATE-----")
                            ->rows(6),

                        Forms\Components\Textarea::make('alipay_public_cert')
                            ->label(__('pay.fields.alipay_public_cert'))
                            ->helperText(__('pay.helps.alipay_public_cert'))
                            ->placeholder("-----BEGIN CERTIFICATE-----\n...\n-----END CERTIFICATE-----")
                            ->rows(6),

                        Forms\Components\Textarea::make('alipay_root_cert')
                            ->label(__('pay.fields.alipay_root_cert'))
                            ->helperText(__('pay.helps.alipay_root_cert'))
                            ->placeholder("-----BEGIN CERTIFICATE-----\n...\n-----END CERTIFICATE-----")
                            ->rows(6),
                    ]),

                Forms\Components\Section::make(__('pay.labels.vmq_section'))
                    ->description('嵌入式 V免签：本站即监控端，无需额外部署 PHP 监控项目，一台服务器 + 一部安卓监控 App 即可')
                    ->collapsible()
                    ->collapsed(fn ($record) => !$record || $record->pay_handleroute !== 'vmq')
                    ->schema([
                        Forms\Components\Placeholder::make('vmq_guide')
                            ->label('嵌入式 V免签 配置步骤')
                            ->content(new \Illuminate\Support\HtmlString(
                                '<p style="margin:0 0 10px 0;color:#0f766e;"><strong>架构说明</strong>：本站已内置 V免签 监控端，<strong>不再需要</strong>独立的 vmqphp/VMQ 监控 URL。下方商户字段保持默认即可，真正的通讯密钥和收款码请到 <code>支付配置 → V免签 全局设置</code> 中维护。</p>
                                <ol style="margin:0;padding-left:18px;line-height:1.9;">
                                    <li><strong>支付处理模块 pay_handleroute</strong> 填 <code>vmq</code></li>
                                    <li><strong>支付标识 pay_check</strong>：微信扫码填 <code>vwx</code>，支付宝扫码填 <code>vzfb</code></li>
                                    <li><strong>支付方式</strong> 选「扫码」</li>
                                    <li><strong>商户 ID / 商户密钥 / 商户 KEY</strong>：三项留空即可（嵌入式模式不使用）</li>
                                    <li>到 <code>支付配置 → V免签 全局设置</code> 配置<strong>通讯密钥 + 微信收款码 + 支付宝收款码</strong>三项，并在安卓 App 里填入本站 URL <code>https://xxxxxxx.com</code>（替换成你的真实发卡站域名）与同一串密钥</li>
                                </ol>
                                <p style="margin:10px 0 0 0;color:#b45309;"><strong>安全提示</strong>：通讯密钥泄露后任何人都可伪造到账，请妥善保管；本站自动对 <code>/appHeart</code>、<code>/appPush</code>、<code>/createOrder</code> 等接口免 CSRF，但仍然通过 MD5 签名校验。</p>'
                            )),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                Tables\Columns\TextColumn::make('pay_name')
                    ->label(__('pay.fields.pay_name'))
                    ->searchable(),

                Tables\Columns\TextColumn::make('pay_check')
                    ->label(__('pay.fields.pay_check'))
                    ->searchable(),

                Tables\Columns\TextColumn::make('pay_fee')
                    ->label(__('pay.fields.pay_fee'))
                    ->money('CNY'),

                Tables\Columns\TextColumn::make('pay_method')
                    ->label(__('pay.fields.pay_method'))
                    ->formatStateUsing(fn (string $state): string => Pay::getMethodMap()[$state] ?? $state),

                Tables\Columns\TextColumn::make('merchant_id')
                    ->label(__('pay.fields.merchant_id'))
                    ->limit(20),

                Tables\Columns\TextColumn::make('pay_client')
                    ->label(__('pay.fields.pay_client'))
                    ->formatStateUsing(fn (string $state): string => Pay::getClientMap()[$state] ?? $state),

                Tables\Columns\TextColumn::make('pay_handleroute')
                    ->label(__('pay.fields.pay_handleroute')),

                Tables\Columns\ToggleColumn::make('china_only')
                    ->label(__('pay.fields.china_only')),

                Tables\Columns\ToggleColumn::make('enable')
                    ->label(__('pay.fields.enable')),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('pay_method')
                    ->label(__('pay.fields.pay_method'))
                    ->options(Pay::getMethodMap()),

                Tables\Filters\SelectFilter::make('pay_client')
                    ->label(__('pay.fields.pay_client'))
                    ->options(Pay::getClientMap()),

                Tables\Filters\TernaryFilter::make('china_only')
                    ->label(__('pay.fields.china_only')),

                Tables\Filters\TernaryFilter::make('enable')
                    ->label(__('pay.fields.enable')),

                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
                Tables\Actions\RestoreAction::make(),
                Tables\Actions\ForceDeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPays::route('/'),
            'create' => Pages\CreatePay::route('/create'),
            'edit' => Pages\EditPay::route('/{record}/edit'),
        ];
    }
    public static function canViewAny(): bool
    {
        return auth('admin')->user()?->can('manage_payments') || auth('admin')->user()?->hasRole('super-admin') || false;
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
