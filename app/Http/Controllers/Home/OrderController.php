<?php

namespace App\Http\Controllers\Home;

use App\Exceptions\RuleValidationException;
use App\Http\Controllers\BaseController;
use App\Models\Order;
use App\Models\Goods;
use App\Models\Carmis;
use App\Models\Pay;
use App\Models\User;
use App\Jobs\OrderExpired;
use App\Services\OrderProcess;
use App\Services\CacheManager;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;


/**
 * 订单控制器
 *
 * Class OrderController
 * @package App\Http\Controllers\Home
 * @author: Assimon
 * @email: Ashang@utf8.hk
 * @blog: https://utf8.hk
 * Date: 2021/5/30
 */
class OrderController extends BaseController
{


    /**
     * 订单服务层
     * @var \App\Services\Orders
     */
    private $orderService;

    /**
     * 订单处理层.
     * @var OrderProcessService
     */
    private $orderProcessService;

    public function __construct()
    {
        $this->orderService = app('App\Services\Orders');
        $this->orderProcessService = app('App\Services\OrderProcess');
    }

    /**
     * 创建订单
     */
    public function createOrder(Request $request)
    {
        DB::beginTransaction();
        try {
            $cartItems = $request->input('cart_items', []);
            if (empty($cartItems)) {
                throw new RuleValidationException('购物车为空');
            }

            // IP 待支付订单数限制
            $ipLimit = (int)cfg('order_ip_limits', 0);
            if ($ipLimit > 0) {
                $pendingCount = Order::where('buy_ip', $request->getClientIp())
                    ->where('status', Order::STATUS_WAIT_PAY)
                    ->count();
                if ($pendingCount >= $ipLimit) {
                    throw new RuleValidationException(__('dujiaoka.prompt.order_ip_limits'));
                }
            }

            // 获取用户信息以决定验证规则
            $user = Auth::guard('web')->user();
            $contactRequired = cfg('contact_required', 'email');

            // 根据设置和用户状态决定email字段验证规则
            $emailRule = 'nullable';
            if ($contactRequired === 'email') {
                $emailRule = $user ? 'nullable|email' : 'required|email';
            } elseif ($contactRequired === 'any') {
                $emailRule = $user ? 'nullable|string' : 'required|string|min:6';
            }
            
            // 游客且开启查询密码时，search_pwd 必填，否则支付后无法进入详情页
            $searchPwdRule = 'nullable|string';
            if (!$user && cfg('is_open_search_pwd', \App\Models\BaseModel::STATUS_CLOSE) == \App\Models\BaseModel::STATUS_OPEN) {
                $searchPwdRule = 'required|string|min:1';
            }

            $validated = $request->validate([
                'email' => $emailRule,
                'payway' => 'required|integer',
                'search_pwd' => $searchPwdRule,
                'cart_items' => 'required|array',
                'cart_items.*.goods_id' => 'required|integer',
                'cart_items.*.sub_id' => 'required|integer', 
                'cart_items.*.quantity' => 'required|integer|min:1',
                'use_balance' => 'boolean',
                'balance_amount' => 'numeric|min:0'
            ]);

            $userDiscountRate = 1.00;
            $userId = null;
            
            if ($user) {
                $userId = $user->id;
                $userDiscountRate = $user->discount_rate;
                // 如果用户已登录但未提供邮箱，使用用户邮箱
                if (empty($validated['email'])) {
                    $validated['email'] = $user->email;
                }
            }

            $totalPrice = 0;
            $orderItems = [];

            // 获取库存模式配置
            $stockMode = cfg('stock_mode', 2); // 默认发货时减库存
            $orderSn = strtoupper(\Illuminate\Support\Str::random(16)); // 提前生成订单号用于库存锁定

            foreach ($cartItems as $item) {
                $goods = Goods::with('goods_sub')->find($item['goods_id']);
                if (!$goods || !$goods->is_open) {
                    throw new RuleValidationException("商品不存在或已下架");
                }

                // 检查是否需要登录购买
                if ($goods->require_login && !$user) {
                    throw new RuleValidationException("{$goods->gd_name} 需要登录后才能购买");
                }

                // 服务端校验商品支付方式限制
                $paymentLimit = $goods->payment_limit ?? [];
                if (!empty($paymentLimit) && !in_array((int)$validated['payway'], array_map('intval', $paymentLimit))) {
                    throw new RuleValidationException("{$goods->gd_name} 不支持所选支付方式");
                }

                $sub = $goods->goods_sub()->find($item['sub_id']);
                if (!$sub) {
                    throw new RuleValidationException("商品规格不存在");
                }

                // 计算实际库存
                $actualStock = $goods->type == Goods::AUTOMATIC_DELIVERY 
                    ? Carmis::where('goods_id', $goods->id)->where('sub_id', $item['sub_id'])->where('status', 1)->count()
                    : $sub->stock;

                // 根据库存模式检查库存
                if ($stockMode == 1) {
                    // 下单即减库存模式：需要考虑已锁定的库存
                    if (!CacheManager::checkStockAvailable($item['sub_id'], $item['quantity'], $actualStock)) {
                        throw new RuleValidationException("{$goods->gd_name} 库存不足");
                    }
                } else {
                    // 发货时减库存模式：直接检查实际库存
                    if ($item['quantity'] > $actualStock) {
                        throw new RuleValidationException("{$goods->gd_name} 库存不足");
                    }
                }

                // 检查购买数量限制
                if ($goods->buy_limit_num > 0) {
                    if ($goods->require_login && $user) {
                        // 检查所有有效订单（待支付、待处理、处理中、已完成）
                        $purchasedQuantity = \App\Models\OrderItem::join('orders', 'order_items.order_id', '=', 'orders.id')
                            ->where('orders.user_id', $user->id)
                            ->where('order_items.goods_id', $goods->id)
                            ->whereIn('orders.status', [
                                \App\Models\Order::STATUS_WAIT_PAY,
                                \App\Models\Order::STATUS_PENDING,
                                \App\Models\Order::STATUS_PROCESSING,
                                \App\Models\Order::STATUS_COMPLETED
                            ])
                            ->sum('order_items.quantity');
                            
                        if ($purchasedQuantity + $item['quantity'] > $goods->buy_limit_num) {
                            throw new RuleValidationException("{$goods->gd_name} 超出最大购买数量（已下单 {$purchasedQuantity} 件，限购 {$goods->buy_limit_num} 件）");
                        }
                    } else {
                        if ($item['quantity'] > $goods->buy_limit_num) {
                            throw new RuleValidationException("{$goods->gd_name} 超出限购数量");
                        }
                    }
                }

                // 检查最低购买数量
                if ($goods->buy_min_num > 0 && $item['quantity'] < $goods->buy_min_num) {
                    throw new RuleValidationException("{$goods->gd_name} 最低购买数量为 {$goods->buy_min_num}");
                }

                // 应用用户等级折扣
                $originalPrice = $sub->price;
                $discountedPrice = $originalPrice * $userDiscountRate;
                $subtotal = $discountedPrice * $item['quantity'];
                $totalPrice += $subtotal;

                // 处理自定义字段（含服务端必填校验）
                $customFields = $item['custom_fields'] ?? [];
                $formFields = $goods->customer_form_fields ?? [];
                foreach ($formFields as $fieldCfg) {
                    $key = $fieldCfg['field_key'] ?? '';
                    $type = $fieldCfg['field_type'] ?? 'input';
                    if ($type === 'switch') {
                        continue; // switch 有默认值 0，不强制
                    }
                    if (!isset($customFields[$key]) || trim((string)$customFields[$key]) === '') {
                        $desc = $fieldCfg['field_description'] ?? $key;
                        throw new RuleValidationException("{$goods->gd_name} 的「{$desc}」不能为空");
                    }
                    // 只允许商品配置中声明的 key
                }
                // 过滤掉未声明的 key，防止注入多余字段
                if (!empty($formFields)) {
                    $allowedKeys = array_column($formFields, 'field_key');
                    $customFields = array_intersect_key($customFields, array_flip($allowedKeys));
                }
                $infoHtml = '';
                if (!empty($customFields)) {
                    $infoItems = [];
                    foreach ($customFields as $key => $value) {
                        $safeKey = htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8');
                        $safeValue = htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
                        $displayValue = in_array($value, ['0', '1', 0, 1]) ? ($value == 1 ? '是' : '否') : $safeValue;
                        $infoItems[] = "{$safeKey}: {$displayValue}";
                    }
                    $infoHtml = implode("\n", $infoItems);
                }

                $orderItems[] = [
                    'goods_id' => $goods->id,
                    'sub_id' => $sub->id,
                    'goods_name' => $goods->gd_name . ' [' . $sub->name . ']',
                    'unit_price' => $discountedPrice,
                    'quantity' => $item['quantity'],
                    'subtotal' => $subtotal,
                    'type' => $goods->type,
                    'info' => $infoHtml
                ];
            }

            $payway = cache()->remember("pay_method_{$validated['payway']}", 43200, function () use ($validated) {
                return Pay::find($validated['payway']);
            });
            if (!$payway?->enable) {
                throw new RuleValidationException('支付方式无效');
            }

            if ($payway->china_only) {
                $isoCode = getIpCountry($request->getClientIp());
                if($isoCode != 'CN') {
                    throw new RuleValidationException(__('dujiaoka.prompt.payment_china_only'));
                }
            }

            // 计算折扣金额
            $originalTotalPrice = $totalPrice / $userDiscountRate;
            $userDiscountAmount = $originalTotalPrice - $totalPrice;
            
            // 处理余额支付
            $balanceUsed = 0;
            $useBalance = $validated['use_balance'] ?? false;
            $paymentMethod = Order::PAYMENT_ONLINE;
            
            if ($useBalance && $user && $user->balance > 0) {
                $balanceAmount = min($user->balance, $totalPrice);
                if (isset($validated['balance_amount']) && $validated['balance_amount'] > 0) {
                    $balanceAmount = min($validated['balance_amount'], $user->balance, $totalPrice);
                }
                
                if ($balanceAmount > 0) {
                    $balanceUsed = $balanceAmount;
                    $totalPrice -= $balanceAmount;
                    
                    if ($totalPrice <= 0) {
                        $paymentMethod = Order::PAYMENT_BALANCE;
                        $totalPrice = 0;
                    } else {
                        $paymentMethod = Order::PAYMENT_MIXED;
                    }
                }
            }

            $order = Order::create([
                'order_sn' => $orderSn,
                'user_id' => $userId,
                'email' => $validated['email'],
                'total_price' => $originalTotalPrice,
                'actual_price' => $totalPrice,
                'paid_price' => 0,
                'coupon_discount_price' => 0, // 暂时不支持优惠券
                'user_discount_rate' => $userDiscountRate,
                'user_discount_amount' => $userDiscountAmount,
                'payment_method' => $paymentMethod,
                'balance_used' => $balanceUsed,
                'status' => $totalPrice <= 0 ? Order::STATUS_PENDING : Order::STATUS_WAIT_PAY,
                'pay_id' => $validated['payway'],
                'search_pwd' => $validated['search_pwd'] ?? '',
                'buy_ip' => $request->getClientIp(),
            ]);
            
            // 如果使用了余额，扣除用户余额
            if ($balanceUsed > 0 && $user) {
                $user->deductBalance($balanceUsed, 'consume', '订单消费', $orderSn);
            }

            // 创建订单项
            foreach ($orderItems as $itemData) {
                $order->orderItems()->create($itemData);
            }

            // 如果是下单即减库存模式，原子检查+锁定库存
            if ($stockMode == 1) {
                foreach ($cartItems as $item) {
                    $sub = \App\Models\GoodsSub::find($item['sub_id']);
                    $goods = \App\Models\Goods::find($item['goods_id']);
                    $actualStock = $goods && $goods->type == \App\Models\Goods::AUTOMATIC_DELIVERY
                        ? \App\Models\Carmis::where('goods_id', $item['goods_id'])->where('sub_id', $item['sub_id'])->where('status', 1)->count()
                        : ($sub ? $sub->stock : 0);
                    if (!CacheManager::checkAndLockStock($item['sub_id'], $item['quantity'], $actualStock, $orderSn)) {
                        throw new RuleValidationException("库存不足，请重试");
                    }
                }
            }

            DB::commit();

            // 事务提交后执行非DB逻辑，避免假rollback
            $this->queueCookie($order->order_sn);

            // 调度过期任务（仅待支付订单需要）
            if ($paymentMethod !== Order::PAYMENT_BALANCE) {
                $expiredMinutes = cfg('order_expire_time', 5);
                OrderExpired::dispatch($orderSn)->delay(Carbon::now()->addMinutes($expiredMinutes));
            }

            // 全余额支付：立即履约（事务外独立执行）
            if ($paymentMethod == Order::PAYMENT_BALANCE) {
                try {
                    $this->orderProcessService->completedOrderByBalance($orderSn);
                } catch (\Exception $e) {
                    // 履约失败：补偿退还余额，重置订单为异常状态
                    DB::transaction(function () use ($order, $balanceUsed, $user, $orderSn, $e) {
                        $order->status = Order::STATUS_ABNORMAL;
                        $order->save();
                        if ($balanceUsed > 0 && $user) {
                            $user->addBalance($balanceUsed, 'refund', '余额支付履约失败退款', $orderSn);
                        }
                    });
                    \Illuminate\Support\Facades\Log::error('余额支付履约失败，已退款', [
                        'order_sn' => $orderSn,
                        'error'    => $e->getMessage(),
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => '支付处理失败，余额已退还，请重试',
                    ]);
                }
            }

            $redirectUrl = url('/order/bill/' . $order->order_sn);
            if (!$user) {
                $pwd = !empty($validated['search_pwd']) ? $validated['search_pwd'] : '__owner__';
                session(['order_search_pwd_' . $order->order_sn => $pwd]);
            }

            return response()->json([
                'success' => true,
                'redirect' => $redirectUrl
            ]);

        } catch (RuleValidationException $exception) {
            DB::rollBack();
            // 如果是下单即减库存模式，释放可能已锁定的库存
            if (isset($stockMode) && $stockMode == 1 && isset($orderSn)) {
                CacheManager::unlockStock($orderSn);
            }
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage()
            ]);
        } catch (\Exception $exception) {
            DB::rollBack();
            // 如果是下单即减库存模式，释放可能已锁定的库存
            if (isset($stockMode) && $stockMode == 1 && isset($orderSn)) {
                CacheManager::unlockStock($orderSn);
            }
            \Log::error('订单创建失败', [
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
                'file' => $exception->getFile() . ':' . $exception->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'message' => '订单创建失败，请重试',
            ]);
        }
    }

    /**
     * 设置订单cookie.
     * @param string $orderSN 订单号.
     */
    private function queueCookie(string $orderSN) : void
    {
        $cookies = Cookie::get('dujiaoka_orders');
        $list = empty($cookies) ? [] : (json_decode($cookies, true) ?: []);
        if (!in_array($orderSN, $list, true)) {
            $list[] = $orderSN;
        }
        $list = array_slice($list, -50);
        Cookie::queue('dujiaoka_orders', json_encode($list));
    }

    /**
     * 结账
     */
    public function bill(string $orderSN)
    {
        $order = Order::with(['orderItems', 'pay'])->where('order_sn', $orderSN)->first();

        if (empty($order)) {
            return $this->err(__('dujiaoka.prompt.order_does_not_exist'));
        }

        // 鉴权：复用 detailOrderSN 的归属校验逻辑
        $user = Auth::guard('web')->user();
        if ($user) {
            // 登录用户：游客单或他人单一律拒绝
            if ($order->user_id === null || $order->user_id !== $user->id) {
                return $this->err(__('dujiaoka.prompt.order_does_not_exist'));
            }
        } else {
            if (!session('order_search_pwd_' . $orderSN)) {
                return $this->err(__('dujiaoka.prompt.server_illegal_request'));
            }
            if (cfg('is_open_search_pwd', \App\Models\BaseModel::STATUS_CLOSE) == \App\Models\BaseModel::STATUS_OPEN) {
                $inputPwd = session('order_search_pwd_' . $orderSN, '');
                if (empty($inputPwd) || $inputPwd !== $order->search_pwd) {
                    return $this->err(__('dujiaoka.prompt.server_illegal_request'));
                }
            }
        }

        if ($order->status !== Order::STATUS_WAIT_PAY) {
            return redirect(url('/order/detail/' . $orderSN));
        }

        $data = [
            'orderItems'              => $order->orderItems,
            'order_sn'                => $order->order_sn,
            'email'                   => $order->email,
            'actual_price'            => $order->actual_price,
            'total_price'             => $order->total_price,
            'created_at'              => $order->created_at->format('Y-m-d H:i:s'),
            'pay'                     => $order->pay,
            'type'                    => $order->orderItems->first()->type ?? 1,
            'coupon_discount_price'   => $order->coupon_discount_price ?? 0,
            'wholesale_discount_price'=> 0,
            'coupon'                  => null,
        ];

        return $this->render('static_pages/bill', $data, __('dujiaoka.page-title.bill'));
    }


    /**
     * 订单状态监测
     *
     * @param string $orderSN 订单号
     * @return \Illuminate\Http\JsonResponse
     *
     */
    public function checkOrderStatus(string $orderSN)
    {
        $order = $this->orderService->detailOrderSN($orderSN);
        // 订单不存在或者已经过期
        if (!$order || $order->status == Order::STATUS_EXPIRED) {
            return response()->json(['msg' => 'expired', 'code' => 400001]);
        }

        // 归属校验：复用详情页鉴权逻辑，防止任意订单号探测
        $user = Auth::guard('web')->user();
        if ($user) {
            if ($order->user_id === null || $order->user_id !== $user->id) {
                return response()->json(['msg' => 'forbidden', 'code' => 403]);
            }
        } else {
            if (!session('order_search_pwd_' . $orderSN)) {
                return response()->json(['msg' => 'forbidden', 'code' => 403]);
            }
            if (cfg('is_open_search_pwd', \App\Models\BaseModel::STATUS_CLOSE) == \App\Models\BaseModel::STATUS_OPEN) {
                $inputPwd = session('order_search_pwd_' . $orderSN, '');
                if (empty($inputPwd) || $inputPwd !== $order->search_pwd) {
                    return response()->json(['msg' => 'forbidden', 'code' => 403]);
                }
            }
        }

        // 订单已经支付
        if ($order->status == Order::STATUS_WAIT_PAY) {
            return response()->json(['msg' => 'wait....', 'code' => 400000]);
        }
        // 处理中（人工/代充待处理）
        if (in_array($order->status, [Order::STATUS_PENDING, Order::STATUS_PROCESSING])) {
            return response()->json(['msg' => 'processing', 'code' => 202]);
        }
        // 失败或异常
        if (in_array($order->status, [Order::STATUS_FAILURE, Order::STATUS_ABNORMAL])) {
            return response()->json(['msg' => 'failed', 'code' => 400002, 'status' => $order->status]);
        }
        // 成功
        if ($order->status === Order::STATUS_COMPLETED) {
            return response()->json(['msg' => 'success', 'code' => 200]);
        }
        // 兜底（未知状态）
        return response()->json(['msg' => 'unknown', 'code' => 400003]);
    }

    /**
     * 通过订单号展示订单详情
     *
     * @param string $orderSN 订单号.
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\View\View
     *
     */
    public function detailOrderSN(string $orderSN)
    {
        $order = $this->orderService->detailOrderSN($orderSN);
        if (!$order) {
            return $this->err(__('dujiaoka.prompt.order_does_not_exist'));
        }

        $user = Auth::guard('web')->user();

        // 已登录用户：只能查看自己的订单；游客单（user_id=null）不属于任何登录用户
        if ($user) {
            if ($order->user_id === null || $order->user_id !== $user->id) {
                return $this->err(__('dujiaoka.prompt.order_does_not_exist'));
            }
        } else {
            if (!session('order_search_pwd_' . $orderSN)) {
                return $this->err(__('dujiaoka.prompt.server_illegal_request'));
            }
            if (cfg('is_open_search_pwd', \App\Models\BaseModel::STATUS_CLOSE) == \App\Models\BaseModel::STATUS_OPEN) {
                $inputPwd = session('order_search_pwd_' . $orderSN, '');
                if (empty($inputPwd) || $inputPwd !== $order->search_pwd) {
                    return $this->err(__('dujiaoka.prompt.server_illegal_request'));
                }
            }
        }

        return $this->render('static_pages/orderinfo', ['orders' => [$order]], __('dujiaoka.page-title.order-detail'));
    }

    /**
     * 订单号查询
     *
     * @param Request $request
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\View\View
     *
     */
    public function searchOrderBySN(Request $request)
    {
        $request->validate([
            'order_sn' => 'required|string|max:64|alpha_num',
        ]);
        return $this->detailOrderSN($request->input('order_sn'));
    }

    /**
     * 通过邮箱查询
     *
     * @param Request $request
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\View\View
     *
     */
    public function searchOrderByEmail(Request $request)
    {
        $request->validate([
            'email' => 'required|email|max:255',
            'search_pwd' => 'nullable|string|max:64',
        ]);

        $user = Auth::guard('web')->user();

        if ($user) {
            // 登录态：只允许查自己的邮箱
            if ($request->input('email') !== $user->email) {
                return $this->err(__('dujiaoka.prompt.no_related_order_found'));
            }
            $orders = $this->orderService->withEmailAndPassword($request->input('email'));
        } else {
            // 游客：始终要求 search_pwd（防止仅凭邮箱泄露订单隐私）
            if (!$request->filled('search_pwd')) {
                return $this->err(__('dujiaoka.prompt.server_illegal_request'));
            }
            $searchPwd = $request->input('search_pwd', '');
            $orders = $this->orderService->withEmailAndPassword($request->input('email'), $searchPwd);

            foreach ($orders as $o) {
                session(['order_search_pwd_' . $o->order_sn => $searchPwd]);
            }
        }

        if ($orders->isEmpty()) {
            return $this->err(__('dujiaoka.prompt.no_related_order_found'));
        }
        return $this->render('static_pages/orderinfo', ['orders' => $orders], __('dujiaoka.page-title.order-detail'));
    }

    /**
     * 通过浏览器缓存查询
     * @param Request $request
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\View\View
     *
     */
    public function searchOrderByBrowser(Request $request)
    {
        $cookies = Cookie::get('dujiaoka_orders');
        if (empty($cookies)) {
            return $this->err(__('dujiaoka.prompt.no_related_order_found_for_cache'));
        }
        $orderSNS = json_decode($cookies, true);
        if (!is_array($orderSNS) || empty($orderSNS)) {
            return $this->err(__('dujiaoka.prompt.no_related_order_found_for_cache'));
        }

        $orderSNS = array_filter($orderSNS, fn($sn) => is_string($sn) && preg_match('/^[A-Za-z0-9]{1,64}$/', $sn));
        if (empty($orderSNS)) {
            return $this->err(__('dujiaoka.prompt.no_related_order_found_for_cache'));
        }

        $validSNS = array_filter($orderSNS, fn($sn) => session('order_search_pwd_' . $sn));
        if (empty($validSNS)) {
            return $this->err(__('dujiaoka.prompt.no_related_order_found_for_cache'));
        }

        $orders = $this->orderService->byOrderSNS($validSNS);
        return $this->render('static_pages/orderinfo', ['orders' => $orders], __('dujiaoka.page-title.order-detail'));
    }

    /**
     * 订单查询页
     *
     * @param Request $request
     * @return mixed
     *
     */
    public function orderSearch(Request $request)
    {
        return $this->render('static_pages/searchOrder', [], __('dujiaoka.page-title.order-search'));
    }

}
