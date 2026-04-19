-- ============================================================
-- 用户分组 + 商品子规格分组价 增量升级脚本（已部署服务器使用）
-- 多次执行幂等：CREATE/IF NOT EXISTS、INSERT IGNORE、动态 ALTER
-- ============================================================

-- 1. user_groups 表
CREATE TABLE IF NOT EXISTS `user_groups` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '分组名称',
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '分组说明',
  `color` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '#6366f1' COMMENT '分组颜色',
  `sort` int NOT NULL DEFAULT '0' COMMENT '排序，越小越靠前',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1启用 0禁用',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status_sort` (`status`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户分组表（手动分配）';

INSERT IGNORE INTO `user_groups` (`id`, `name`, `description`, `color`, `sort`, `status`, `created_at`, `updated_at`) VALUES
  (1, '批发客户', '批发渠道客户，享受批发价格', '#10b981', 1, 1, now(), now()),
  (2, 'VIP 客户', '高价值客户，享受 VIP 专属价格', '#f59e0b', 2, 1, now(), now()),
  (3, '推广客户', '渠道推广客户，享受推广价格', '#8b5cf6', 3, 1, now(), now());

-- 2. users 表加 group_id 字段（动态判断，避免重复添加报错）
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'group_id'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `users` ADD COLUMN `group_id` bigint unsigned DEFAULT NULL COMMENT ''用户分组ID（手动分配，与等级解耦）'' AFTER `level_id`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'users_group_id_index'
);
SET @sql := IF(@idx_exists = 0,
  'ALTER TABLE `users` ADD INDEX `users_group_id_index` (`group_id`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. goods_sub_group_prices 表
CREATE TABLE IF NOT EXISTS `goods_sub_group_prices` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `sub_id` int NOT NULL COMMENT '商品子规格 ID（goods_sub.id）',
  `group_id` bigint unsigned NOT NULL COMMENT '用户分组 ID（user_groups.id）',
  `price` decimal(10,2) NOT NULL COMMENT '该分组对该子规格的专属绝对价',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_sub_group` (`sub_id`,`group_id`),
  KEY `idx_group` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='商品子规格分组价（绝对价）';

-- 4. permissions 表加 manage_user_groups（如果不存在）
INSERT IGNORE INTO `permissions` (`id`, `name`, `guard_name`, `created_at`, `updated_at`) VALUES
  (17, 'manage_user_groups', 'admin', now(), now());

-- 5. 给 super-admin / admin / manager 角色赋予新权限
INSERT IGNORE INTO `role_has_permissions` (`permission_id`, `role_id`) VALUES
  (17, 1), (17, 2), (17, 3);

-- 检查结果
SELECT 'user_groups' AS table_name, COUNT(*) AS rows_count FROM `user_groups`
UNION ALL SELECT 'goods_sub_group_prices', COUNT(*) FROM `goods_sub_group_prices`
UNION ALL SELECT 'permissions(manage_user_groups)', COUNT(*) FROM `permissions` WHERE `name` = 'manage_user_groups'
UNION ALL SELECT 'role_has_permissions(17)', COUNT(*) FROM `role_has_permissions` WHERE `permission_id` = 17
UNION ALL SELECT 'users.group_id column', COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'group_id';
