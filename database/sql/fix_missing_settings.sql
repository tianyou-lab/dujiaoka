-- ===========================================================
-- 修复 settings 表缺失行（解决"发件设置/系统设置 保存 500"）
-- 适用：已部署的旧库。settings 主键是 (group, name)，
-- INSERT IGNORE 不会覆盖你已配置好的现有数据。
-- 用法：
--   mysql -u<user> -p<pass> <db_name> < fix_missing_settings.sql
-- 或登录 mysql 后：source /var/www/dujiaoka/database/sql/fix_missing_settings.sql;
-- ===========================================================

START TRANSACTION;

-- ---------- system 组（之前只有 contact_required，缺 19 个） ----------
INSERT IGNORE INTO `settings` (`group`, `name`, `payload`, `locked`, `created_at`, `updated_at`) VALUES
('system', 'order_expire_time',      '5',       0, NOW(), NOW()),
('system', 'is_open_img_code',       'false',   0, NOW(), NOW()),
('system', 'order_ip_limits',        '3',       0, NOW(), NOW()),
('system', 'contact_required',       '"email"', 0, NOW(), NOW()),
('system', 'stock_mode',             '2',       0, NOW(), NOW()),
('system', 'is_open_server_jiang',   'false',   0, NOW(), NOW()),
('system', 'server_jiang_token',     'null',    0, NOW(), NOW()),
('system', 'is_open_telegram_push',  'false',   0, NOW(), NOW()),
('system', 'telegram_bot_token',     'null',    0, NOW(), NOW()),
('system', 'telegram_userid',        'null',    0, NOW(), NOW()),
('system', 'is_open_bark_push',      'false',   0, NOW(), NOW()),
('system', 'is_open_bark_push_url',  'false',   0, NOW(), NOW()),
('system', 'bark_server',            'null',    0, NOW(), NOW()),
('system', 'bark_token',             'null',    0, NOW(), NOW()),
('system', 'is_open_qywxbot_push',   'false',   0, NOW(), NOW()),
('system', 'qywxbot_key',            'null',    0, NOW(), NOW()),
('system', 'geetest_id',             'null',    0, NOW(), NOW()),
('system', 'geetest_key',            'null',    0, NOW(), NOW()),
('system', 'is_open_geetest',        'false',   0, NOW(), NOW()),
('system', 'cdn_url',                'null',    0, NOW(), NOW());

-- ---------- mail 组（之前完全没有，全部 9 个都缺） ----------
INSERT IGNORE INTO `settings` (`group`, `name`, `payload`, `locked`, `created_at`, `updated_at`) VALUES
('mail', 'driver',       '"smtp"',     0, NOW(), NOW()),
('mail', 'host',         'null',       0, NOW(), NOW()),
('mail', 'port',         '465',        0, NOW(), NOW()),
('mail', 'username',     'null',       0, NOW(), NOW()),
('mail', 'password',     'null',       0, NOW(), NOW()),
('mail', 'encryption',   '"ssl"',      0, NOW(), NOW()),
('mail', 'from_address', 'null',       0, NOW(), NOW()),
('mail', 'from_name',    '"启航数卡"',  0, NOW(), NOW()),
('mail', 'manage_email', 'null',       0, NOW(), NOW());

COMMIT;

-- 校验：执行后查看现在 settings 表里 mail/system 各有多少行
SELECT `group`, COUNT(*) AS rows_count
FROM `settings`
WHERE `group` IN ('system', 'mail', 'shop', 'theme')
GROUP BY `group`;
