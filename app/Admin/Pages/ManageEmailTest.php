<?php

namespace App\Admin\Pages;

use App\Settings\MailSettings;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Mail;

class ManageEmailTest extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static string $view = 'filament.pages.manage-email-test';

    protected static ?string $navigationGroup = '邮件设置';
    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string
    {
        return __('email-test.labels.email-test');
    }

    public function getTitle(): string
    {
        return __('email-test.labels.email-test');
    }

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'title' => '这是一条测试邮件',
            'body' => '这是一条测试邮件的正文内容<br/><br/>正文比较长<br/><br/>非常长<br/><br/>测试测试测试',
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                
                Forms\Components\Section::make(__('email-test.labels.send_test_email'))
                    ->description('测试邮件发送功能，确保邮件配置正确')
                    ->schema([
                        Forms\Components\TextInput::make('to')
                            ->label(__('email-test.labels.to'))
                            ->email()
                            ->required()
                            ->default(fn () => auth()->user()?->email ?? '')
                            ->helperText('可发送到任意合法邮箱地址，建议使用你能接收到的邮箱以便验证'),

                        Forms\Components\TextInput::make('title')
                            ->label(__('email-test.labels.title'))
                            ->required()
                            ->default('这是一条测试邮件')
                            ->helperText('支持变量：{{site.name}}、{{order.id}}、{{order.amount | money}}、{{customer.email}} 等。更多变量请查看开发文档。'),

                        Forms\Components\RichEditor::make('body')
                            ->label(__('email-test.labels.body'))
                            ->required()
                            ->default('这是一条测试邮件的正文内容<br/><br/>正文比较长<br/><br/>非常长<br/><br/>测试测试测试')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function send(): void
    {
        try {
            $data = $this->form->getState();

            $to = $data['to'];
            $title = $data['title'];
            $body = $data['body'];

            $mailSettings = app(MailSettings::class);

            if (empty($mailSettings->host) || empty($mailSettings->username) || empty($mailSettings->from_address)) {
                throw new \RuntimeException('请先在「邮件设置」中填写 SMTP 主机/用户名/发件地址，再回此页测试。');
            }

            $localDomain = substr(strrchr($mailSettings->from_address, '@'), 1) ?: 'localhost';

            config([
                'mail.default' => $mailSettings->driver ?? 'smtp',
                'mail.mailers.smtp.transport' => 'smtp',
                'mail.mailers.smtp.host' => $mailSettings->host,
                'mail.mailers.smtp.port' => $mailSettings->port ?? 465,
                'mail.mailers.smtp.encryption' => $mailSettings->encryption ?: null,
                'mail.mailers.smtp.username' => $mailSettings->username,
                'mail.mailers.smtp.password' => $mailSettings->password ?? '',
                'mail.mailers.smtp.timeout' => 15,
                'mail.mailers.smtp.local_domain' => $localDomain,
                'mail.from.address' => $mailSettings->from_address,
                'mail.from.name' => $mailSettings->from_name ?? '启航数卡',
            ]);

            app()->forgetInstance('mail.manager');
            app()->forgetInstance('mailer');
            \Illuminate\Support\Facades\Mail::clearResolvedInstances();

            \Log::info('SMTP 测试发件 - 当前生效配置', [
                'mail.default' => config('mail.default'),
                'mail.mailers.smtp.host' => config('mail.mailers.smtp.host'),
                'mail.mailers.smtp.port' => config('mail.mailers.smtp.port'),
                'mail.mailers.smtp.encryption' => config('mail.mailers.smtp.encryption'),
                'mail.mailers.smtp.username' => config('mail.mailers.smtp.username'),
                'mail.mailers.smtp.local_domain' => config('mail.mailers.smtp.local_domain'),
                'mail.from.address' => config('mail.from.address'),
                'to' => $to,
            ]);

            $mailer = \Illuminate\Support\Facades\Mail::mailer('smtp');
            $transport = $mailer->getSymfonyTransport();
            if (method_exists($transport, 'setLocalDomain')) {
                $transport->setLocalDomain($localDomain);
                \Log::info('SMTP 测试发件 - 强制设置 transport local_domain', ['local_domain' => $localDomain]);
            }
            if (method_exists($transport, 'getDebug')) {
                \Log::info('SMTP 测试发件 - transport 类型', ['class' => get_class($transport)]);
            }

            $mailer->send(['html' => 'email.mail'], ['body' => $body], function ($message) use ($to, $title) {
                $message->to($to)->subject($title);
            });

            if (method_exists($transport, 'getDebug')) {
                \Log::info('SMTP 测试发件 - SMTP 完整流', ['debug' => $transport->getDebug()]);
            }

            Notification::make()
                ->title(__('email-test.labels.success'))
                ->success()
                ->send();

        } catch (\Throwable $e) {
            \Log::error('邮件发送测试失败', [
                'error' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            Notification::make()
                ->title('发送失败')
                ->body('SMTP 错误: ' . $e->getMessage())
                ->danger()
                ->persistent()
                ->send();
        }
    }

    protected function getFormActions(): array
    {
        return [
            Forms\Components\Actions\Action::make('send')
                ->label(__('email-test.labels.send_test_email'))
                ->action('send')
                ->icon('heroicon-o-paper-airplane')
                ->color('primary'),
        ];
    }
}