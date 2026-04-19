<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Bus\Queueable;

/**
 * 队列化的邮箱验证通知
 *
 * 继承 Laravel 内置的 VerifyEmail 行为，但通过 ShouldQueue 接口将
 * 邮件发送投递到队列异步执行，避免 SMTP 失败/超时直接阻塞 HTTP 请求。
 */
class QueuedVerifyEmail extends VerifyEmail implements ShouldQueue
{
    use Queueable;
}
