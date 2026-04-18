-- 升级脚本：将 remote_servers 表从旧版 data 字段结构迁移到新版独立字段结构
-- 执行前请备份数据库

ALTER TABLE `remote_servers`
  ADD COLUMN `url`         varchar(500)  DEFAULT NULL COMMENT 'HTTP服务器URL' AFTER `type`,
  ADD COLUMN `host`        varchar(255)  DEFAULT NULL COMMENT 'RCON/SQL主机' AFTER `url`,
  ADD COLUMN `port`        int(5)        DEFAULT NULL COMMENT '端口' AFTER `host`,
  ADD COLUMN `username`    varchar(255)  DEFAULT NULL COMMENT '用户名' AFTER `port`,
  ADD COLUMN `password`    varchar(255)  DEFAULT NULL COMMENT '密码' AFTER `username`,
  ADD COLUMN `database`    varchar(255)  DEFAULT NULL COMMENT '数据库名' AFTER `password`,
  ADD COLUMN `command`     text          DEFAULT NULL COMMENT 'RCON命令/SQL语句' AFTER `database`,
  ADD COLUMN `headers`     json          DEFAULT NULL COMMENT 'HTTP自定义请求头' AFTER `command`,
  ADD COLUMN `body`        json          DEFAULT NULL COMMENT 'HTTP自定义请求体' AFTER `headers`,
  ADD COLUMN `description` text          DEFAULT NULL COMMENT '备注' AFTER `body`,
  ADD COLUMN `is_active`   tinyint(1)    NOT NULL DEFAULT '1' COMMENT '是否启用' AFTER `description`;

-- 旧 data 字段保留，待人工确认数据迁移完成后可手动删除：
-- ALTER TABLE `remote_servers` DROP COLUMN `data`;
