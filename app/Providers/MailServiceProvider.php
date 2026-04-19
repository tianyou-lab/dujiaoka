<?php

namespace App\Providers;

use App\Settings\MailSettings;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;

class MailServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        try {
            if (! Schema::hasTable('settings')) {
                return;
            }

            $mailSettings = $this->app->make(MailSettings::class);

            if (empty($mailSettings->host) || empty($mailSettings->username) || empty($mailSettings->from_address)) {
                return;
            }

            $localDomain = substr(strrchr($mailSettings->from_address, '@'), 1) ?: 'localhost';

            config([
                'mail.default' => $mailSettings->driver ?: 'smtp',
                'mail.mailers.smtp.transport' => 'smtp',
                'mail.mailers.smtp.host' => $mailSettings->host,
                'mail.mailers.smtp.port' => $mailSettings->port ?: 465,
                'mail.mailers.smtp.encryption' => $mailSettings->encryption ?: null,
                'mail.mailers.smtp.username' => $mailSettings->username,
                'mail.mailers.smtp.password' => $mailSettings->password ?? '',
                'mail.mailers.smtp.timeout' => 15,
                'mail.mailers.smtp.local_domain' => $localDomain,
                'mail.from.address' => $mailSettings->from_address,
                'mail.from.name' => $mailSettings->from_name ?: '启航数卡',
            ]);
        } catch (\Throwable $e) {
            \Log::warning('MailServiceProvider 加载 MailSettings 失败，邮件将使用 .env 默认配置', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
