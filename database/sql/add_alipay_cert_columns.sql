-- ============================================================
-- 为 pays 表新增支付宝 v3 证书字段（仅运行一次）
-- 执行：mysql -u <DB_USER> -p<DB_PASS> <DB_NAME> < add_alipay_cert_columns.sql
-- 或在 MySQL 命令行 / phpMyAdmin 里粘贴执行
-- ============================================================

ALTER TABLE `pays`
    ADD COLUMN `app_public_cert` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL
        COMMENT '支付宝-应用公钥证书 (.crt 内容)' AFTER `merchant_pem`,
    ADD COLUMN `alipay_public_cert` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL
        COMMENT '支付宝-支付宝公钥证书 (.crt 内容)' AFTER `app_public_cert`,
    ADD COLUMN `alipay_root_cert` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL
        COMMENT '支付宝-支付宝根证书 (.crt 内容)' AFTER `alipay_public_cert`;
