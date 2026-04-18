<?php
/**
 * The file was created by Assimon.
 *
 */

namespace App\Services;

use App\Exceptions\RuleValidationException;
use App\Jobs\ApiHook;
use App\Jobs\MailSend;
use App\Jobs\OrderExpired;
use App\Jobs\ServerJiang;
use App\Jobs\TelegramPush;
use App\Jobs\BarkPush;
use App\Jobs\WorkWeiXinPush;
use App\Models\BaseModel;
use App\Models\Coupon;
use App\Models\Goods;
use App\Models\GoodsSub;
use App\Models\Carmis;
use App\Models\Order;
use App\Models\User;
use App\Services\CacheManager;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 订单处理层
 *
 * Class OrderProcessService
 * @package App\Service
 * @author: Assimon
 * @email: Ashang@utf8.hk
 * @blog: https://utf8.hk
 * Date: 2021/5/30
 */
class OrderProcess
{

    const PENDING_CACHE_KEY = 'PENDING_ORDERS_LIST';

    /**
     * 优惠码服务层
     * @var \App\Services\Coupons
     */
    private $couponService;

    /**
     * 订单服务层
     * @var \App\Services\Orders
     */
    private $orderService;

    /**
     * 卡密服务层
     * @var \App\Services\Cards
     */
    private $carmisService;

    /**
     * 邮件服务层
     * @var \App\Service\EmailtplService
     */
    private $emailtplService;

    /**
     * 商品服务层.
     * @var \App\Services\Shop
     */
    private $goodsService;

    /**
     * 支付服务层
     * @var \App\Services\Payment
     */
    private $payService;

    /**
     * 商品
     * @var Goods
     */
    private $goods;

    /**
     * 优惠码
     * @var Coupon;
     */
    private $coupon;

    /**
     * 其他输入框
     * @var string
     */
    private $otherIpt;

    /**
     * 购买数量
     * @var int
     */
    private $buyAmount;

    /**
     * 购买邮箱
     * @var string
     */
    private $email;

    /**
     * 查询密码
     * @var string
     */
    private $searchPwd;

    /**
     * 下单IP
     * @var string
     */
    private $buyIP;

    /**
     * 支付方式
     * @var int
     */
    private $payID;
    
    /**
     * 预选的卡密ID
     * @var int
     */
    private $carmiID;
    
    /**
     * 商品规格ID
     * @var int
     */
    private $sub_id;

    public function __construct()
    {
        $this->couponService = app('App\Services\Coupons');
        $this->orderService = app('App\Services\Orders');
        $this->carmisService = app('App\Services\Cards');
        $this->emailtplService = app('App\Services\Email');
        $this->goodsService = app('App\Services\Shop');
        $this->payService = app('App\Services\Payment');

    }

    /**
     * 设置支付方式
     * @param int $payID
     */
    public function setPayID(int $payID): void
    {
        $this->payID = $payID;
    }



    /**
     * 下单ip
     * @param mixed $buyIP
     */
    public function setBuyIP($buyIP): void
    {
        $this->buyIP = $buyIP;
    }

    /**
     * 设置查询密码
     * @param mixed $searchPwd
     */
    public function setSearchPwd($searchPwd): void
    {
        $this->searchPwd = $searchPwd;
    }

    /**
     * 设置购买数量
     * @param mixed $buyAmount
     */
    public function setBuyAmount($buyAmount): void
    {
        $this->buyAmount = $buyAmount;
    }

    /**
     * 设置下单邮箱
     * @param mixed $email
     */
    public function setEmail($email): void
    {
        $this->email = $email;
    }
    
    /**
     * 设置预选卡密ID
     * @param int $id
     */
    public function setCarmi(int $id): void
    {
        $this->carmiID = $id;
    }

    /**
     * 设置商品
     *
     * @param Goods $goods
     *
     */
    public function setGoods(Goods $goods)
    {
        $this->goods = $goods;
    }
    
    /**
     * 设置商品规格ID
     *
     * @param int $sub_id
     */
    public function setSubID($sub_id)
    {
        $this->sub_id = $sub_id;
    }

    /**
     * 设置优惠码.
     *
     * @param ?Coupon $coupon
     *
     */
    public function setCoupon(?Coupon $coupon)
    {
        $this->coupon = $coupon;
    }

    /**
     * 其他输入框设置.
     *
     * @param ?string $otherIpt
     *
     */
    public function setOtherIpt(?string $otherIpt)
    {
        $this->otherIpt = $otherIpt;
    }

    /**
     * 计算优惠码价格
     *
     * @return float
     *
     */
    private function calculateTheCouponPrice(): float
    {
        $couponPrice = 0;
        // 优惠码优惠价格
        if ($this->coupon) {
            switch($this->coupon->type){
                case Coupon::TYPE_FIXED:
                    $couponPrice =  $this->coupon->discount;
                    break;
                case Coupon::TYPE_PERCENT:
                    $totalPrice = $this->calculateTheTotalPrice(); // 总价
                    $couponPrice = $totalPrice - bcmul($totalPrice, $this->coupon->discount, 2); //计算折扣
                    break;
                case Coupon::TYPE_EACH:
                    $couponPrice = bcmul($this->coupon->discount, $this->buyAmount, 2);
                    break;
            }
        }
        return $couponPrice;
    }

    /**
     * 计算批发优惠
     * @return float
     *
     */
    private function calculateTheWholesalePrice(): float
    {
        // 优惠码与批发价不叠加
        if($this->coupon)
            return 0;
        $wholesalePrice = 0; // 优惠单价
        $wholesaleTotalPrice = 0; // 优惠总价
        if ($this->goods->wholesale_price_cnf) {
            $formatWholesalePrice = formatWholesalePrice($this->goods->wholesale_price_cnf);
            foreach ($formatWholesalePrice as $item) {
                if ($this->buyAmount >= $item['number']) {
                    $wholesalePrice = $item['price'];
                }
            }
        }
        if ($wholesalePrice > 0 ) {
            $totalPrice = $this->calculateTheTotalPrice(); // 实际原总价
            $newTotalPrice = bcmul($wholesalePrice, $this->buyAmount, 2); // 批发价优惠后的总价
            $wholesaleTotalPrice = bcsub($totalPrice, $newTotalPrice, 2); // 批发总优惠
        }
        return $wholesaleTotalPrice;
    }

    /**
     * 订单总价
     * @return float
     *
     */
    private function calculateTheTotalPrice(): float
    {
        $price = $this->goods->price;
        
        // 如果预选了卡密，则加上预选加价
        if($this->carmiID)
            $price+=$this->goods->preselection;
            
        return bcmul($price, $this->buyAmount, 2);
    }
    
    /**
     * 计算支付通道手续费
     * @return float
     *
     * @author    outtime<i@treeo.cn>
     * @copyright outtime<i@treeo.cn>
     * @link      https://outti.me
     */
    private function calculateThePayFee(float $price): float
    {
        $feeRate = $this->payService->detail($this->payID)->pay_fee;
        if(!$price || $feeRate == 0) return 0;
        $raw = bcdiv((string)ceil((float)bcmul((string)$feeRate, (string)$price, 4)), '100', 2);
        return max(0.01, (float)$raw);
    }

    /**
     * 计算实际需要支付的价格
     *
     * @param float $totalPrice 总价
     * @param float $couponPrice 优惠码优惠价
     * @param float $wholesalePrice 批发优惠
     * @return float
     *
     */
    private function calculateTheActualPrice(float $totalPrice, float $couponPrice, float $wholesalePrice): float
    {
        $actualPrice = bcsub($totalPrice, $couponPrice, 2);
        $actualPrice = bcsub($actualPrice, $wholesalePrice, 2);
        if ($actualPrice <= 0) {
            $actualPrice = 0;
        }
        $actualPrice+=$this->calculateThePayFee($actualPrice);
        return $actualPrice;
    }

    /**
     * 余额/零元内部履约入口，只接受 PENDING 状态（余额支付下单时已设为 PENDING）。
     * 第三方回调走 completedOrder()。
     */
    public function completedOrderByBalance(string $orderSN): Order
    {
        DB::beginTransaction();
        try {
            $order = Order::with(['orderItems.goods', 'pay', 'user'])
                ->where('order_sn', $orderSN)
                ->lockForUpdate()
                ->first();

            if (!$order) {
                throw new \Exception(__('dujiaoka.prompt.order_status_completed'));
            }

            // 幂等：只允许 PENDING 进入（余额支付专属状态）
            if ($order->status !== Order::STATUS_PENDING) {
                DB::commit();
                return $order;
            }

            $pushed = Order::where('order_sn', $orderSN)
                ->where('status', Order::STATUS_PENDING)
                ->update(['status' => Order::STATUS_PROCESSING]);

            if (!$pushed) {
                DB::commit();
                $order->refresh();
                return $order;
            }

            $order->refresh();
            $this->fulfillOrder($order);

            DB::commit();
            return $order;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 外部第三方回调专用入口，只接受 WAIT_PAY 状态。
     * 余额支付走 completedOrderByBalance()，不走此方法。
     */
    public function completedOrder(string $orderSN, float $actualPrice, string $tradeNo = '')
    {
        DB::beginTransaction();
        try {
            $order = Order::with(['orderItems.goods', 'pay', 'user'])
                ->where('order_sn', $orderSN)
                ->lockForUpdate()
                ->first();

            if (!$order) {
                throw new \Exception(__('dujiaoka.prompt.order_status_completed'));
            }

            // 晚到回调：订单已过期但第三方已扣款，标记异常供人工复核
            if ($order->status === Order::STATUS_EXPIRED && $actualPrice > 0) {
                $order->status    = Order::STATUS_ABNORMAL;
                $order->trade_no  = $tradeNo ?: '';
                $order->paid_price = $actualPrice;
                $order->save();

                // 混合支付：过期时已退回余额，晚到回调需幂等冲回，防止账务失衡
                if ($order->payment_method === Order::PAYMENT_MIXED
                    && $order->balance_used > 0
                    && $order->user_id
                ) {
                    $chargebackKey = 'chargeback_' . $order->order_sn;
                    $alreadyRefunded = \App\Models\UserBalanceRecord::where('user_id', $order->user_id)
                        ->where('type', 'refund')
                        ->where('related_order_sn', $order->order_sn)
                        ->exists();
                    $alreadyCharged = \App\Models\UserBalanceRecord::where('user_id', $order->user_id)
                        ->where('type', 'consume')
                        ->where('related_order_sn', $chargebackKey)
                        ->exists();
                    if ($alreadyRefunded && !$alreadyCharged) {
                        $user = User::find($order->user_id);
                        if ($user) {
                            $user->deductBalance(
                                $order->balance_used,
                                'consume',
                                '混合支付晚到回调冲回已退余额',
                                $chargebackKey
                            );
                        }
                    }
                }

                \Illuminate\Support\Facades\Log::warning('订单已过期但收到第三方付款回调，已标记为异常待人工复核', [
                    'order_sn'   => $orderSN,
                    'paid_price' => $actualPrice,
                    'trade_no'   => $tradeNo,
                ]);
                DB::commit();
                return $order;
            }

            // 幂等：外部回调只允许从 WAIT_PAY 推进；PENDING 是余额支付内部状态，不在此处理
            if ($order->status !== Order::STATUS_WAIT_PAY) {
                DB::commit();
                return $order;
            }

            // 回调金额与订单应付金额一致性校验（允许 ±0.01 误差）
            if ($actualPrice > 0 && abs($actualPrice - (float)$order->actual_price) > 0.01) {
                \Illuminate\Support\Facades\Log::warning('支付金额不一致', [
                    'order_sn'     => $orderSN,
                    'expected'     => $order->actual_price,
                    'callback_got' => $actualPrice,
                ]);
                throw new \Exception('支付金额与订单金额不符，拒绝履约');
            }

            // CAS 原子推进状态，防止并发重复回调
            $pushed = Order::where('order_sn', $orderSN)
                ->where('status', Order::STATUS_WAIT_PAY)
                ->update([
                    'status'     => Order::STATUS_PROCESSING,
                    'trade_no'   => $tradeNo ?: '',
                    'paid_price' => $actualPrice > 0 ? $actualPrice : $order->actual_price,
                ]);

            if (!$pushed) {
                // 状态已被其他进程推进（含 OrderExpired），检查是否为晚到回调
                $order->refresh();
                if ($order->status === Order::STATUS_EXPIRED && $actualPrice > 0) {
                    $order->status    = Order::STATUS_ABNORMAL;
                    $order->trade_no  = $tradeNo ?: '';
                    $order->paid_price = $actualPrice;
                    $order->save();

                    // 混合支付：幂等冲回已退余额
                    if ($order->payment_method === Order::PAYMENT_MIXED
                        && $order->balance_used > 0
                        && $order->user_id
                    ) {
                        $chargebackKey = 'chargeback_' . $order->order_sn;
                        $alreadyRefunded = \App\Models\UserBalanceRecord::where('user_id', $order->user_id)
                            ->where('type', 'refund')
                            ->where('related_order_sn', $order->order_sn)
                            ->exists();
                        $alreadyCharged = \App\Models\UserBalanceRecord::where('user_id', $order->user_id)
                            ->where('type', 'consume')
                            ->where('related_order_sn', $chargebackKey)
                            ->exists();
                        if ($alreadyRefunded && !$alreadyCharged) {
                            $user = User::find($order->user_id);
                            if ($user) {
                                $user->deductBalance(
                                    $order->balance_used,
                                    'consume',
                                    '混合支付晚到回调冲回已退余额',
                                    $chargebackKey
                                );
                            }
                        }
                    }

                    \Illuminate\Support\Facades\Log::warning('订单已过期但收到第三方付款回调（CAS），已标记为异常待人工复核', [
                        'order_sn'   => $orderSN,
                        'paid_price' => $actualPrice,
                        'trade_no'   => $tradeNo,
                    ]);
                }
                DB::commit();
                return $order;
            }

            $order->refresh();

            $this->fulfillOrder($order);

            DB::commit();
            return $order;
        } catch (\Exception $exception) {
            DB::rollBack();
            throw new RuleValidationException($exception->getMessage());
        }
    }

    /**
     * 核心履约逻辑（库存扣减、发货、状态流转、通知），必须在事务内调用。
     * 所有 dispatch 使用 afterCommit，确保事务回滚时不会外发。
     */
    private function fulfillOrder(Order $order): void
    {
        // 库存处理
        $stockMode = cfg('stock_mode', 2);
        if ($stockMode == 1) {
            // 预占模式：解锁缓存锁，并实扣数据库库存
            CacheManager::unlockStock($order->order_sn);
            foreach ($order->orderItems as $orderItem) {
                // 自动发货商品库存即卡密数量，由 processAutoItem 取卡密时自然扣减，不操作 goods_sub.stock 原始列
                if ($orderItem->goods && $orderItem->goods->type === Goods::AUTOMATIC_DELIVERY) {
                    GoodsSub::where('id', $orderItem->sub_id)
                        ->increment('sales_volume', $orderItem->quantity);
                    continue;
                }
                $affected = GoodsSub::where('id', $orderItem->sub_id)
                    ->where('stock', '>=', $orderItem->quantity)
                    ->decrement('stock', $orderItem->quantity);
                if (!$affected) {
                    $order->status = Order::STATUS_ABNORMAL;
                    $order->save();
                    \Illuminate\Support\Facades\Log::error('预占模式库存扣减失败，订单标记为异常', [
                        'order_sn' => $order->order_sn,
                        'sub_id'   => $orderItem->sub_id,
                    ]);
                    return;
                }
                GoodsSub::where('id', $orderItem->sub_id)
                    ->increment('sales_volume', $orderItem->quantity);
            }
        }

        // 按 orderItem 粒度分发自动/人工，避免混合购物车整单误处理
        $allSucceeded = true;
        foreach ($order->orderItems as $orderItem) {
            if (!$orderItem->goods) {
                continue;
            }
            if ($orderItem->goods->type === Goods::AUTOMATIC_DELIVERY) {
                $result = $this->processAutoItem($order, $orderItem);
                if (!$result) {
                    $allSucceeded = false;
                }
            } else {
                $this->processManualItem($order, $orderItem);
            }
        }

        // 仅在全部自动发货成功时才标记完成；有手工商品则保持 PENDING
        $hasManual = $order->orderItems->contains(fn($i) => $i->goods && $i->goods->type !== Goods::AUTOMATIC_DELIVERY);
        if (!$hasManual && $allSucceeded) {
            $order->status = Order::STATUS_COMPLETED;
            $order->save();
        } elseif (!$hasManual && !$allSucceeded) {
            // 纯自动但有失败项，已在 processAutoItem 内标异常
            if ($order->balance_used > 0 && $order->user_id) {
                $user = User::find($order->user_id);
                if ($user) {
                    $user->addBalance($order->balance_used, 'refund', '自动发货缺货退款', $order->order_sn);
                }
            }
            if ($order->paid_price > 0) {
                \Illuminate\Support\Facades\Log::warning('自动发货缺货，第三方实付需人工退款', [
                    'order_sn'   => $order->order_sn,
                    'paid_price' => $order->paid_price,
                    'trade_no'   => $order->trade_no,
                ]);
            }
        } else {
            if (!$allSucceeded && $order->balance_used > 0 && $order->user_id) {
                $user = User::find($order->user_id);
                if ($user) {
                    $user->addBalance($order->balance_used, 'refund', '混合订单发货异常退款', $order->order_sn);
                }
            }
            if (!$allSucceeded && $order->paid_price > 0) {
                \Illuminate\Support\Facades\Log::warning('混合订单自动发货缺货，第三方实付需人工退款', [
                    'order_sn'   => $order->order_sn,
                    'paid_price' => $order->paid_price,
                    'trade_no'   => $order->trade_no,
                ]);
            }
            if ($order->status !== Order::STATUS_ABNORMAL) {
                $order->status = $allSucceeded ? Order::STATUS_PENDING : Order::STATUS_ABNORMAL;
                $order->save();
            }
        }

        // 仅在订单最终成功（COMPLETED 或 PENDING）时累计用户消费
        if (in_array($order->status, [Order::STATUS_COMPLETED, Order::STATUS_PENDING])) {
            $this->processUserLogic($order);
        }

        // 所有外发通知在事务提交后执行，防止回滚后脏外发
        $orderSnapshot = $order;
        DB::afterCommit(function () use ($orderSnapshot) {
            if (cfg('is_open_server_jiang', 0) == BaseModel::STATUS_OPEN) {
                ServerJiang::dispatch($orderSnapshot);
            }
            if (cfg('is_open_telegram_push', 0) == BaseModel::STATUS_OPEN) {
                TelegramPush::dispatch($orderSnapshot);
            }
            if (cfg('is_open_bark_push', 0) == BaseModel::STATUS_OPEN) {
                BarkPush::dispatch($orderSnapshot);
            }
            if (cfg('is_open_qywxbot_push', 0) == BaseModel::STATUS_OPEN) {
                WorkWeiXinPush::dispatch($orderSnapshot);
            }
            ApiHook::dispatch($orderSnapshot);
        });
    }

    /**
     * 处理单个自动发货 item，返回是否成功
     */
    private function processAutoItem(Order $order, $orderItem): bool
    {
        $carmis = $this->carmisService->takes($orderItem->goods_id, $orderItem->quantity, $orderItem->sub_id ?? 0);
        if (!$carmis || count($carmis) != $orderItem->quantity) {
            $orderItem->info = __('dujiaoka.prompt.order_carmis_insufficient_quantity_available');
            $orderItem->save();
            $order->status = Order::STATUS_ABNORMAL;
            $order->save();
            return false;
        }

        $carmisTexts = array_column($carmis, 'carmi');
        $ids = array_column($carmis, 'id');
        $orderItem->info = implode(PHP_EOL, $carmisTexts);
        $orderItem->save();
        $this->carmisService->soldByIDS($ids);

        // 发货邮件
        $mailData = [
            'product_name' => $orderItem->goods_name ?? '未知商品',
            'webname'      => cfg('text_logo', '独角数卡'),
            'weburl'       => config('app.url') ?? 'http://dujiaoka.com',
            'ord_info'     => implode('<br/>', $carmisTexts),
            'ord_title'    => $orderItem->goods_name ?? '未知商品',
            'order_id'     => $order->order_sn,
            'buy_amount'   => $orderItem->quantity,
            'ord_price'    => $order->actual_price,
        ];
        $tpl = $this->emailtplService->detailByToken('card_send_user_email');
        $mailBody = replaceMailTemplate($tpl, $mailData);
        if (filter_var($order->email, FILTER_VALIDATE_EMAIL)) {
            $email = $order->email;
            $tplName = $mailBody['tpl_name'];
            $tplContent = $mailBody['tpl_content'];
            DB::afterCommit(fn() => MailSend::dispatch($email, $tplName, $tplContent));
        }
        return true;
    }

    /**
     * 处理单个人工发货 item
     */
    private function processManualItem(Order $order, $orderItem): void
    {
        // 发货时减库存模式
        $stockMode = cfg('stock_mode', 2);
        if ($stockMode == 2) {
            $affected = GoodsSub::where('id', $orderItem->sub_id)
                ->where('stock', '>=', $orderItem->quantity)
                ->decrement('stock', $orderItem->quantity);
            if (!$affected) {
                // 不抛异常：第三方已扣款，只标记异常让人工处理，保证事务正常提交
                $order->status = Order::STATUS_ABNORMAL;
                $order->save();
                \Illuminate\Support\Facades\Log::error('人工商品库存扣减失败，订单标记为异常', [
                    'order_sn' => $order->order_sn,
                    'sub_id'   => $orderItem->sub_id,
                ]);
                return;
            }
            GoodsSub::where('id', $orderItem->sub_id)->increment('sales_volume', $orderItem->quantity);
        }

        // 通知管理员
        $mailData = [
            'product_name' => $orderItem->goods_name ?? '未知商品',
            'webname'      => cfg('text_logo', '独角数卡'),
            'weburl'       => config('app.url') ?? 'http://dujiaoka.com',
            'ord_info'     => str_replace(PHP_EOL, '<br/>', $orderItem->info ?? ''),
            'ord_title'    => $orderItem->goods_name ?? '未知商品',
            'order_id'     => $order->order_sn,
            'buy_amount'   => $orderItem->quantity,
            'ord_price'    => $order->actual_price,
            'created_at'   => $order->created_at,
        ];
        $tpl = $this->emailtplService->detailByToken('manual_send_manage_mail');
        $mailBody = replaceMailTemplate($tpl, $mailData);
        $manageMail = cfg('manage_email', '');
        $tplName = $mailBody['tpl_name'];
        $tplContent = $mailBody['tpl_content'];
        DB::afterCommit(fn() => MailSend::dispatch($manageMail, $tplName, $tplContent));
    }

    /**
     * 手动处理的订单（保留兼容，内部调用 processManualItem）.
     *
     * @param Order $order 订单
     * @return Order 订单
     *
     */
    public function processManual(Order $order)
    {
        // 设置订单为待处理
        $order->status = Order::STATUS_PENDING;
        $order->save();
        
        // 如果是发货时减库存模式，现在减库存
        $stockMode = cfg('stock_mode', 2);
        if ($stockMode == 2) {
            foreach ($order->orderItems as $orderItem) {
                $affected = GoodsSub::where('id', $orderItem->sub_id)
                    ->where('stock', '>=', $orderItem->quantity)
                    ->decrement('stock', $orderItem->quantity);
                if (!$affected) {
                    $order->status = Order::STATUS_ABNORMAL;
                    $order->save();
                    throw new \Exception("库存扣减失败，订单标记为异常: {$order->order_sn}");
                }
                GoodsSub::where('id', $orderItem->sub_id)->increment('sales_volume', $orderItem->quantity);
            }
        }
        // 邮件数据
        $mailData = [
            'product_name' => $order->orderItems->first()->goods_name ?? '未知商品',
            'webname' => cfg('text_logo', '独角数卡'),
            'weburl' => config('app.url') ?? 'http://dujiaoka.com',
            'ord_info' => str_replace(PHP_EOL, '<br/>', $order->orderItems->first()->info ?? ''),
            'ord_title' => $order->orderItems->first()->goods_name ?? '未知商品',
            'order_id' => $order->order_sn,
            'buy_amount' => $order->orderItems->sum('quantity'),
            'ord_price' => $order->actual_price,
            'created_at' => $order->created_at,
        ];
        $tpl = $this->emailtplService->detailByToken('manual_send_manage_mail');
        $mailBody = replaceMailTemplate($tpl, $mailData);
        $manageMail = cfg('manage_email', '');
        $tplName = $mailBody['tpl_name'];
        $tplContent = $mailBody['tpl_content'];
        // 邮件发送
        DB::afterCommit(fn() => MailSend::dispatch($manageMail, $tplName, $tplContent));
        return $order;
    }

    /**
     * @deprecated 已被 fulfillOrder + processAutoItem 取代，保留仅供向后兼容，不再被调用。
     */
    public function processAuto(Order $order): Order
    {
        $firstItem = $order->orderItems->first();
        if (!$firstItem) {
            $order->status = Order::STATUS_ABNORMAL;
            $order->save();
            return $order;
        }

        $carmisInfo = [];
        $ids = [];

        foreach ($order->orderItems as $item) {
            $carmis = $this->carmisService->takes($item->goods_id, $item->quantity, $item->sub_id ?? 0);
            if (!$carmis || count($carmis) != $item->quantity) {
                $item->info = __('dujiaoka.prompt.order_carmis_insufficient_quantity_available');
                $item->save();
                $order->status = Order::STATUS_ABNORMAL;
                $order->save();
                return $order;
            }
            $carmisInfo = array_merge($carmisInfo, array_column($carmis, 'carmi'));
            $ids = array_merge($ids, array_column($carmis, 'id'));
            $item->info = implode(PHP_EOL, array_column($carmis, 'carmi'));
            $item->save();
        }

        $order->status = Order::STATUS_COMPLETED;
        $order->save();
        // 将卡密设置为已售出
        $this->carmisService->soldByIDS($ids);
        // 邮件数据
        $mailData = [
            'product_name' => $firstItem->goods_name ?? '未知商品',
            'webname' => cfg('text_logo', '独角数卡'),
            'weburl' => config('app.url') ?? 'http://dujiaoka.com',
            'ord_info' => implode('<br/>', $carmisInfo),
            'ord_title' => $firstItem->goods_name ?? '未知商品',
            'order_id' => $order->order_sn,
            'buy_amount' => $order->orderItems->sum('quantity'),
            'ord_price' => $order->actual_price,
        ];
        $tpl = $this->emailtplService->detailByToken('card_send_user_email');
        $mailBody = replaceMailTemplate($tpl, $mailData);
        if (filter_var($order->email, FILTER_VALIDATE_EMAIL)) {
            MailSend::dispatch($order->email, $mailBody['tpl_name'], $mailBody['tpl_content']);
        }
        return $order;
    }

    /**
     * 处理用户相关逻辑
     * 
     * @param Order $order
     */
    private function processUserLogic(Order $order)
    {
        if (!$order->user_id) {
            return;
        }

        $user = User::find($order->user_id);
        if (!$user) {
            return;
        }

        $isRechargeOrder = $order->isRechargeOrder();
        
        if ($isRechargeOrder) {
            // 余额充值订单：增加用户余额
            $user->addBalance(
                $order->actual_price,
                'recharge',
                '余额充值',
                $order->order_sn
            );
        } else {
            // 普通订单：增加累计消费并检查等级升级
            $totalSpent = $order->total_price; // 使用原价计算累计消费
            $user->addTotalSpent($totalSpent);
        }
    }


}
