<?php
/**
 * The file was created by Assimon.
 *
 */


use App\Exceptions\AppException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use GeoIp2\Database\Reader;
use GeoIp2\Exception\AddressNotFoundException;

if (! function_exists('replaceMailTemplate')) {

    /**
     * 替换邮件模板（兼容两套语法）
     *
     * 支持的占位符：
     *   1) 新语法：{{site.name}} / {{order.id}} / {{customer.name}}
     *              {{order.amount | money}} / {{order.created_at | date}}
     *              {{#if customer.is_registered}} ... {{/if}}（支持 {{else}}）
     *   2) 老语法：{order_id} / {product_name} / {webname} ...（扁平 key）
     *
     * $data 支持两种形态：
     *   - 嵌套结构：['site' => [...], 'order' => [...], 'customer' => [...]]
     *   - 扁平结构：['order_id' => 'XX', 'product_name' => 'YY', ...]
     *     扁平时会自动映射到新的嵌套结构，尽量让老的 {{order.id}} 也能替上。
     *
     * @param mixed $template 模板，可以是数组、Emailtpl 模型或 ArrayAccess
     * @param array $data 替换数据
     * @return array|false
     */
    function replaceMailTemplate($template = [], $data = [])
    {
        if (!$template) {
            return false;
        }

        $tplName = '';
        $tplContent = '';
        if (is_array($template) || $template instanceof \ArrayAccess) {
            $tplName = (string)($template['tpl_name'] ?? '');
            $tplContent = (string)($template['tpl_content'] ?? '');
        } elseif (is_object($template)) {
            $tplName = (string)($template->tpl_name ?? '');
            $tplContent = (string)($template->tpl_content ?? '');
        }

        if ($tplName === '' && $tplContent === '') {
            return false;
        }

        $flat = is_array($data) ? $data : [];

        $hasNested = isset($flat['site']) && is_array($flat['site'])
            || isset($flat['order']) && is_array($flat['order'])
            || isset($flat['customer']) && is_array($flat['customer']);

        if ($hasNested) {
            $context = $flat;
        } else {
            $context = [
                'site' => [
                    'name' => $flat['webname'] ?? config('app.name'),
                    'url' => $flat['weburl'] ?? config('app.url'),
                    'email' => $flat['site_email'] ?? config('mail.from.address'),
                    'year' => date('Y'),
                ],
                'order' => [
                    'id' => $flat['order_id'] ?? ($flat['ord_id'] ?? ''),
                    'sn' => $flat['order_id'] ?? ($flat['order_sn'] ?? ''),
                    'amount' => $flat['ord_price'] ?? ($flat['amount'] ?? 0),
                    'quantity' => $flat['buy_amount'] ?? ($flat['quantity'] ?? 0),
                    'status' => $flat['ord_status'] ?? ($flat['status'] ?? ''),
                    'created_at' => $flat['created_at'] ?? '',
                    'title' => $flat['ord_title'] ?? ($flat['product_name'] ?? ''),
                    'goods_summary' => $flat['ord_title'] ?? ($flat['product_name'] ?? ''),
                    'info' => $flat['ord_info'] ?? '',
                    'is_paid' => isset($flat['is_paid']) ? (bool)$flat['is_paid'] : false,
                    'is_failed' => isset($flat['is_failed']) ? (bool)$flat['is_failed'] : false,
                ],
                'customer' => [
                    'email' => $flat['customer_email'] ?? ($flat['email'] ?? ''),
                    'name' => $flat['customer_name'] ?? '客户',
                    'is_registered' => isset($flat['is_registered']) ? (bool)$flat['is_registered'] : false,
                ],
                'product' => [
                    'name' => $flat['product_name'] ?? '',
                ],
            ];
        }

        $newTplName = \App\Services\EmailVariableResolver::resolve($tplName, $context);
        $newTplContent = \App\Services\EmailVariableResolver::resolve($tplContent, $context);

        if ($flat) {
            foreach ($flat as $key => $val) {
                if (is_array($val) || is_object($val)) {
                    continue;
                }
                $needle = '{' . $key . '}';
                if (strpos($newTplName, $needle) !== false || strpos($newTplContent, $needle) !== false) {
                    $safeVal = (string)$val;
                    $newTplName = str_replace($needle, $safeVal, $newTplName);
                    $newTplContent = str_replace($needle, $safeVal, $newTplContent);
                }
            }
        }

        return ['tpl_name' => $newTplName, 'tpl_content' => $newTplContent];
    }
}


if (! function_exists('cfg')) {
    function cfg(string $key, $default = null)
    {
       return app('App\\Services\\ConfigService')->get($key, $default);
    }
}

if (! function_exists('theme_config')) {
    function theme_config(string $key, $default = null)
    {
       return app('App\Services\ThemeService')->getConfig($key, $default);
    }
}

if (! function_exists('theme_asset')) {
    function theme_asset(string $path): string
    {
       return app('App\Services\ThemeService')->asset($path);
    }
}

if (! function_exists('current_theme')) {
    function current_theme(): string
    {
       return app('App\Services\ThemeService')->getCurrentTheme();
    }
}

if (! function_exists('shop_cfg')) {
    function shop_cfg(string $key, $default = null)
    {
        return app('App\Settings\ShopSettings')->{$key} ?? $default;
    }
}

if (! function_exists('theme_cfg')) {
    function theme_cfg(string $key, $default = null)
    {
        return app('App\Settings\ThemeSettings')->{$key} ?? $default;
    }
}


if (! function_exists('formatWholesalePrice')) {

    /**
     * 格式化批发价
     *
     * @param string $priceConfig 批发价配置
     * @return array|null
     *
     */
    function formatWholesalePrice(string $priceConfig): ?array
    {
        $waitArr = explode(PHP_EOL, $priceConfig);
        $formatData = [];
        foreach ($waitArr as $key => $val) {
            if ($val != "") {
                $explodeFormat = explode('=', cleanHtml($val));
                if (count($explodeFormat) != 2) {
                    return null;
                }
                $formatData[$key]['number'] = $explodeFormat[0];
                $formatData[$key]['price'] = $explodeFormat[1];
            }
        }
        usort($formatData, fn($a, $b) => (float)$a['number'] <=> (float)$b['number']);
        return $formatData;
    }
}

if (! function_exists('cleanHtml')) {

    /**
     * 去除html内容
     * @param string $str 需要去掉的字符串
     * @return string
     */
    function cleanHtml(string $str): string
    {
        $str = trim($str); //清除字符串两边的空格
        $str = preg_replace("/\t/", "", $str); //使用正则表达式替换内容，如：空格，换行，并将替换为空。
        $str = preg_replace("/\r\n/", "", $str);
        $str = preg_replace("/\r/", "", $str);
        $str = preg_replace("/\n/", "", $str);
        $str = preg_replace("/ /", "", $str);
        $str = preg_replace("/  /", "", $str);  //匹配html中的空格
        return trim($str); //返回字符串
    }
}

if (! function_exists('formatChargeInput')) {

    /**
     * 格式化代充框
     *
     * @param string $charge
     * @return array|null
     *
     */
    function formatChargeInput(string $charge): ?array
    {
        $inputArr = explode(PHP_EOL, $charge);
        $formatData = [];
        foreach ($inputArr as $key => $val) {
            if ($val != "") {
                $explodeFormat = explode('=', cleanHtml($val));
                if (count($explodeFormat) != 3) {
                    return null;
                }
                $formatData[$key]['field'] = $explodeFormat[0];
                $formatData[$key]['desc'] = $explodeFormat[1];
                $formatData[$key]['rule'] = filter_var($explodeFormat[2], FILTER_VALIDATE_BOOLEAN);
            }
        }
        return $formatData;
    }
}

if (! function_exists('siteUrl')) {

    /**
     * 获取顶级域名 带协议
     * @return string
     */
    function siteUrl()
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        $domainName = $_SERVER['HTTP_HOST'] . '/';
        return $protocol . $domainName;
    }
}

if (! function_exists('md5SignQuery')) {

    function md5SignQuery(array $parameter, string $signKey)
    {
        ksort($parameter); //重新排序$data数组
        reset($parameter); //内部指针指向数组中的第一个元素
        $sign = '';
        $urls = '';
        foreach ($parameter as $key => $val) {
            if ($val == '') continue;
            if ($key != 'sign') {
                if ($sign != '') {
                    $sign .= "&";
                    $urls .= "&";
                }
                $sign .= "$key=$val"; //拼接为url参数形式
                $urls .= "$key=" . urlencode($val); //拼接为url参数形式
            }
        }
        $sign = md5($sign . $signKey);//密码追加进入开始MD5签名
        $query = $urls . '&sign=' . $sign; //创建订单所需的参数
        return $query;
    }
}

if (! function_exists('signQueryString')) {

    function signQueryString(array $data)
    {
        ksort($data); //排序post参数
        reset($data); //内部指针指向数组中的第一个元素
        $sign = ''; //加密字符串初始化
        foreach ($data as $key => $val) {
            if ($val == '' || $key == 'sign') continue; //跳过这些不签名
            if ($sign) $sign .= '&'; //第一个字符串签名不加& 其他加&连接起来参数
            $sign .= "$key=$val"; //拼接为url参数形式
        }
        return $sign;
    }
}

if (!function_exists('pictureUrl')) {

    /**
     * 生成前台图片链接 不存在使用默认图
     * 如果地址已经是完整URL了，则直接输出
     * @param string $file 图片地址
     * @param false $getHost 是否只获取图片前缀域名
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\UrlGenerator|string
     */
    function pictureUrl($file, $getHost = false)
    {
        if ($getHost) return Storage::disk('admin')->url('');
        if (Illuminate\Support\Facades\URL::isValidUrl($file)) return $file;
        $url = $file ? Storage::disk('admin')->url($file) : url('assets/common/images/default.jpg');
        return \App\Helpers\CdnHelper::asset($url);
    }
}

if (!function_exists('assocUnique')) {
    function assocUnique($arr, $key)
    {
        $tmp_arr = array();
        foreach ($arr as $k => $v) {
            if (in_array($v[$key], $tmp_arr)) {//搜索$v[$key]是否在$tmp_arr数组中存在，若存在返回true
                unset($arr[$k]);
            } else {
                $tmp_arr[] = $v[$key];
            }
        }
        sort($arr); //sort函数对数组进行排序
        return $arr;
    }
}

if (!function_exists('getIpCountry')) {
    function getIpCountry($ip) {
        // 仅在可信代理链下才信任 CF-IPCountry 头，防止伪造
        $cfCountry = request()->header('CF-IPCountry');
        if ($cfCountry && app('request')->isFromTrustedProxy()) {
            return strtoupper($cfCountry);
        }

        $mmdbPath = storage_path('app/library/GeoLite2-Country.mmdb');
        if (!file_exists($mmdbPath)) {
            return '';
        }

        try {
            $reader = new Reader($mmdbPath);
            return $reader->country($ip)->country->isoCode ?? '';
        } catch (AddressNotFoundException $e) {
            return '';
        } catch (\Exception $e) {
            return '';
        }
    }
}

if (!function_exists('currency_symbol')) {
    /**
     * 获取当前系统设置的货币符号
     *
     * @return string
     */
    function currency_symbol(): string
    {
        $currency = shop_cfg('currency', 'cny');
        
        $symbols = [
            'cny' => '¥',
            'usd' => '$',
        ];
        
        return $symbols[$currency] ?? '¥';
    }
}

if (!function_exists('asset')) {
    /**
     * 覆盖Laravel默认asset函数，支持CDN
     *
     * @param string $path 资源路径
     * @param bool|null $secure 是否HTTPS
     * @return string
     */
    function asset(string $path, ?bool $secure = null): string
    {
        $url = app('url')->asset($path, $secure);
        return \App\Helpers\CdnHelper::asset($url);
    }
}

if (!function_exists('purifyHtml')) {
    /**
     * 白名单净化 HTML，只保留安全标签和属性，移除 script/event handler 等。
     */
    function purifyHtml(?string $html): string
    {
        if (empty($html)) {
            return '';
        }

        $allowedTags = '<p><br><b><i><u><strong><em><a><ul><ol><li><h1><h2><h3><h4><h5><h6>'
            . '<table><thead><tbody><tr><th><td><img><div><span><blockquote><pre><code><hr><sub><sup><dl><dt><dd>';

        $cleaned = strip_tags($html, $allowedTags);

        $cleaned = preg_replace('/\s+on\w+\s*=\s*(["\']).*?\1/is', '', $cleaned);
        $cleaned = preg_replace('/\s+on\w+\s*=\s*[^\s>]*/is', '', $cleaned);

        $dangerousProtocols = '/\b(href|src|action|formaction|xlink:href|data)\s*=\s*(["\']?)\s*(javascript|data|vbscript)\s*:/is';
        $cleaned = preg_replace($dangerousProtocols, '$1=$2#', $cleaned);

        $decoded = html_entity_decode($cleaned, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('/\bon\w+\s*=/i', $decoded) ||
            preg_match('/\b(href|src|action|formaction)\s*=\s*["\']?\s*(javascript|data|vbscript)\s*:/i', $decoded)) {
            return e($html);
        }

        $cleaned = preg_replace('/<(style|object|embed|applet|form|input|textarea|select|button|meta|link|base|iframe|frame|frameset)\b[^>]*>.*?<\/\1>/is', '', $cleaned);
        $cleaned = preg_replace('/<(style|object|embed|applet|form|input|textarea|select|button|meta|link|base|iframe|frame|frameset)\b[^>]*\/?>/is', '', $cleaned);

        return $cleaned;
    }
}

if (!function_exists('safe_url')) {
    /**
     * 过滤 URL，只允许 http/https 和相对路径，阻止 javascript:/data: 等危险 scheme。
     */
    function safe_url(?string $url): string
    {
        if ($url === null || $url === '') {
            return '#';
        }
        $url = trim($url);
        $decoded = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalized = preg_replace('/[\x00-\x1f\x7f]+/', '', $decoded);
        if (preg_match('#^\s*(javascript|data|vbscript)\s*:#i', $normalized)) {
            return '#';
        }
        return $url;
    }
}