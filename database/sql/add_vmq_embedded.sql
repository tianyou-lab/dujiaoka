-- =============================================================================
-- 嵌入式 V免签 / VPay 支持（无需独立监控端）
-- 用法：在已有 dujiaoka 数据库中执行本脚本即可
-- 依赖：已有 `pays` 表，能通过 pay_handleroute='vmq' 与本套实现对接
--
-- 幂等保证：
--   - 所有 CREATE 均为 IF NOT EXISTS，重复执行不会丢数据
--   - INSERT 使用 INSERT IGNORE，已存在的设置项不会被重置
--   - 不会使用 DROP TABLE / TRUNCATE，服务器第二次执行本脚本安全
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. V免签 内部订单表：记录每一笔发起的 V免签 扫码支付
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `vmq_pay_orders` (
  `id`            int unsigned NOT NULL AUTO_INCREMENT,
  `order_sn`      varchar(64)  COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'dujiaoka 订单号（来自 orders.order_sn）',
  `vmq_order_id`  varchar(64)  COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'V免签 内部订单号（YmdHis+随机）',
  `pay_id`        int NOT NULL COMMENT '支付通道 pays.id',
  `type`          tinyint(1) NOT NULL COMMENT '支付类型：1=微信 2=支付宝',
  `price`         decimal(10,2) NOT NULL COMMENT '订单原价（元）',
  `really_price`  decimal(10,2) NOT NULL COMMENT '实际应付金额（金额错位后，元）',
  `pay_url`       text COLLATE utf8mb4_unicode_ci COMMENT '支付二维码 URL（可能为个人收款码链接或系统自动生成的跳转链接）',
  `is_auto`       tinyint(1) NOT NULL DEFAULT '1' COMMENT '1=系统自动生成二维码（金额错位） 0=固定金额收款码',
  `state`         tinyint(1) NOT NULL DEFAULT '0' COMMENT '订单状态：0=待支付 1=已支付 -1=已关闭/过期',
  `create_date`   int unsigned NOT NULL DEFAULT '0' COMMENT '创建时间（unix 秒）',
  `pay_date`      int unsigned NOT NULL DEFAULT '0' COMMENT '到账时间（unix 秒）',
  `close_date`    int unsigned NOT NULL DEFAULT '0' COMMENT '关闭时间（unix 秒）',
  `param`         varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '自定义参数（存 dujiaoka 订单号）',
  `created_at`    timestamp NULL DEFAULT NULL,
  `updated_at`    timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_order_sn` (`order_sn`),
  UNIQUE KEY `uniq_vmq_order_id` (`vmq_order_id`),
  KEY `idx_state_type_really` (`state`,`type`,`really_price`),
  KEY `idx_create_date` (`create_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='嵌入式 V免签 内部订单';

-- -----------------------------------------------------------------------------
-- 2. V免签 金额占用防冲突表：同一金额+同一支付类型仅允许一单待支付
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `vmq_tmp_prices` (
  `id`          int unsigned NOT NULL AUTO_INCREMENT,
  `price_key`   varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '金额锁 key，格式：realPriceFen-type，例如 1005-1',
  `vmq_order_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '关联的 vmq_pay_orders.vmq_order_id',
  `create_date` int unsigned NOT NULL COMMENT '创建时间（unix 秒）',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_price_key` (`price_key`),
  KEY `idx_vmq_order_id` (`vmq_order_id`),
  KEY `idx_create_date` (`create_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='V免签 金额占用锁';

-- -----------------------------------------------------------------------------
-- 3. V免签 全局设置表：通讯密钥、心跳、App 最近心跳/付款时间等
--    再次执行时通过 INSERT IGNORE 跳过已存在的 key，**绝不会覆盖已有密钥**
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `vmq_settings` (
  `id`          int unsigned NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(64)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_val` text         COLLATE utf8mb4_unicode_ci,
  `updated_at`  timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='V免签 全局设置';

INSERT IGNORE INTO `vmq_settings` (`setting_key`, `setting_val`, `updated_at`) VALUES
  ('key',               '',       NOW()),  -- 通讯密钥：32 位随机串，后台「V免签 全局设置」里维护
  ('close_minutes',     '10',     NOW()),  -- 订单超时自动关闭（分钟）
  ('pay_qf',            '1',      NOW()),  -- 金额错位方向：1=递增(+1分) 2=递减(-1分)
  ('heart_timeout',     '60',     NOW()),  -- 心跳超时（秒），超过则标记 App 离线
  ('last_heart',        '0',      NOW()),  -- App 最后一次心跳时间（unix 秒）
  ('last_pay',          '0',      NOW()),  -- App 最后一次推送到账时间（unix 秒）
  ('jk_state',          '0',      NOW()),  -- 监控 App 在线状态：1=在线 0=离线
  ('enable',            '1',      NOW());  -- V免签 全局开关：1=启用 0=停用

-- -----------------------------------------------------------------------------
-- 4. V免签 固定金额收款码表：给特定金额配置专用收款二维码
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `vmq_qrcodes` (
  `id`          int unsigned NOT NULL AUTO_INCREMENT,
  `type`        tinyint(1) NOT NULL COMMENT '1=微信 2=支付宝',
  `price`       decimal(10,2) NOT NULL COMMENT '对应金额（元）',
  `pay_url`     text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '扫码支付链接（或收款二维码原始内容）',
  `image_path`  varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '收款码图片存储路径（可选）',
  `enable`      tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否启用',
  `remark`      varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '备注',
  `created_at`  timestamp NULL DEFAULT NULL,
  `updated_at`  timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_type_price` (`type`,`price`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='V免签 固定金额收款码';

-- -----------------------------------------------------------------------------
-- 5. pays 表：只把 pay_handleroute = 'vpay' 的旧记录迁移到 'vmq'
--    * 不会改动任何 pay_handleroute 已是其他值的记录（比如用户自建的 wxpay/alipay）
--    * 如果你的 pays 表里根本没有 vpay，本条 UPDATE 影响 0 行、安全
-- -----------------------------------------------------------------------------
UPDATE `pays` SET `pay_handleroute` = 'vmq'
 WHERE `pay_handleroute` = 'vpay'
   AND `pay_check` IN ('vwx','vzfb');

-- =============================================================================
-- 完成。执行完毕后请刷新配置缓存：
--   php artisan cache:clear
--   php artisan config:clear
--   php artisan route:clear
--   php artisan view:clear
-- =============================================================================
