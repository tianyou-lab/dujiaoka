# V 免签 部署与接入完整指南

适用本项目（dujiaoka）从 0 到成功收款的全部操作，读完就能跑。

---

## 0. 为什么需要 V 免签

V 免签（VMQ，又叫"个人免签约收款"）是用一台**你自己的手机**+**一台监控端服务器**，模拟个人用户的微信、支付宝收款到账通知，把个人账户当成商户号来使用。

系统分成三块，**缺一不可**：

| 模块 | 部署在哪 | 作用 |
| --- | --- | --- |
| dujiaoka（本项目） | 你的 Web 服务器 | 下单、跳转、接收回调、发货 |
| V 免签监控端（vmqphp / vmq / vmqfox 等） | 一台**独立**的 Web 服务器或子域名 | 生成二维码、接收监控 App 的推送、回调商户 |
| V 免签监控 App（VmqApk） | 一台安卓手机或模拟器 | 常驻运行，监听微信/支付宝到账 |

> 重要：**监控端**和 **dujiaoka** 不是一个东西，**不能部署在同一个域名/路径**，否则就会像你看到的 `/createOrder` 404 那样冲突。

---

## 1. 选版本

V 免签有多个社区分支，协议大同小异。建议按推荐顺序选：

1. **szvone/vmqphp**（PHP 老版）—— 文档多、生态最稳定、配合本项目已验证
2. **szvone/Vmq**（Java 老版）—— 一样稳定，部署需要 JDK
3. **vmqfox-backend**（ThinkPHP 8 重构版，RESTful）—— 新，路径略有不同
4. **Vmq-Go**（Go 重写版）—— 低内存，ARM 小机利器

**本项目（commit d4e32c1 起）的驱动完全按 V 免签协议编写**（`/createOrder` + md5(payId+param+type+price+key) 签名）。因此**首选 `szvone/vmqphp` 或 `szvone/Vmq`**，其它版本需要对方保持向后兼容。

---

## 2. 准备工作

- 一台 **独立** 可访问的 Web 服务器或子域名（例如 `https://vmq.your-site.com/`）
  - **不能**用 dujiaoka 的主域名，必须是不同主机名或子域名
  - 建议用 HTTPS（微信/支付宝收款回调不受 HTTPS 限制，但你的回调地址 `/pay/vmq/notify` 在 dujiaoka 这边如果用 HTTPS，监控端访问时不能有证书错误）
- 一台 **安卓手机或模拟器**（用来装监控 App，7 天也不熄屏、不杀后台）
- 一个干净的**个人微信**+**个人支付宝**账号，绑定你想收钱的银行卡
- PHP 7.x / 8.x + MySQL + Nginx（装 vmqphp 用）；装 Java 版就需要 JDK 8

---

## 3. 下载监控端（以 vmqphp 为例）

### 方式 A：Git 克隆（推荐）

```bash
cd /var/www && \
git clone https://github.com/szvone/vmqphp.git vmq
```

如果 GitHub 在你服务器上慢，换 gitee：

```bash
cd /var/www && \
git clone https://gitee.com/isoundy/vmq.git vmq
```

### 方式 B：直接下载压缩包

- GitHub Release：<https://github.com/szvone/vmqphp/releases>
- 解压到 `/var/www/vmq`，保证 `public` 是 Web 入口

---

## 4. 部署监控端（vmqphp）

### 4.1 安装依赖

```bash
cd /var/www/vmq && \
composer install --no-dev --optimize-autoloader
```

（如果没装 composer：`curl -sS https://getcomposer.org/installer | php && mv composer.phar /usr/local/bin/composer`）

### 4.2 建库 & 配置

```sql
CREATE DATABASE vmq DEFAULT CHARSET utf8mb4;
CREATE USER 'vmq'@'localhost' IDENTIFIED BY '一个强密码';
GRANT ALL ON vmq.* TO 'vmq'@'localhost';
FLUSH PRIVILEGES;
```

把 `/var/www/vmq/.env.example` 复制成 `.env`，修改数据库连接：

```ini
APP_DEBUG=false
APP_URL=https://vmq.your-site.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=vmq
DB_USERNAME=vmq
DB_PASSWORD=一个强密码
```

### 4.3 初始化数据库

```bash
cd /var/www/vmq && \
php artisan key:generate && \
php artisan migrate --force && \
php artisan db:seed --force
```

（不同版本命令可能不同，以仓库 README 为准。）

### 4.4 配置 Nginx

```nginx
server {
    listen 443 ssl http2;
    server_name vmq.your-site.com;

    # SSL 证书（Let's Encrypt / 宝塔 / 自行签发都行）
    ssl_certificate     /etc/letsencrypt/live/vmq.your-site.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/vmq.your-site.com/privkey.pem;

    root /var/www/vmq/public;
    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;   # 改成你 PHP 版本的 socket
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }

    access_log /var/log/nginx/vmq.access.log;
    error_log  /var/log/nginx/vmq.error.log;
}
```

重载：`nginx -t && systemctl reload nginx`

### 4.5 权限

```bash
chown -R www-data:www-data /var/www/vmq/storage /var/www/vmq/bootstrap/cache
chmod -R 775 /var/www/vmq/storage /var/www/vmq/bootstrap/cache
```

打开 `https://vmq.your-site.com/` 能看到登录页（默认账号 admin / admin，登录后第一时间改掉）。

---

## 5. 监控端后台设置

登录监控端后台，找到**系统设置 / 系统管理 / 后端配置**（不同版本菜单名略有不同）。**必须** 设置的 3 项：

| 项 | 填什么 | 说明 |
| --- | --- | --- |
| **通讯密钥 / key** | 点「生成」，得到一个 32 位随机串 | **极度重要**，一定要复制好，后面 dujiaoka 要填这个 |
| 心跳超时（秒） | `60`–`120` | App 多久没上报就判定离线 |
| 订单超时（秒） | `300` | 同 dujiaoka 的订单过期时间一致或稍大一点 |

> **通讯密钥一定不要泄露给外部**，签名就靠这个。

---

## 6. 安卓 App（监控 App）安装

### 6.1 下载

- 仓库：<https://github.com/szvone/VmqApk/releases>
- 镜像：<https://gitee.com/isoundy/vmq-apk>（同步仓库里可能附 APK）

直接下载最新的 `.apk` 安装到安卓手机上。

### 6.2 App 里绑定监控端

第一次打开 App：

- **服务器地址**：填 `https://vmq.your-site.com`（就是第 4 步的监控端 URL）
- **通讯密钥**：和监控端后台的「通讯密钥」**一字不差**
- **心跳间隔**：默认 60 秒即可

点"开始服务"后，App 会常驻通知栏，每隔几秒给监控端上报一次心跳。监控端后台可以在"设备管理"看到设备在线。

### 6.3 让 App 活下去

安卓系统会随便杀后台。务必做：

1. 关闭电池优化：`设置 → 电池 → 电池优化 → 全部应用 → V免签监控 → 不优化`
2. 自启动：`设置 → 应用管理 → V免签监控 → 允许自启动/后台运行/关联启动`
3. 锁屏不清后台：`多任务界面长按 App → 锁定`
4. 给微信、支付宝也开`通知权限`（监控 App 是靠读通知栏消息来识别到账的）

> **推荐的做法**：找一台二手旧安卓手机，单独跑这个 App，24 小时开机充电常驻。云手机/安卓模拟器也行。

### 6.4 手机里微信/支付宝收款设置

- **支付宝**：打开"收款到账语音提醒"（首页搜"收款到账"即可开启），并在通知里保证"支付宝到账通知"能弹出来
- **微信**：打开"收款到账语音提醒"（微信 → 我 → 服务 → 收付款 → 收款小账本 → 右上角 → 收款设置），确保"到账提醒"是开着的

只要手机能"叮咚，到账 X 元"，监控 App 就能识别并上报。

---

## 7. 回到 dujiaoka 配置

登录 dujiaoka 后台 → **支付配置 → 支付通道 → 新建**

### 7.1 微信通道

| 字段 | 填什么 |
| --- | --- |
| 支付名称 `pay_name` | `V免签微信` |
| 支付标识 `pay_check` | `vwx` |
| 支付方式 `pay_method` | 选 **扫码** |
| 支付场景 `pay_client` | 选 **通用** |
| **支付处理模块 `pay_handleroute`** | `vmq` |
| 通道费率 `pay_fee` | `0` |
| 仅允许中国大陆下单 | 按需 |
| 是否启用 | **打开** |
| **商户 ID `merchant_id`** | 第 5 步生成的 **通讯密钥**（32 位） |
| 商户 KEY `merchant_key` | 留空 |
| **商户密钥 `merchant_pem`** | **监控端 URL**（例如 `https://vmq.your-site.com/`，结尾斜杠可省）。**绝对不能** 填你自己 dujiaoka 的域名 |
| 支付宝证书区 | 全部留空 |

保存。

### 7.2 支付宝通道

复制一份微信通道，只改两处：

- 支付名称：`V免签支付宝`
- 支付标识 `pay_check`：`vzfb`（**注意是 vzfb，不是 vwx**）

保存。

### 7.3 商品关联支付通道

编辑商品 → 底部「支付方式限制」如果为空就表示"允许所有"；如果填了就要把 V免签 微信/支付宝的 ID 勾选进去。

---

## 8. 下单测试

1. 浏览商品 → 加入购物车 → 下单
2. 在结账页选 V免签微信 → 立即支付
3. 浏览器会 302 跳转到 `https://vmq.your-site.com/createOrder?payId=...&sign=...`
4. 监控端挑选一部已绑定的手机，显示一张**带有你个人微信收款码的二维码**
5. 用另一部手机的微信扫码付款（金额会比下单金额多/少几分钱，用来区分同时并发的订单）
6. 付款后，手机 App 捕获到账通知 → 上报监控端 → 监控端 POST 到 dujiaoka 的 `/pay/vmq/notify`
7. dujiaoka 验签通过 → 订单自动完成 → 结账页自动跳转到订单详情页

---

## 9. 故障排查

| 症状 | 原因 / 解决 |
| --- | --- |
| 点"立即支付"跳转后显示 404（**本站的**不是监控端的） | URL 里的 `driver` 不是 `vmq`；检查通道的`pay_handleroute` |
| 跳转到监控端后返回 **监控端自己的 404** | 你的 `merchant_pem` 填错了，填成了**非监控端**的域名（比如填成 dujiaoka 自己的前台） |
| 监控端提示 "签名错误" | 监控端"通讯密钥" 和 dujiaoka 的 `merchant_id` 不完全一致（大小写、空格） |
| 监控端提示 "没有可用设备" | App 掉线了。检查 App 的服务器地址、通讯密钥、网络，并检查手机是否因省电被杀 |
| 付款成功但订单不自动完成 | `storage/logs/laravel-*.log` 里看 `Vmq notify`，若没收到说明监控端的"回调地址"没填成 `https://dujiaoka站点/pay/vmq/notify`；若收到但 `sign mismatch` 同上"签名错误" |
| 付款金额和订单金额差几分 | V免签靠"到账金额唯一性"匹配订单，这几分钱是监控端自动加/减的，正常现象 |

### 日志位置

- dujiaoka：`storage/logs/laravel-*.log`，驱动里所有关键节点都会记录（`VmqDriver createOrder` / `Vmq notify received` / `Vmq notify: order completed`）
- 监控端：看对应版本的日志目录

### 启用详细错误页（临时调试）

dujiaoka 的 `.env`：

```ini
APP_DEBUG=true
APP_ENV=local
```

改完跑 `php artisan config:clear`。调通后记得改回 `APP_DEBUG=false`。

---

## 10. 安全建议

1. 监控端和 dujiaoka **必须** HTTPS（不然中间人可以篡改回调金额）
2. 监控端后台默认 `admin/admin`，**第一件事** 改强密码
3. 通讯密钥泄露等于有人可以伪造成功回调 → 泄露后立即重新生成一个，两边（监控端 + dujiaoka 的 `merchant_id`）**同时** 更新
4. 监控端防爬虫：可以在 Nginx 前加一层 Cloudflare / 给 `/createOrder` 放开但其它路径做 WAF
5. 建议不要在同一台服务器跑监控端 + dujiaoka，一旦被攻破 = 订单金额被任意伪造

---

## 附录 A：字段对照表

dujiaoka 后台「支付通道」的 4 个关键字段，和 V 免签侧的对应：

| dujiaoka 字段 | 对应 V免签 / 协议概念 |
| --- | --- |
| `pay_handleroute` | 驱动名，填 `vmq` |
| `pay_check` | URL 里的 `payway`，决定 `type=1` 或 `type=2`（本驱动已改为智能识别，填 `vwx` 走微信，填 `vzfb` 走支付宝） |
| `merchant_id` | V免签 通讯密钥（32 位） |
| `merchant_pem` | V免签 监控端完整 URL |
| `merchant_key` | **不使用**，留空 |

## 附录 B：回调 URL（告诉监控端的）

在 V 免签监控端后台"商户设置 / 回调地址"（不同版本叫法不一）填：

```
https://你的dujiaoka站点/pay/vmq/notify
```

> 结尾**不要加**斜杠、不要加查询串。

本项目的 `pay/*` 已在 CSRF 白名单，监控端可直接 POST 进来。
