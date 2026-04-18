![](https://files.mdnice.com/user/39773/dc2143d7-422a-4fe3-8bcb-692e8c6cbd9a.png)

<p align="center">
<img alt="GitHub" src="https://img.shields.io/github/license/outtimes/dujiaoka?style=for-the-badge">
<img alt="GitHub tag (latest by date)" src="https://img.shields.io/github/v/tag/outtimes/dujiaoka?label=version&style=for-the-badge">
<img alt="PHP Version" src="https://img.shields.io/static/v1?label=PHP&message=8.3%2B&style=for-the-badge">
<img alt="Laravel Version" src="https://img.shields.io/static/v1?label=Laravel&message=12.x&style=for-the-badge&color=red">
<img alt="Telegram" src="https://img.shields.io/static/v1?label=Telegram&logo=Telegram&message=@dujiaoka&style=for-the-badge&color=blue&&link=https://t.me/dujiaoka">
</p>

# :warning: 开发版本声明
**本版本为重构版本，正在积极开发中，不建议用于生产环境**  
**仅供技术研究和功能预览，目前需全新安装部署，后期将会推出迁移工具**

## :rocket: 架构升级
本项目基于[独角数卡](https://github.com/assimon/dujiaoka)进行深度重构和功能扩展：

- 升级框架至 **Laravel 12**
- 使用 **Filament 3** 作为后台管理系统
- 以及超多新增功能与优化

## :sparkles: 部分功能特性

### 用户系统
- 完整的用户注册/登录/找回密码体系
- 邮箱验证
- 基于消费实现的用户等级与折扣系统
- 用户余额系统（充值/消费/退款）
- 用户下单历史与订单管理

### 商品管理
- 多规格（子商品）支持
- 下单库存模式可选（下单减库存 / 发货减库存）
- 商品自定义表单字段
- 登录购买限制
- 自选卡密功能
- 批发价格配置

### 订单系统
- 购物车批量下单
- IP并发订单限制
- 余额支付 / 混合支付
- 订单过期自动取消与余额退还
- 优惠券系统

### 支付系统
- 统一支付网关架构（可扩展驱动）
- 支付通道费率配置
- 单商品支付方式限制
- 异步回调幂等处理

### 安全特性
- XSS 防护（HTML Purifier + JS 上下文 `@json` 编码 + URL 协议过滤）
- CSRF 保护
- SSRF 防护（API Hook DNS 校验 + Bark URL scheme 校验）
- Session Fixation 防护（登录/注册/改密后 regenerate）
- 暴力破解防护（登录/订单创建/查询 rate limiting）
- 输入校验（订单号/邮箱/Cookie 等全链路格式验证）
- 敏感信息脱敏（支付异常/数据库错误不暴露内部细节）
- 管理员强密码策略（8位+大小写+数字）
- XXE 防护（XML 解析使用 LIBXML_NONET）
- Open Redirect 防护（orderSN 格式校验）

## :open_book: 技术依赖

### 核心框架
- **后端框架**: [Laravel 12.x](https://github.com/laravel/laravel)
- **管理后台**: [Filament 3.x](https://filamentphp.com/)
- **支付集成**: [yansongda/Pay](https://github.com/yansongda/pay)
- **区块链支付**: [Tokenpay](https://github.com/LightCountry/TokenPay)

### 数据与服务
- **地理数据**: [GeoLite2](https://dev.maxmind.com/geoip/geolite2-free-geolocation-data)
- **缓存系统**: Redis
- **队列处理**: Laravel Queues
- **文件存储**: Laravel Storage

### 项目原版作者：
- [Assimon](https://github.com/assimon)

#### 核心贡献者：
- [iLay1678](https://github.com/iLay1678)

#### 模板贡献者：
- [Riniba](https://t.me/riniba) 默认模板作者

鸣谢以上开源项目及贡献者，排名不分先后。

## :gear: 部署要求

### 服务器环境
- **操作系统**: Linux (推荐Ubuntu 20.04+/CentOS 8+)
- **Web服务器**: Nginx 1.18+ 或 Apache 2.4+
- **数据库**: MySQL 8.0+ 或 MariaDB 10.6+
- **缓存**: Redis 6.0+
- **PHP版本**: 8.3+ (推荐) / 8.2+ (最低)

### PHP扩展要求
- **必需扩展**: `fileinfo`, `redis`, `gd`, `curl`, `zip`, `xml`, `mbstring`
- **系统函数**: `putenv`, `proc_open`, `pcntl_signal`, `pcntl_alarm`
- **推荐扩展**: `opcache`, `imagick`

### 技术要求
- 具备Linux服务器基础运维知识
- 理解Laravel框架部署流程
- 熟悉Composer依赖管理
- 了解Redis配置和使用

## :speech_balloon:使用交流
- 原作者的[Telegram群组](https://t.me/dujiaoka)
- 原作者的[Telegram官方频道](https://t.me/dujiaoshuka)

## :eye_speech_bubble:相关推荐
- [两米商店 2MStore](https://buy.2m.pub)
> 以下为原作者推荐
- （🇭🇰香港三网(电信/移动/联通)直连优化VPS，CN2优化网络大带宽低至35RMB/每月）[👉🏻点我直达](https://www.vkvm.info/cart?action=configureproduct&pid=146&aff=ECRPONNJ)
- （🇺🇸美国免备案vps，配置2核2G仅需`20.98$`≈`145RMB`一年/支持支付宝付款）[👉🏻点我直达](https://my.racknerd.com/aff.php?aff=2745&pid=681)

## :open_mouth:快速预览
![](https://files.mdnice.com/user/39773/0abbadfa-ef39-492b-bbc0-ac74b78e6a64.png)

![](https://files.mdnice.com/user/39773/8d72ecb8-c860-4d05-93c3-3691e786b05a.png)

![](https://files.mdnice.com/user/39773/c712dd5a-d987-4fd4-a84c-ed2244579c1c.png)

![](https://files.mdnice.com/user/39773/554c51e0-563f-4176-91ed-5ec4e0478c1c.png)

![](https://files.mdnice.com/user/39773/e43c9d40-1a03-4821-9e98-d285fa1ce6bd.png)

![](https://files.mdnice.com/user/39773/978342a2-15f7-477c-85c3-d6aac8a06e63.png)

![](https://files.mdnice.com/user/39773/0d9494c7-9cbe-4dea-b168-05f36d55273c.png)

## :package: 快速部署

### 方式一：服务器一键部署（推荐）

适用于全新的 Ubuntu 20.04+ / Debian 11+ 服务器，一键完成 LNMP + Redis + Composer + Supervisor 全套环境搭建：

```bash
bash <(curl -sL https://raw.githubusercontent.com/tianyou-lab/dujiaoka/main/scripts/install.sh)
```

脚本执行后按照提示输入：
1. 你的域名（如 `shop.example.com`）
2. 数据库名称和密码
3. 是否自动申请 Let's Encrypt SSL 证书

安装完成后访问 `https://你的域名` 进入安装向导。

> 如果服务器已有 LNMP 环境，也可以手动部署，参见 [手动安装文档](debian_manual.md)

---

### 方式二：宝塔面板部署

适用于已安装 [宝塔面板](https://www.bt.cn/) 的服务器。

#### 1. 环境要求

在宝塔面板「软件商店」中安装以下组件：
- **Nginx** 1.18+
- **MySQL** 8.0+ 或 **MariaDB** 10.6+
- **PHP** 8.3+（推荐，8.2可用但部分依赖需降级）
- **Redis** 6.0+

#### 2. PHP 配置

进入宝塔面板 → **软件商店** → **PHP-8.2** → **设置**：

**安装扩展**（PHP → 安装扩展）：
- `fileinfo`（必装）
- `redis`（必装）
- `opcache`（推荐）

**禁用函数**（PHP → 禁用函数）：
从禁用列表中**删除**以下函数：
- `putenv`
- `proc_open`
- `pcntl_signal`
- `pcntl_alarm`

#### 3. 创建网站

1. 在宝塔面板 → **网站** → **添加站点**
2. 域名填写你的域名
3. 数据库选择 **MySQL**，编码选择 **utf8mb4**
4. PHP版本选择 **PHP-8.2**
5. 创建完成后记录数据库名、用户名和密码

#### 4. 上传源代码

**方式A：Git 拉取（推荐）**

在宝塔终端或 SSH 中执行：
```bash
cd /www/wwwroot/你的域名
git clone https://github.com/tianyou-lab/dujiaoka.git .
```

**方式B：下载压缩包**

从 GitHub 下载最新版本源码压缩包：
- [下载 ZIP](https://github.com/tianyou-lab/dujiaoka/archive/refs/heads/main.zip)

下载后通过宝塔文件管理器上传到网站根目录并解压。解压后将文件移动到网站根目录下（确保 `public` 目录在网站根目录下）。

#### 5. 设置运行目录

进入宝塔面板 → **网站** → 你的站点 → **网站目录**：
- 将**运行目录**设为 `/public`

#### 6. 设置伪静态

进入宝塔面板 → **网站** → 你的站点 → **伪静态**：

选择 **laravel5** 或手动填入：
```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

#### 7. 安装 Composer 依赖

在宝塔终端或 SSH 中执行：
```bash
cd /www/wwwroot/你的域名
composer install --no-dev --optimize-autoloader
```

#### 8. 设置目录权限

在宝塔终端或 SSH 中执行：
```bash
cd /www/wwwroot/你的域名
chown -R www:www .
chmod -R 755 .
chmod -R 775 storage bootstrap/cache
```

#### 9. 访问安装向导

浏览器访问 `https://你的域名`，按提示填写数据库信息完成安装。

#### 10. 配置队列（重要）

在宝塔面板 → **软件商店** → 搜索安装 **Supervisor 管理器**。

进入 Supervisor 管理器 → **添加守护进程**：
- **名称**: `dujiaoka-queue`
- **运行用户**: `www`
- **运行目录**: `/www/wwwroot/你的域名`
- **启动命令**: `php artisan queue:work --sleep=3 --tries=3 --max-time=3600`
- **进程数量**: `1`

保存后确认进程状态为「运行中」。

#### 11. 安装后配置

1. 编辑网站根目录下的 `.env` 文件：
   - 将 `APP_DEBUG=true` 改为 `APP_DEBUG=false`
   - 如使用宝塔 CDN 或反代，配置 `TRUSTED_PROXIES=127.0.0.1`
2. 访问 `https://你的域名/admin` 登录管理后台
3. 默认账号：`admin`，密码：`admin`
4. **立即修改默认密码**

---

### 方式三：手动安装

适用于熟悉 Linux 运维的用户，参见完整手动安装文档：
- [Debian/Ubuntu 手动安装教程](debian_manual.md)

---

## :book: 文档和教程

### 官方文档
- [系统安装指南](https://github.com/outtimes/dujiaoka/wiki/installation)
- [配置说明文档](https://github.com/outtimes/dujiaoka/wiki/configuration)
- [API接口文档](https://github.com/outtimes/dujiaoka/wiki/api)
- [主题开发指南](https://github.com/outtimes/dujiaoka/wiki/theme-development)

### 参考资料（原版）
- [Linux环境安装](https://github.com/assimon/dujiaoka/wiki/linux_install)
- [支付配置说明](https://github.com/assimon/dujiaoka/wiki/problems#各支付对应配置)
- [常见问题解答](https://github.com/assimon/dujiaoka/wiki/problems)

**注意**: 本版本架构已升级，请以本仓库Wiki文档为准

## :bank:支持的支付接口

### 新版统一支付网关
新版采用统一支付网关架构，支持以下驱动（可扩展）：
- [x] 支付宝（Alipay）- 当面付、PC网站、手机网站
- [x] 微信支付（Wechat）- Native、H5
- [x] Wepay（威支付）
- [x] EPUSDT（加密货币 USDT 支付）
- [x] [TokenPay](https://github.com/LightCountry/TokenPay)（区块链支付）

### 旧版兼容（通过 PayController）
- [x] Payjs
- [x] 码支付(QQ/支付宝/微信)
- [x] [Paypal支付(默认美元)](https://www.paypal.com)
- [x] V免签支付
- [x] 全网易支付支持(通用彩虹版)
- [x] [Stripe](https://stripe.com/)

## :shield: 安全配置

### 默认管理员信息
**部署完成后请立即修改以下默认配置:**

- **后台访问路径**: `/admin`
- **默认管理员账号**: `admin`
- **默认管理员密码**: `admin`

### 安全建议
- 修改默认管理员密码为强密码（至少8位，含大小写字母和数字）
- 将 `.env` 中 `APP_DEBUG` 设为 `false`
- 配置 `TRUSTED_PROXIES` 环境变量为实际反代 IP，避免 IP 伪造
- 定期更新系统和 Composer 依赖
- 配置防火墙限制管理后台访问
- 开启 HTTPS 并配置 HSTS 头
- 使用 Redis 作为缓存和队列驱动（库存锁需要原子操作支持）
- 配置 `session.php` 中 `same_site` 为 `lax`（已默认配置）
- 合理设置 `order_ip_limits`（IP 待支付订单数限制）和 `order_expire_time`（订单过期时间）

## :eyes:免责声明

独角数卡是一款用于学习PHP搭建自动化销售系统的程序案例，仅供学习交流使用。
严禁用于用于任何违反`中华人民共和国(含台湾省)`或`使用者所在地区`法律法规的用途。      
因为作者即本人仅完成代码的开发和开源活动`(开源即任何人都可以下载使用)`，从未参与用户的任何运营和盈利活动。    
且不知晓用户后续将`程序源代码`用于何种用途，故用户使用过程中所带来的任何法律责任即由用户自己承担。      

## :raised_hands:License

独角数卡 DJK Inc [MIT license](https://opensource.org/licenses/MIT).

This product includes GeoLite2 data created by MaxMind, available from
[https://www.maxmind.com](https://www.maxmind.com)

