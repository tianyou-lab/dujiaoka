<?php
/**
 * The file was created by Assimon.
 *
 * @author    assimon<ashang@utf8.hk>
 * @copyright assimon<ashang@utf8.hk>
 * @link      http://utf8.hk/
 */
use Illuminate\Support\Facades\Route;

// 支付网关入口
Route::get('pay/{driver}/{payway}/{orderSN}', 'UnifiedPaymentController@gateway')
    ->middleware('dujiaoka.pay_gate_way');

// 支付回调统一入口
Route::post('pay/{driver}/notify', 'UnifiedPaymentController@notify');
Route::get('pay/{driver}/return', 'UnifiedPaymentController@return');

// --------------------------------------------------------------------------
// 嵌入式 V免签 专用路由（App 与收银台）
//
// 所有 POST 接口均**免 CSRF**（安卓 App 无法携带 XSRF-TOKEN）。
// CSRF 豁免统一在 App\Http\Middleware\VerifyCsrfToken::$except 里声明。
// --------------------------------------------------------------------------

// 本站内置收银台（Blade 页面，供用户扫码）
Route::get('pay/vmq/cashier/{orderSN}', 'VmqApiController@cashier')
    ->name('pay.vmq.cashier');

// 收银台用的二维码图片接口（按订单生成）
Route::get('pay/vmq/qr/{orderSN}', 'VmqApiController@qr')
    ->name('pay.vmq.qr');

// 收银台用的监控端在线状态公开查询（无签名，仅返回在线/离线）
Route::get('pay/vmq/heart-public', 'VmqApiController@heartPublic');

// 兼容老 V免签 协议的 API 端点（第三方可从外部调用）
Route::match(['get', 'post'], 'createOrder', 'VmqApiController@createOrder');
Route::post('checkOrder',  'VmqApiController@checkOrder');
Route::post('getOrder',    'VmqApiController@getOrder');
Route::post('appHeart',    'VmqApiController@appHeart');
Route::post('appPush',     'VmqApiController@appPush');
Route::post('getState',    'VmqApiController@getState');
