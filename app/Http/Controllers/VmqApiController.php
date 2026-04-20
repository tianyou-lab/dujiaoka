<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\VmqPayOrder;
use App\Models\VmqSetting;
use App\Models\VmqTmpPrice;
use App\Services\OrderProcess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 嵌入式 V免签 API 控制器
 *
 * 完整实现 V免签 协议，**不需要独立监控端服务器**：
 *   - GET  /createOrder   下单并跳转到收银台（可选）
 *   - POST /checkOrder    前端轮询订单状态
 *   - POST /getOrder      获取订单详情（收银台渲染用）
 *   - POST /appHeart      安卓 V免签 App 心跳
 *   - POST /appPush       安卓 V免签 App 推送到账
 *   - POST /getState      查询监控端在线状态
 *   - GET  /pay/vmq/cashier/{orderSN}  本站内置收银台页面
 *
 * 所有 POST 接口都在路由层免 CSRF（App 端无法携带 token）。
 */
class VmqApiController extends Controller
{
    /**
     * 标准返回结构（兼容旧 V免签 App）
     */
    protected function out(int $code = 1, string $msg = '成功', array $data = null): array
    {
        return array_filter([
            'code' => $code,
            'msg'  => $msg,
            'data' => $data,
        ], fn($v) => $v !== null);
    }

    /**
     * GET /createOrder
     *
     * 兼容 V免签 原生协议的下单入口：某些第三方系统可能直接请求本站 /createOrder，
     * 这里做签名校验后写入 vmq_pay_orders 并 302 到收银台页面。
     *
     * dujiaoka 自身用户走 UnifiedPaymentController → VmqDriver::gateway() 内部创建订单，
     * 不会访问此接口；此接口主要用于向老系统/第三方兼容。
     */
    public function createOrder(Request $request)
    {
        $this->closeEndOrders();

        $payId    = (string) $request->input('payId', '');
        $type     = (int)    $request->input('type', 0);
        $price    = (string) $request->input('price', '');
        $param    = (string) $request->input('param', '');
        $sign     = (string) $request->input('sign', '');
        $isHtml   = (int)    $request->input('isHtml', 0);

        if ($payId === '') {
            return response()->json($this->out(-1, '请传入商户订单号'));
        }
        if (!in_array($type, [1, 2], true)) {
            return response()->json($this->out(-1, '支付方式错误（1=微信 2=支付宝）'));
        }
        if ($price === '' || (float) $price <= 0) {
            return response()->json($this->out(-1, '订单金额必须大于 0'));
        }

        $key = (string) VmqSetting::get('key', '');
        if ($key === '') {
            return response()->json($this->out(-1, 'V免签 通讯密钥未配置'));
        }

        $expectSign = md5($payId . $param . $type . $price . $key);
        if (!hash_equals($expectSign, $sign)) {
            return response()->json($this->out(-1, '签名校验不通过'));
        }

        if ((string) VmqSetting::get('enable', '1') !== '1') {
            return response()->json($this->out(-1, 'V免签 已全局停用'));
        }
        if ((string) VmqSetting::get('jk_state', '0') !== '1') {
            return response()->json($this->out(-1, '监控端状态异常，请检查 App 心跳'));
        }

        // 如果 payId 已存在未关闭订单，直接复用
        $existing = VmqPayOrder::where('vmq_order_id', $payId)->first();
        if ($existing) {
            if ($isHtml === 1) {
                return redirect()->to($this->cashierUrlByVmqOrderId($payId));
            }
            return response()->json($this->out(1, '成功', [
                'payId'   => $existing->vmq_order_id,
                'orderId' => $existing->vmq_order_id,
                'payType' => $existing->type,
                'price'   => (string) $existing->price,
                'reallyPrice' => (string) $existing->really_price,
                'state'   => $existing->state,
            ]));
        }

        $priceFen = (int) bcmul($price, '100', 0);
        $payQf    = (int) VmqSetting::get('pay_qf', '1');
        $realFen  = $priceFen;
        $locked   = false;
        $vmqOrderId = date('YmdHis') . mt_rand(10000, 99999);

        for ($i = 0; $i < 300; $i++) {
            $priceKey = $realFen . '-' . $type;
            try {
                $inserted = DB::insert(
                    'INSERT IGNORE INTO vmq_tmp_prices (price_key, vmq_order_id, create_date) VALUES (?, ?, ?)',
                    [$priceKey, $vmqOrderId, time()]
                );
                if ($inserted) {
                    $locked = true;
                    break;
                }
            } catch (\Throwable $e) {
                Log::warning('VmqApi createOrder tmp_price ex', ['msg' => $e->getMessage()]);
            }
            $realFen = $payQf === 2 ? ($realFen - 1) : ($realFen + 1);
            if ($realFen <= 0) {
                break;
            }
        }

        if (!$locked) {
            return response()->json($this->out(-1, '订单超出负荷，请稍后重试'));
        }

        $reallyPrice = number_format($realFen / 100, 2, '.', '');

        VmqPayOrder::create([
            'order_sn'     => $param !== '' ? $param : $vmqOrderId,
            'vmq_order_id' => $vmqOrderId,
            'pay_id'       => 0,
            'type'         => $type,
            'price'        => $price,
            'really_price' => $reallyPrice,
            'pay_url'      => '',
            'is_auto'      => 1,
            'state'        => VmqPayOrder::STATE_WAIT,
            'create_date'  => time(),
            'pay_date'     => 0,
            'close_date'   => 0,
            'param'        => $param,
        ]);

        if ($isHtml === 1) {
            return redirect()->to($this->cashierUrlByVmqOrderId($vmqOrderId));
        }

        return response()->json($this->out(1, '成功', [
            'payId'   => $vmqOrderId,
            'orderId' => $vmqOrderId,
            'payType' => $type,
            'price'   => $price,
            'reallyPrice' => $reallyPrice,
        ]));
    }

    /**
     * POST /getOrder
     * 收银台渲染用的订单详情接口
     */
    public function getOrder(Request $request): JsonResponse
    {
        $this->closeEndOrders();

        $vmqOrderId = (string) $request->input('orderId', '');
        $orderSN    = (string) $request->input('orderSN', '');

        $order = null;
        if ($vmqOrderId !== '') {
            $order = VmqPayOrder::where('vmq_order_id', $vmqOrderId)->first();
        }
        if (!$order && $orderSN !== '') {
            $order = VmqPayOrder::where('order_sn', $orderSN)->first();
        }

        if (!$order) {
            return response()->json($this->out(-1, '订单不存在'));
        }

        $closeMin = (int) VmqSetting::get('close_minutes', '10');

        return response()->json($this->out(1, '成功', [
            'payId'       => $order->vmq_order_id,
            'orderId'     => $order->vmq_order_id,
            'orderSN'     => $order->order_sn,
            'payType'     => $order->type,
            'price'       => (string) $order->price,
            'reallyPrice' => (string) $order->really_price,
            'payUrl'      => $order->pay_url,
            'isAuto'      => $order->is_auto,
            'state'       => $order->state,
            'timeOut'     => $closeMin,
            'param'       => $order->param,
            'date'        => $order->create_date,
        ]));
    }

    /**
     * POST /checkOrder
     * 前端 1~2 秒轮询一次，直到 state=1 或 state=-1
     */
    public function checkOrder(Request $request): JsonResponse
    {
        $this->closeEndOrders();

        $vmqOrderId = (string) $request->input('orderId', '');
        $orderSN    = (string) $request->input('orderSN', '');

        $order = null;
        if ($vmqOrderId !== '') {
            $order = VmqPayOrder::where('vmq_order_id', $vmqOrderId)->first();
        }
        if (!$order && $orderSN !== '') {
            $order = VmqPayOrder::where('order_sn', $orderSN)->first();
        }

        if (!$order) {
            return response()->json($this->out(-1, '订单不存在'));
        }
        if ($order->state === VmqPayOrder::STATE_WAIT) {
            return response()->json($this->out(-1, '订单未支付'));
        }
        if ($order->state === VmqPayOrder::STATE_CLOSED) {
            return response()->json($this->out(-1, '订单已过期'));
        }

        return response()->json($this->out(1, '成功', [
            'redirect' => url('/order/detail/' . $order->order_sn),
        ]));
    }

    /**
     * POST /appHeart
     * 安卓 V免签 App 周期上报心跳（通常 15 秒一次）
     */
    public function appHeart(Request $request): JsonResponse
    {
        $this->closeEndOrders();

        $t    = (string) $request->input('t', '0');
        $sign = (string) $request->input('sign', '');

        $key = (string) VmqSetting::get('key', '');
        if ($key === '') {
            return response()->json($this->out(-1, 'V免签 通讯密钥未配置'));
        }

        $expect = md5($t . $key);
        if (!hash_equals($expect, $sign)) {
            return response()->json($this->out(-1, '签名校验不通过'));
        }

        $gap = abs(time() * 1000 - (int) $t);
        if ($gap > 300000) {
            return response()->json($this->out(-1, '客户端时间误差过大，请同步手机时间'));
        }

        VmqSetting::put('last_heart', (string) time());
        VmqSetting::put('jk_state', '1');

        return response()->json($this->out(1, '成功'));
    }

    /**
     * POST /appPush
     * 安卓 V免签 App 推送到账消息（必须在 5 分钟内到达，否则忽略）
     *
     * 签名规则：md5(type + price + t + key)
     */
    public function appPush(Request $request)
    {
        $this->closeEndOrders();

        $t     = (string) $request->input('t', '0');
        $type  = (int)    $request->input('type', 0);
        $price = (string) $request->input('price', '');
        $sign  = (string) $request->input('sign', '');

        $key = (string) VmqSetting::get('key', '');
        if ($key === '') {
            return response()->json($this->out(-1, 'V免签 通讯密钥未配置'));
        }

        $expect = md5($type . $price . $t . $key);
        if (!hash_equals($expect, $sign)) {
            return response()->json($this->out(-1, '签名校验不通过'));
        }

        $gap = abs(time() * 1000 - (int) $t);
        if ($gap > 300000) {
            return response()->json($this->out(-1, '客户端时间误差过大'));
        }

        if (!in_array($type, [1, 2], true)) {
            return response()->json($this->out(-1, '支付类型错误'));
        }
        if ((float) $price <= 0) {
            return response()->json($this->out(-1, '金额非法'));
        }

        VmqSetting::put('last_pay', (string) time());

        // 匹配金额+类型+待支付状态的最早一单
        $vmqOrder = VmqPayOrder::where('really_price', $price)
            ->where('type', $type)
            ->where('state', VmqPayOrder::STATE_WAIT)
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->first();

        if (!$vmqOrder) {
            Log::warning('V免签 到账推送无匹配订单', [
                'type'  => $type,
                'price' => $price,
            ]);
            return response()->json($this->out(1, '无匹配订单，已忽略'));
        }

        DB::beginTransaction();
        try {
            $affected = VmqPayOrder::where('id', $vmqOrder->id)
                ->where('state', VmqPayOrder::STATE_WAIT)
                ->update([
                    'state'      => VmqPayOrder::STATE_PAID,
                    'pay_date'   => time(),
                    'close_date' => time(),
                ]);

            if (!$affected) {
                DB::commit();
                return response()->json($this->out(1, '已被其他进程处理'));
            }

            VmqTmpPrice::where('vmq_order_id', $vmqOrder->vmq_order_id)->delete();

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('V免签 appPush 更新订单失败', [
                'message' => $e->getMessage(),
                'id'      => $vmqOrder->id,
            ]);
            return response()->json($this->out(-1, '内部错误'));
        }

        // 触发 dujiaoka 订单履约
        try {
            $dujOrder = Order::where('order_sn', $vmqOrder->order_sn)->first();
            if ($dujOrder && $dujOrder->status === Order::STATUS_WAIT_PAY) {
                app(OrderProcess::class)->completedOrder(
                    $vmqOrder->order_sn,
                    (float) $vmqOrder->really_price,
                    $vmqOrder->vmq_order_id
                );
            }
        } catch (\Throwable $e) {
            Log::error('V免签 触发 dujiaoka 履约失败', [
                'order_sn' => $vmqOrder->order_sn,
                'message'  => $e->getMessage(),
            ]);
            return response()->json($this->out(-1, $e->getMessage()));
        }

        return response()->json($this->out(1, '成功'));
    }

    /**
     * POST /getState
     * 后台/外部查询监控端在线状态
     */
    public function getState(Request $request): JsonResponse
    {
        $this->closeEndOrders();

        $t    = (string) $request->input('t', '0');
        $sign = (string) $request->input('sign', '');

        $key = (string) VmqSetting::get('key', '');
        if ($key === '') {
            return response()->json($this->out(-1, 'V免签 通讯密钥未配置'));
        }
        $expect = md5($t . $key);
        if (!hash_equals($expect, $sign)) {
            return response()->json($this->out(-1, '签名校验不通过'));
        }

        return response()->json($this->out(1, '成功', [
            'lastheart' => VmqSetting::get('last_heart', '0'),
            'lastpay'   => VmqSetting::get('last_pay', '0'),
            'jkstate'   => VmqSetting::get('jk_state', '0'),
        ]));
    }

    /**
     * GET /pay/vmq/cashier/{orderSN}
     * 本站内置收银台页面（Blade）
     */
    public function cashier(string $orderSN)
    {
        $this->closeEndOrders();

        $vmqOrder = VmqPayOrder::where('order_sn', $orderSN)->first();
        if (!$vmqOrder) {
            return view('morpho::errors.error', [
                'title'   => 'V免签 收银台',
                'content' => '订单不存在或已失效',
                'url'     => url('/'),
            ]);
        }

        $closeMin = (int) VmqSetting::get('close_minutes', '10');

        return view('vmq.cashier', [
            'order'    => $vmqOrder,
            'closeMin' => $closeMin,
        ]);
    }

    /**
     * GET /pay/vmq/qr/{orderSN}
     * 返回二维码 PNG 图片；内容优先使用 pay_url，否则退化为金额+订单号的文本
     */
    public function qr(string $orderSN)
    {
        $vmqOrder = VmqPayOrder::where('order_sn', $orderSN)->first();
        if (!$vmqOrder) {
            abort(404);
        }

        $content = $vmqOrder->pay_url !== '' ? $vmqOrder->pay_url
            : sprintf('VMQ|%s|%s|%s', $vmqOrder->type, $vmqOrder->really_price, $vmqOrder->vmq_order_id);

        try {
            $png = \SimpleSoftwareIO\QrCode\Facades\QrCode::format('png')
                ->size(300)
                ->margin(1)
                ->errorCorrection('M')
                ->generate($content);
            return response($png, 200, [
                'Content-Type'  => 'image/png',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
            ]);
        } catch (\Throwable $e) {
            Log::error('V免签 二维码生成失败', ['msg' => $e->getMessage()]);
            abort(500, 'QR generation failed');
        }
    }

    /**
     * GET /pay/vmq/heart-public
     * 收银台专用的监控端状态查询（无签名，仅返回 jk_state / last_heart，不泄露密钥）
     */
    public function heartPublic(): JsonResponse
    {
        $this->closeEndOrders();

        return response()->json([
            'jk_state'   => (string) VmqSetting::get('jk_state', '0'),
            'last_heart' => (int)    VmqSetting::get('last_heart', '0'),
        ]);
    }

    /**
     * 自动清理超时订单（每次 API 请求顺带调一次，无需额外定时任务）
     */
    protected function closeEndOrders(): void
    {
        $heartTimeout = (int) VmqSetting::get('heart_timeout', '60');
        $lastHeart    = (int) VmqSetting::get('last_heart', '0');
        if (time() - $lastHeart > $heartTimeout) {
            VmqSetting::put('jk_state', '0');
        }

        $closeMin = (int) VmqSetting::get('close_minutes', '10');
        $expireBefore = time() - 60 * $closeMin;

        try {
            DB::transaction(function () use ($expireBefore) {
                $expiredVmqIds = VmqPayOrder::where('state', VmqPayOrder::STATE_WAIT)
                    ->where('create_date', '<=', $expireBefore)
                    ->pluck('vmq_order_id')
                    ->all();

                if (!empty($expiredVmqIds)) {
                    VmqPayOrder::whereIn('vmq_order_id', $expiredVmqIds)
                        ->where('state', VmqPayOrder::STATE_WAIT)
                        ->update([
                            'state'      => VmqPayOrder::STATE_CLOSED,
                            'close_date' => time(),
                        ]);

                    VmqTmpPrice::whereIn('vmq_order_id', $expiredVmqIds)->delete();
                }

                // 兜底清理 tmp_prices 里的孤儿记录
                VmqTmpPrice::where('create_date', '<=', $expireBefore)->delete();
            });
        } catch (\Throwable $e) {
            Log::warning('V免签 closeEndOrders 失败', ['message' => $e->getMessage()]);
        }
    }

    protected function cashierUrlByVmqOrderId(string $vmqOrderId): string
    {
        $row = VmqPayOrder::where('vmq_order_id', $vmqOrderId)->first();
        return $row ? url('/pay/vmq/cashier/' . $row->order_sn) : url('/');
    }
}
