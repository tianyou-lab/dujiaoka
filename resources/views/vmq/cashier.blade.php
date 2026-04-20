<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>扫码支付 · {{ $order->type == \App\Models\VmqPayOrder::TYPE_WECHAT ? '微信' : '支付宝' }}</title>
    <style>
        body {
            margin: 0;
            background: linear-gradient(135deg, #eef2ff 0%, #f8fafc 100%);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", Helvetica, Arial, sans-serif;
            color: #1f2937;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            box-sizing: border-box;
        }
        .card {
            width: 100%;
            max-width: 420px;
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 20px 60px rgba(15, 23, 42, 0.08);
            padding: 32px 28px;
            text-align: center;
        }
        .brand {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-size: 16px;
            font-weight: 600;
            padding: 6px 14px;
            border-radius: 999px;
            background: {{ $order->type == \App\Models\VmqPayOrder::TYPE_WECHAT ? '#ecfdf5' : '#eff6ff' }};
            color: {{ $order->type == \App\Models\VmqPayOrder::TYPE_WECHAT ? '#059669' : '#2563eb' }};
        }
        .price {
            margin: 18px 0 6px;
            font-size: 40px;
            font-weight: 700;
            color: {{ $order->type == \App\Models\VmqPayOrder::TYPE_WECHAT ? '#059669' : '#2563eb' }};
            letter-spacing: -1px;
        }
        .price .unit { font-size: 20px; margin-right: 4px; vertical-align: top; }
        .tip {
            font-size: 13px;
            color: #ef4444;
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 10px;
            padding: 10px 12px;
            margin: 8px 0 22px;
            line-height: 1.6;
        }
        .qrwrap {
            padding: 16px;
            border-radius: 14px;
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            display: inline-block;
            margin: 0 auto 20px;
        }
        .qrwrap img { display: block; width: 240px; height: 240px; }
        .countdown {
            font-size: 14px;
            color: #475569;
            margin-bottom: 16px;
        }
        .countdown strong { color: #1f2937; font-weight: 600; }
        .meta {
            font-size: 12px;
            color: #94a3b8;
            line-height: 1.8;
            text-align: left;
            background: #f8fafc;
            border-radius: 10px;
            padding: 12px 14px;
        }
        .meta .row { display: flex; justify-content: space-between; gap: 12px; }
        .meta .row span:first-child { color: #64748b; }
        .expired {
            display: none;
            padding: 24px;
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #b91c1c;
            border-radius: 12px;
            font-size: 14px;
        }
        .offline-warn {
            display: none;
            margin-bottom: 12px;
            padding: 10px 12px;
            background: #fefce8;
            color: #854d0e;
            border: 1px solid #fde68a;
            border-radius: 10px;
            font-size: 12.5px;
        }
        .toast {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(15, 23, 42, 0.9);
            color: #fff;
            padding: 10px 18px;
            border-radius: 8px;
            font-size: 14px;
            display: none;
        }
        @media (max-width: 420px) {
            .qrwrap img { width: 200px; height: 200px; }
            .price { font-size: 34px; }
        }
    </style>
</head>
<body>
<div class="card">
    <div id="pay-box">
        <div class="brand">
            @if($order->type == \App\Models\VmqPayOrder::TYPE_WECHAT)
                <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M9.5 9c.828 0 1.5-.672 1.5-1.5S10.328 6 9.5 6 8 6.672 8 7.5 8.672 9 9.5 9zm5 0c.828 0 1.5-.672 1.5-1.5S15.328 6 14.5 6 13 6.672 13 7.5s.672 1.5 1.5 1.5z"/></svg>
                微信扫码支付
            @else
                <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8z"/></svg>
                支付宝扫码支付
            @endif
        </div>

        <div class="offline-warn" id="offline-warn">
            监控端 App 当前离线，到账可能延迟。请确认安卓 V免签 App 正在后台运行。
        </div>

        @if(!($hasPayUrl ?? true))
        <div class="offline-warn" style="display:block;background:#fef2f2;border-color:#fecaca;color:#b91c1c;">
            管理员尚未在「V免签 全局设置」配置 {{ $order->type == \App\Models\VmqPayOrder::TYPE_WECHAT ? '微信' : '支付宝' }} 收款码，二维码无法扫码到账。请联系站长。
        </div>
        @endif

        <div class="price"><span class="unit">￥</span>{{ number_format((float) $order->really_price, 2, '.', '') }}</div>

        @if(bccomp((string) $order->really_price, (string) $order->price, 2) !== 0)
        <div class="tip">
            为了系统识别您的订单，请<strong>必须支付 ￥{{ number_format((float) $order->really_price, 2, '.', '') }}</strong><br>
            原价 ￥{{ number_format((float) $order->price, 2, '.', '') }}，已做金额错位
        </div>
        @endif

        <div class="qrwrap">
            <img id="qr" alt="二维码加载中..." src="{{ route('pay.vmq.qr', ['orderSN' => $order->order_sn]) }}">
        </div>

        <div class="countdown">
            剩余支付时间：<strong id="minute">--</strong> 分 <strong id="second">--</strong> 秒
        </div>

        <div class="meta">
            <div class="row"><span>订单号</span><span>{{ $order->order_sn }}</span></div>
            <div class="row"><span>V免签 单号</span><span>{{ $order->vmq_order_id }}</span></div>
            <div class="row"><span>创建时间</span><span>{{ date('Y-m-d H:i:s', $order->create_date) }}</span></div>
        </div>
    </div>

    <div class="expired" id="expired-box">
        订单已过期，请返回页面重新下单。
    </div>
</div>

<div class="toast" id="toast"></div>

<script>
    (function () {
        const orderSN = {!! json_encode($order->order_sn) !!};
        const closeMin = {!! (int) $closeMin !!};
        const createTs = {!! (int) $order->create_date !!};
        const successUrl = {!! json_encode(url('/order/detail/' . $order->order_sn)) !!};

        const $minute = document.getElementById('minute');
        const $second = document.getElementById('second');
        const $payBox = document.getElementById('pay-box');
        const $expiredBox = document.getElementById('expired-box');
        const $offlineWarn = document.getElementById('offline-warn');
        const $toast = document.getElementById('toast');

        let expired = false;

        function tick() {
            const now = Math.floor(Date.now() / 1000);
            const remain = createTs + closeMin * 60 - now;
            if (remain <= 0) {
                $minute.textContent = '0';
                $second.textContent = '0';
                if (!expired) {
                    expired = true;
                    $payBox.style.display = 'none';
                    $expiredBox.style.display = 'block';
                }
                return;
            }
            const m = Math.floor(remain / 60);
            const s = remain % 60;
            $minute.textContent = String(m).padStart(2, '0');
            $second.textContent = String(s).padStart(2, '0');
        }
        tick();
        setInterval(tick, 1000);

        function showToast(msg) {
            $toast.textContent = msg;
            $toast.style.display = 'block';
            setTimeout(() => { $toast.style.display = 'none'; }, 2500);
        }

        async function poll() {
            if (expired) return;
            try {
                const form = new FormData();
                form.append('orderSN', orderSN);
                const resp = await fetch({!! json_encode(url('/checkOrder')) !!}, {
                    method: 'POST',
                    body: form,
                    credentials: 'same-origin',
                });
                const data = await resp.json();
                if (data && data.code === 1) {
                    showToast('支付成功，正在跳转...');
                    setTimeout(() => { window.location.href = (data.data && data.data.redirect) || successUrl; }, 800);
                    return;
                }
                if (data && data.msg === '订单已过期') {
                    expired = true;
                    $payBox.style.display = 'none';
                    $expiredBox.style.display = 'block';
                    return;
                }
            } catch (e) { /* 忽略网络抖动 */ }
            setTimeout(poll, 1800);
        }
        setTimeout(poll, 1500);

        // 后台接口健康检查：监控 App 掉线时给用户提示
        async function checkHeart() {
            try {
                const resp = await fetch({!! json_encode(url('/pay/vmq/heart-public')) !!}, {
                    method: 'GET',
                    credentials: 'same-origin',
                });
                const data = await resp.json();
                if (data && data.jk_state === '0') {
                    $offlineWarn.style.display = 'block';
                }
            } catch (e) { /* ignore */ }
        }
        checkHeart();
        setInterval(checkHeart, 20000);
    })();
</script>
</body>
</html>
