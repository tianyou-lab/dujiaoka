# V 免签 部署与接入指南（嵌入式版）

> 适用 dujiaoka-main（本项目）**自 v2.0 起**内置的 V 免签监控端。
> **一台服务器 + 一部安卓手机即可**，不再需要独立部署 vmqphp/Vmq 监控端。

---

## 0. 嵌入式 V 免签 是什么

传统 V 免签需要三块东西：主站、独立的监控端（vmqphp 等）、监控 App。
嵌入式版把「监控端」的所有接口直接内置进 dujiaoka，省掉一台服务器：

| 模块 | 部署位置 | 作用 |
| --- | --- | --- |
| dujiaoka 主站（含 V 免签接口） | 你的 Web 服务器 | 下单、收银台、接收 App 推送、发货 |
| V 免签监控 App（VmqApk） | 一部安卓手机 | 常驻运行，监听微信/支付宝到账后 `POST /appPush` |

没错，只需要**一台服务器 + 一部手机**，没有第三方服务商，零手续费，到账 1 对 1。

---

## 1. 升级前数据备份

嵌入式方案会新建 4 张表并修改 `pays.pay_handleroute`。升级前强烈建议：

```bash
cd /var/www/dujiaoka
php artisan down
mysqldump -u你的用户 -p你的密码 你的库名 > /tmp/dujiaoka_before_vmq.sql
```

---

## 2. 执行数据库升级脚本

项目仓库已内置升级 SQL：`database/sql/add_vmq_embedded.sql`。

```bash
cd /var/www/dujiaoka
mysql -u你的用户 -p你的密码 你的库名 < database/sql/add_vmq_embedded.sql
```

脚本做这几件事（**完全幂等，可以重复执行**）：

1. 创建 `vmq_pay_orders`（V 免签内部订单）
2. 创建 `vmq_tmp_prices`（金额错位锁，防并发撞车）
3. 创建 `vmq_settings`（全局通讯密钥、超时、心跳等）—— 已存在则不会重置任何已有设置
4. 创建 `vmq_qrcodes`（固定金额收款码，选用）
5. 仅把**原本 `pay_handleroute='vpay'` 的 vwx / vzfb** 记录迁移到 `pay_handleroute='vmq'`；其他通道不动

> 注意：脚本**不会 DROP 任何表**，不会清空 `vmq_settings.key`，也不会覆盖管理员已经填好的通讯密钥，可放心在生产环境多次执行。

执行完检查一下：

```bash
mysql -u用户 -p密码 -e "SHOW TABLES LIKE 'vmq_%'" 库名
```

应能看到 4 张新表。

---

## 3. 清缓存 & 拉起来

```bash
cd /var/www/dujiaoka
composer dump-autoload -o
php artisan optimize:clear
php artisan filament:cache-components
php artisan up
```

---

## 4. 后台基础配置

### 4.1 生成通讯密钥

登录后台：

* **支付配置 → V 免签 全局设置**

做 3 件事：

1. **启用嵌入式 V 免签** 打开
2. **通讯密钥** 点右侧「随机生成」按钮，会自动填入一个 32 位随机串
3. **订单超时** 建议 `10` 分钟，**心跳超时** 建议 `60` 秒，**金额错位方向** 选「递增」

最下方会看到一个运行状态面板：**监控 App 离线 / 最后心跳 / 最后到账**。此时 App 还没连上来，离线正常。

### 4.2 配置可见的支付方式

* **支付配置 → 支付方式**

找到 `vwx`（微信扫码）和 `vzfb`（支付宝扫码）两条记录：

* **支付处理模块 pay_handleroute**：填 `vmq`（如果原来是 `vpay`，升级脚本会自动迁移）
* **支付标识 pay_check**：`vwx` 或 `vzfb`
* **支付方式**：选「扫码」
* **商户 ID / 商户密钥 / 商户 KEY**：**全部留空**（嵌入式模式不使用，留着反而会误导）
* 打开「启用」

保存即可。**绝对不要**在「商户密钥」里填什么 `https://xxx.com/`，那是外置监控端模式的用法；嵌入式模式下留空即可。

### 4.3（可选）上传固定金额收款码

* **支付配置 → V 免签 收款码**

在这里你可以把自己微信/支付宝里截图出来的 **固定金额** 付款码上传：

* 类型：1=微信，2=支付宝
* 金额：精确到分，如 `1.99`
* 收款 URL：长按二维码图解出来的 `https://...` 或 `wxp://...`
* 启用：打开

当用户下单金额恰好 == 上传的金额（经过金额错位后的那个金额）时，会直接显示你的固定二维码，而不是动态二维码。**不上传也能跑**，嵌入式模式会动态生成带金额的收款链接。

---

## 5. 安卓监控 App 配置

下载 [VmqApk](https://github.com/szvone/VmqApk/releases)（任意社区分支都兼容，协议一致）。

安装后进入**设置页**：

| 字段 | 值 |
| --- | --- |
| 服务端地址 | `https://你的站点域名/` （**末尾一定要有斜杠**） |
| 通讯密钥 | 和后台「V 免签 全局设置 → 通讯密钥」**完全一致** |
| 心跳间隔 | `30` 秒 |

保存后点「启动监控」。App 会：

1. 每 30 秒调用 `POST /appHeart` 上报心跳
2. 监听到微信/支付宝通知栏消息时，调用 `POST /appPush` 推送到账

**关键：** 把这部手机放在一个**稳定电源**的地方，关闭系统省电/后台清理，否则 App 会被杀。

回到后台「V 免签 全局设置」页面，刷新一下，状态应变成：

> 监控 App：● 在线
> 最后心跳：2026-xx-xx xx:xx:xx

---

## 6. 测试下单

1. 退出后台，用普通用户下单，选「微信扫码」或「支付宝扫码」
2. 跳到本站收银台（URL 形如 `/pay/vmq/cashier/订单号`）
3. 页面显示倒计时 + 二维码 + 实际支付金额（**可能比原价 +0.01 或 -0.01**，这是金额错位，用来唯一识别）
4. 用你绑定监控 App 的那部手机的微信/支付宝扫码 → 付款
5. 30 秒内页面自动弹出「支付成功」并跳转

---

## 7. 常见故障

### 7.1 App 一直显示离线

* 确认 **服务端地址** 末尾有 `/`
* 确认 **通讯密钥** 完全一致（区分大小写）
* 打开后台 `支付配置 → V 免签 全局设置`，看最后心跳时间，如果时间不更新说明 App 根本没连上
* 确认服务器 Web 防火墙放行 `POST /appHeart`（不要被 WAF 拦截）

### 7.2 用户付款了但订单没履约

* 查看 `storage/logs/laravel.log` 里有没有 `V免签 到账推送无匹配订单`
* 很可能是**金额错位**，用户付款时金额和订单当前的 `vmq_pay_orders.really_price` 不一致
* 进数据库 `SELECT * FROM vmq_pay_orders WHERE order_sn='xxx'` 查 `really_price`，和实际到账金额对比

### 7.3 出现 `签名校验不通过`

* 通讯密钥改了但 App 没同步，或者 App 的本地时钟偏差 > 5 分钟
* 重新点「随机生成」通讯密钥，把新密钥同步到 App，并校准手机时间

### 7.4 同金额并发

默认启用了 `vmq_tmp_prices` 金额错位池。2 个用户同时下 10 元订单时，一个会被调成 10.01（或 9.99），最多尝试 10 次。大量并发时可以到「V 免签 全局设置 → 金额错位方向」切换递增/递减方向。

---

## 8. 定时任务（强烈建议）

超时订单自动关闭。编辑 `crontab -e`：

```
* * * * * cd /var/www/dujiaoka && php artisan schedule:run >> /dev/null 2>&1
```

本项目已内置 `vmq:close-expired` 命令，会每分钟自动扫描 `state=0` 且已过期的 V 免签订单，置为已过期并释放金额锁。手工执行：

```bash
php artisan vmq:close-expired --dry-run   # 仅预览不写库
php artisan vmq:close-expired             # 真正关闭
```

---

## 9. 安全与性能建议

* **通讯密钥** 不要用简单字符串，使用「随机生成」一键生成 32 位，并不要提交进 git
* 把 `/appHeart`、`/appPush`、`/createOrder`、`/checkOrder` 几个接口放过 WAF 或 Rate limiter
* 数据库 `vmq_pay_orders` 会越来越多，半年后可以手动归档/清理 `state != 0` 的历史记录
* 如果要多人并发，建议 PHP-FPM ≥ 20 个进程，MySQL `innodb_buffer_pool_size ≥ 512M`

---

## 10. 回滚

万一嵌入式模式出问题需要临时下线：

```sql
UPDATE pays SET enable = 0 WHERE pay_handleroute = 'vmq';
```

或者直接在 **V 免签 全局设置** 把「启用嵌入式 V 免签」关掉，所有用户无法再通过 V 免签下单，已有 waiting 订单不受影响。

数据表不建议回滚删除，保留历史订单数据更安全。

---

## 11. 接口协议（开发者速查）

| 方法 | 路径 | 作用 | 调用方 |
| --- | --- | --- | --- |
| POST | `/createOrder` | V 免签兼容：外部下单 | 旧系统 / 调试 |
| POST | `/checkOrder` | 本站收银台轮询订单 | 浏览器 |
| POST | `/getOrder` | 查订单详情 | 外部 / 调试 |
| POST | `/appHeart` | App 心跳 | 监控 App |
| POST | `/appPush` | App 推送到账 | 监控 App |
| POST | `/getState` | 查 App 在线状态（需要密钥） | 调试 |
| GET | `/pay/vmq/cashier/{orderSN}` | 本站内置收银台 | 浏览器 |
| GET | `/pay/vmq/qr/{orderSN}` | 动态二维码图片 | 浏览器 |
| GET | `/pay/vmq/heart-public` | 公共 App 在线状态查询 | 浏览器 |

签名规则（`appHeart` / `appPush` / `getState`）：

```
sign = md5(type + price + t + key)
```

* `t` 是毫秒时间戳，容差 5 分钟
* `key` 就是后台的「通讯密钥」
* 所有参数均为 POST form-data

---

OK，到这里嵌入式 V 免签就跑起来了。享受**零手续费、零第三方**的个人免签收款吧。
