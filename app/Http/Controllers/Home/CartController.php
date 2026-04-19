<?php

namespace App\Http\Controllers\Home;

use App\Http\Controllers\BaseController;
use App\Models\Goods;
use App\Models\GoodsSub;
use App\Models\Carmis;
use App\Models\Pay;
use Illuminate\Http\Request;

class CartController extends BaseController
{
    public function index()
    {
        return $this->render('static_pages/cart', [], '购物车');
    }

    public function validateItem(Request $request)
    {
        $params = $request->validate([
            'goods_id' => 'required|integer|min:1',
            'sub_id' => 'required|integer|min:1',
            'quantity' => 'required|integer|min:1',
        ]);
        $qty = (int) $params['quantity'];

        $goods = cache()->remember("goods_with_sub_{$params['goods_id']}", 21600, function () use ($params) {
            return Goods::with('goods_sub')->find($params['goods_id']);
        });
        
        if (!$goods?->is_open) {
            return $this->fail('商品不存在或已下架');
        }

        $sub = $goods->goods_sub()->find($params['sub_id']);
        if (!$sub) {
            return $this->fail('商品规格不存在');
        }

        $stock = $goods->type == Goods::AUTOMATIC_DELIVERY 
            ? Carmis::where('goods_id', $params['goods_id'])->where('sub_id', $params['sub_id'])->where('status', 1)->count()
            : $sub->stock;

        if ($qty > $stock) {
            return $this->fail("库存不足，当前库存：{$stock}");
        }

        if ($goods->buy_limit_num > 0 && $qty > $goods->buy_limit_num) {
            return $this->fail("超出限购数量：{$goods->buy_limit_num}");
        }

        if ($goods->buy_min_num > 0 && $qty < $goods->buy_min_num) {
            return $this->fail("最低购买数量：{$goods->buy_min_num}");
        }

        $enabledPays = cache()->remember('enabled_pay_methods', 43200, function () {
            return Pay::enabled()->get();
        });
        $payways = empty($goods->payment_limit) 
            ? $enabledPays->toArray()
            : $enabledPays->whereIn('id', $goods->payment_limit)->values()->toArray();

        $user = \Illuminate\Support\Facades\Auth::guard('web')->user();
        $unitPrice = (float) $sub->price;
        $originalPrice = $unitPrice;

        if ($user && $user->group_id) {
            $groupPrice = \App\Models\GoodsSubGroupPrice::where('sub_id', $sub->id)
                ->where('group_id', $user->group_id)
                ->value('price');
            if ($groupPrice !== null) {
                $unitPrice = (float) $groupPrice;
            } elseif ($user->discount_rate < 1) {
                $unitPrice = round($unitPrice * (float) $user->discount_rate, 2);
            }
        } elseif ($user && $user->discount_rate < 1) {
            $unitPrice = round($unitPrice * (float) $user->discount_rate, 2);
        }

        return $this->success([
            'goods_id' => $goods->id,
            'sub_id' => $sub->id,
            'name' => "{$goods->gd_name} [{$sub->name}]",
            'price' => $unitPrice,
            'original_price' => $originalPrice,
            'has_group_price' => $unitPrice < $originalPrice,
            'image' => pictureUrl($goods->picture),
            'stock' => $stock,
            'max_quantity' => min($stock, $goods->buy_limit_num ?: $stock),
            'min_quantity' => $goods->buy_min_num ?: 1,
            'payways' => $payways
        ]);
    }

    private function success($data = [])
    {
        return response()->json(['success' => true, 'data' => $data]);
    }

    private function fail($message)
    {
        return response()->json(['success' => false, 'message' => $message]);
    }

}