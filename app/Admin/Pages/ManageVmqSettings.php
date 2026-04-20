<?php

namespace App\Admin\Pages;

use App\Models\VmqSetting;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Str;

class ManageVmqSettings extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-device-phone-mobile';

    protected static string $view = 'filament.pages.manage-vmq-settings';

    protected static ?string $navigationGroup = '支付配置';

    protected static ?int $navigationSort = 11;

    public static function getNavigationLabel(): string
    {
        return 'V免签 全局设置';
    }

    public function getTitle(): string
    {
        return 'V免签 全局设置（嵌入式）';
    }

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'enable'         => (string) VmqSetting::get('enable', '1') === '1',
            'key'            => (string) VmqSetting::get('key', ''),
            'close_minutes'  => (int)    VmqSetting::get('close_minutes', '10'),
            'pay_qf'         => (int)    VmqSetting::get('pay_qf', '1'),
            'heart_timeout'  => (int)    VmqSetting::get('heart_timeout', '60'),
            'wx_pay_url'     => (string) VmqSetting::get('wx_pay_url', ''),
            'zfb_pay_url'    => (string) VmqSetting::get('zfb_pay_url', ''),
            'last_heart'     => (int)    VmqSetting::get('last_heart', '0'),
            'last_pay'       => (int)    VmqSetting::get('last_pay', '0'),
            'jk_state'       => (string) VmqSetting::get('jk_state', '0'),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('状态监控')
                    ->description('嵌入式 V免签 当前运行状态（只读）')
                    ->schema([
                        Forms\Components\Placeholder::make('status_panel')
                            ->label('')
                            ->content(function () {
                                $jk = (string) VmqSetting::get('jk_state', '0');
                                $lh = (int) VmqSetting::get('last_heart', '0');
                                $lp = (int) VmqSetting::get('last_pay', '0');
                                $heartText = $lh ? date('Y-m-d H:i:s', $lh) : '从未上报';
                                $payText   = $lp ? date('Y-m-d H:i:s', $lp) : '从未到账';
                                $statusHtml = $jk === '1'
                                    ? '<span style="color:#059669;font-weight:600;">● 在线</span>'
                                    : '<span style="color:#dc2626;font-weight:600;">● 离线</span>';
                                return new \Illuminate\Support\HtmlString(
                                    '<div style="font-size:14px;line-height:2;">'
                                    . '<div>监控 App：' . $statusHtml . '</div>'
                                    . '<div>最后心跳：<code>' . $heartText . '</code></div>'
                                    . '<div>最后到账：<code>' . $payText . '</code></div>'
                                    . '</div>'
                                );
                            }),
                    ]),

                Forms\Components\Section::make('基础配置')
                    ->description('V免签 核心开关与密钥配置')
                    ->schema([
                        Forms\Components\Toggle::make('enable')
                            ->label('启用嵌入式 V免签')
                            ->default(true)
                            ->inline(false)
                            ->helperText('关闭后用户无法通过 V免签 发起支付'),

                        Forms\Components\TextInput::make('key')
                            ->label('通讯密钥')
                            ->required()
                            ->helperText('32 位随机串，安卓 V免签 App 里的「通讯密钥」必须与此完全一致。留空时本功能不可用。')
                            ->suffixAction(
                                Forms\Components\Actions\Action::make('genKey')
                                    ->icon('heroicon-o-sparkles')
                                    ->label('随机生成')
                                    ->action(function (Forms\Set $set) {
                                        $set('key', Str::random(32));
                                    })
                            ),
                    ]),

                Forms\Components\Section::make('全局收款码')
                    ->description('与 V免签 原版一致：只需配置 1 个微信收款码 + 1 个支付宝收款码。订单通过「金额错位」区分，不需要按金额建多个码。')
                    ->schema([
                        Forms\Components\Textarea::make('wx_pay_url')
                            ->label('微信收款码内容')
                            ->rows(2)
                            ->helperText(new \Illuminate\Support\HtmlString(
                                '粘贴微信个人收款码的二维码解析结果，例如 <code>wxp://f2f0xxxx...</code>。<br>'
                                . '在线解析工具：<a href="https://www.sojson.com/qr/deqr.html" target="_blank" rel="noopener">https://www.sojson.com/qr/deqr.html</a>。留空则回退到金额+订单号文本码。'
                            )),

                        Forms\Components\Textarea::make('zfb_pay_url')
                            ->label('支付宝收款码内容')
                            ->rows(2)
                            ->helperText(new \Illuminate\Support\HtmlString(
                                '粘贴支付宝个人收款码的二维码解析结果，例如 <code>https://qr.alipay.com/xxxx</code>。<br>'
                                . '在线解析工具：<a href="https://www.sojson.com/qr/deqr.html" target="_blank" rel="noopener">https://www.sojson.com/qr/deqr.html</a>。留空则回退到金额+订单号文本码。'
                            )),
                    ]),

                Forms\Components\Section::make('订单与金额策略')
                    ->schema([
                        Forms\Components\Grid::make(2)->schema([
                            Forms\Components\TextInput::make('close_minutes')
                                ->label('订单超时自动关闭（分钟）')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(120)
                                ->default(10)
                                ->required(),

                            Forms\Components\Select::make('pay_qf')
                                ->label('金额错位方向')
                                ->options([
                                    1 => '递增（+1 分，推荐）',
                                    2 => '递减（−1 分）',
                                ])
                                ->default(1)
                                ->required()
                                ->helperText('同金额并发时向哪个方向错位，避免两单撞车'),
                        ]),

                        Forms\Components\TextInput::make('heart_timeout')
                            ->label('心跳超时（秒）')
                            ->numeric()
                            ->minValue(15)
                            ->maxValue(600)
                            ->default(60)
                            ->required()
                            ->helperText('超过此秒数未收到 App 心跳，监控端自动置为离线'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        VmqSetting::put('enable',        $data['enable'] ? '1' : '0');
        VmqSetting::put('key',           (string) ($data['key'] ?? ''));
        VmqSetting::put('close_minutes', (string) ($data['close_minutes'] ?? 10));
        VmqSetting::put('pay_qf',        (string) ($data['pay_qf'] ?? 1));
        VmqSetting::put('heart_timeout', (string) ($data['heart_timeout'] ?? 60));
        VmqSetting::put('wx_pay_url',    (string) ($data['wx_pay_url'] ?? ''));
        VmqSetting::put('zfb_pay_url',   (string) ($data['zfb_pay_url'] ?? ''));

        Notification::make()
            ->title('V免签 全局设置已保存')
            ->success()
            ->send();
    }

    protected function getFormActions(): array
    {
        return [
            Forms\Components\Actions\Action::make('save')
                ->label('保存设置')
                ->action('save')
                ->icon('heroicon-o-check')
                ->color('primary'),
        ];
    }
}
