<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6 flex justify-end gap-3">
            @foreach ($this->getFormActions() as $action)
                {{ $action }}
            @endforeach
        </div>
    </form>

    <div class="mt-8 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
        <h3 class="mb-2 text-base font-semibold text-gray-900 dark:text-white">嵌入式 V免签 对接说明</h3>
        <div class="space-y-2 text-sm leading-6 text-gray-600 dark:text-gray-300">
            <p><strong>架构：</strong>本站即 V免签 监控端，无需额外搭建 PHP 监控项目，一台服务器 + 一部安装了《码支付监控 App》的手机即可。</p>
            <p><strong>App 配置项：</strong></p>
            <ul class="ml-5 list-disc space-y-1">
                <li>服务端地址：<code>https://xxxxxxx.com/</code>（替换成你的真实发卡站域名，<strong>末尾斜杠必须有</strong>）</li>
                <li>通讯密钥：与本页「通讯密钥」完全一致</li>
                <li>心跳间隔：建议 30 秒</li>
            </ul>
            <p><strong>接口清单（均已自动免 CSRF）：</strong></p>
            <ul class="ml-5 list-disc space-y-1">
                <li>App 心跳：<code>POST https://xxxxxxx.com/appHeart</code></li>
                <li>App 到账推送：<code>POST https://xxxxxxx.com/appPush</code></li>
                <li>本站轮询下单状态：<code>POST https://xxxxxxx.com/checkOrder</code></li>
                <li>外部兼容下单：<code>POST https://xxxxxxx.com/createOrder</code></li>
            </ul>
            <p><strong>安全建议：</strong>通讯密钥请妥善保管，泄露后任何人都可伪造到账推送。可随时点击输入框右侧的 <em>「随机生成」</em> 重新下发新密钥。</p>
        </div>
    </div>
</x-filament-panels::page>
