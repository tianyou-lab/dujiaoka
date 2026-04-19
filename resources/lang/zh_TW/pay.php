<?php

return [
    'labels' => [
        'Pay' => '支付通道',
        'pay' => '支付通道',
        'alipay_cert_section' => '支付寶證書（僅支付寶通道需要）',
    ],
    'fields' => [
        'merchant_id' => '商戶 ID',
        'merchant_key' => '商戶 KEY',
        'merchant_pem' => '商戶金鑰',
        'pay_check' => '支付標識',
        'pay_client' => '支付場景',
        'pay_handleroute' => '支付處理模組',
        'pay_method' => '支付方式',
        'pay_name' => '支付名稱',
        'is_open' => '是否啟用',
        'enable' => '是否啟用',
        'pay_fee' => '通道費率',
        'merchant_key_64' => '商戶密鑰(Base64)',
        'china_only' => '僅允許中國大陸下單',
        'method_jump' => '跳躍',
        'method_scan' => '掃碼',
        'pay_client_pc' => '計算機PC',
        'pay_client_mobile' => '行動電話',
        'pay_client_all' => '通用',
        'app_public_cert' => '應用公鑰證書 (appCertPublicKey_*.crt)',
        'alipay_public_cert' => '支付寶公鑰證書 (alipayCertPublicKey_RSA2.crt)',
        'alipay_root_cert' => '支付寶根證書 (alipayRootCert.crt)',
    ],
    'options' => [
    ],
    'helps' => [
        'merchant_key' => '支付寶場景：舊版「公鑰模式」欄位，v3 已不再使用，可留空。其他通道按各自要求填寫。',
        'merchant_pem' => '支付寶場景：填【應用私鑰】字串（不要帶 BEGIN/END 行）。其他通道按各自要求填寫。',
        'alipay_cert_section' => '支付寶當面付/網頁/WAP 必須使用「證書模式」。請到 https://open.alipay.com 下載 3 個 .crt 證書文件，用記事本打開後將完整內容（含 -----BEGIN/END CERTIFICATE-----）貼到下面對應的輸入框。其他通道（微信、TokenPay、Epusdt 等）可忽略此區域。',
        'app_public_cert' => '在支付寶開放平台 → 應用詳情 → 開發設置 → 介面加簽方式 中下載，檔名形如 appCertPublicKey_2021xxxxxxxxxxxx.crt',
        'alipay_public_cert' => '同一處下載，檔名形如 alipayCertPublicKey_RSA2.crt',
        'alipay_root_cert' => '同一處下載，檔名形如 alipayRootCert.crt',
    ],
];
