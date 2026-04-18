<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class MailSend implements ShouldQueue
{

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 任务最大尝试次数。
     *
     * @var int
     */
    public $tries = 2;

    /**
     * 任务运行的超时时间。
     *
     * @var int
     */
    public $timeout = 30;

    private $to;

    private $content;

    private $title;


    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(string $to, string $title, string $content)
    {
        $this->to = $to;
        $this->title = $title;
        $this->content = $content;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $body = $this->content;
        $title = $this->title;
        $to = $this->to;
        $sysConfig = cache('system-setting');

        $mailerName = 'dujiaoka_dynamic';
        config([
            "mail.mailers.{$mailerName}" => [
                'transport' => $sysConfig['driver'] ?? 'smtp',
                'host' => $sysConfig['host'] ?? '',
                'port' => $sysConfig['port'] ?? '465',
                'username' => $sysConfig['username'] ?? '',
                'password' => $sysConfig['password'] ?? '',
                'encryption' => $sysConfig['encryption'] ?? '',
            ],
        ]);

        $fromAddress = $sysConfig['from_address'] ?? config('mail.from.address');
        $fromName = $sysConfig['from_name'] ?? '独角发卡';

        Mail::mailer($mailerName)->send(
            ['html' => 'email.mail'],
            ['body' => $body],
            function ($message) use ($to, $title, $fromAddress, $fromName) {
                $message->from($fromAddress, $fromName)->to($to)->subject($title);
            }
        );
    }
}
