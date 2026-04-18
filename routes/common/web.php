<?php
/**
 * The file was created by Assimon.
 *
 * @author    assimon<ashang@utf8.hk>
 * @copyright assimon<ashang@utf8.hk>
 * @link      http://utf8.hk/
 */
use Illuminate\Support\Facades\Route;


Route::middleware('dujiaoka.boot')->namespace('Home')->group(function () {
    // 首页和商品
    Route::get('/', 'HomeController@index')->name('home');
    Route::get('check-geetest', 'HomeController@geetest');
    Route::get('buy/{id}', 'HomeController@buy')->name('goods.show');
    
    // 购物车
    Route::prefix('cart')->controller('CartController')->group(function () {
        Route::get('/', 'index');
    });
    Route::get('/cart', 'CartController@index');
    
    // API路由
    Route::prefix('api')->middleware('throttle:60,1')->group(function () {
        Route::post('cart/validate', 'CartController@validateItem');
    });
    
    // 订单相关
    Route::prefix('order')->controller('OrderController')->group(function () {
        Route::post('create', 'createOrder')->middleware('throttle:15,1');
        Route::middleware('throttle:30,1')->group(function () {
            Route::get('bill/{orderSN}', 'bill');
            Route::get('detail/{orderSN}', 'detailOrderSN');
            Route::get('status/{orderSN}', 'checkOrderStatus');
        });
        Route::get('search', 'orderSearch');
        Route::middleware('throttle:10,1')->group(function () {
            Route::post('search/sn', 'searchOrderBySN');
            Route::post('search/email', 'searchOrderByEmail');
            Route::post('search/browser', 'searchOrderByBrowser');
        });
    });
    
    // 支付相关
    Route::prefix('pay')->controller('OrderController')->group(function () {
        Route::get('checkout/{orderSN}', 'bill')->name('pay.checkout');
    });
    
    // 文章
    Route::prefix('article')->controller('ArticleController')->group(function () {
        Route::get('/', 'listAll')->name('article.list');
        Route::get('{link}', 'show')->name('article.show');
    });
});

// 用户认证路由
Route::middleware(['dujiaoka.boot', 'throttle:10,1'])->namespace('Auth')->prefix('auth')->group(function () {
    Route::get('login', 'AuthController@showLogin')->name('login');
    Route::post('login', 'AuthController@login');
    Route::get('register', 'AuthController@showRegister')->name('register');
    Route::post('register', 'AuthController@register');
    Route::get('forgot-password', 'AuthController@showForgotPassword')->name('password.request');
    Route::post('forgot-password', 'AuthController@sendPasswordResetLink')->name('password.email');
    Route::get('reset-password/{token}', 'AuthController@showResetPassword')->name('password.reset');
    Route::post('reset-password', 'AuthController@resetPassword')->name('password.update');
    Route::post('logout', 'AuthController@logout')->name('logout');
});

// 邮箱验证路由：需要登录 + 限流
Route::middleware(['dujiaoka.boot', 'auth:web', 'throttle:6,1'])->namespace('Auth')->prefix('auth')->group(function () {
    Route::get('email/verify', 'AuthController@showVerifyNotice')->name('verification.notice');
    Route::post('email/resend', 'AuthController@resendVerification')->name('verification.resend');
});

// 邮箱验证点击链接：需要登录 + signed 签名校验
Route::middleware(['dujiaoka.boot', 'auth:web', 'signed', 'throttle:6,1'])->namespace('Auth')->prefix('auth')->group(function () {
    Route::get('email/verify/{id}/{hash}', 'AuthController@verify')->name('verification.verify');
});

// 用户中心路由
Route::middleware(['dujiaoka.boot', 'auth:web'])->namespace('User')->prefix('user')->group(function () {
    Route::get('center', 'UserCenterController@index')->name('user.center');
    Route::get('profile', 'UserCenterController@profile')->name('user.profile');
    Route::post('profile', 'UserCenterController@updateProfile');
    Route::get('change-password', 'UserCenterController@changePassword')->name('user.change-password');
    Route::post('change-password', 'UserCenterController@updatePassword');
    Route::get('orders', 'UserCenterController@orders')->name('user.orders');
    Route::get('orders/{orderSn}', 'UserCenterController@orderDetail')->name('user.order.detail');
    Route::get('balance', 'UserCenterController@balance')->name('user.balance');
    Route::get('level', 'UserCenterController@levelInfo')->name('user.level');

    // 充值操作需要邮箱验证
    Route::middleware('verified')->group(function () {
        Route::get('recharge', 'UserCenterController@recharge')->name('user.recharge');
        Route::post('recharge', 'UserCenterController@processRecharge');
    });
});

// 安装路由
Route::middleware('install.check')->group(function () {
    Route::get('/install', 'InstallController@index')->name('install.index');
    Route::post('/install', 'InstallController@install')->name('install.do');
});


