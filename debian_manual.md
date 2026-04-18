## 写在前面

- 此教程专为有洁癖的宝宝们准备。不使用任何一键安装脚本。面板党可以退散了！！
- 推荐环境：Debian 12+ / Ubuntu 22.04+
- **本版本推荐 PHP 8.3+**（8.2 可用但部分依赖需手动降级）

## 手动安装 LNMP

- 更新源

```bash  
apt update  
apt upgrade
``` 

- 安装 Nginx
```bash
apt install nginx
```
- 安装 MySQL 8.0+ 或 MariaDB 10.6+
```bash
apt install mariadb-server
```
- 配置 MariaDB
```bash
mysql_secure_installation
```
根据提示操作即可。
- 创建数据库
```bash
mariadb
```
之后会显示
```bash
Welcome to the MariaDB monitor.  Commands end with ; or \g.
Your MariaDB connection id is 74
Server version: 10.6.x-MariaDB
Copyright (c) 2000, 2018, Oracle, MariaDB Corporation Ab and others.
Type 'help;' or '\h' for help. Type '\c' to clear the current input statement.
MariaDB [(none)]> 
```
接下来输入命令 
```sql
CREATE DATABASE [这里替换为数据库名] CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL ON [这里替换为数据库名].* TO '[这里替换为用户名]'@'localhost' IDENTIFIED BY '[这里替换为密码]' WITH GRANT OPTION;
FLUSH PRIVILEGES;
EXIT
```
- 安装 PHP 8.2+

推荐使用 PHP 8.3+。需先添加 Sury 仓库：
```bash
apt install -y lsb-release apt-transport-https ca-certificates wget
wget -qO- https://packages.sury.org/php/apt.gpg | gpg --dearmor -o /usr/share/keyrings/sury-php.gpg
echo "deb [signed-by=/usr/share/keyrings/sury-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" | tee /etc/apt/sources.list.d/sury-php.list
apt update
apt install php8.3 php8.3-fpm php8.3-mysql php8.3-gd php8.3-zip php8.3-opcache php8.3-curl php8.3-mbstring php8.3-intl php8.3-dom php8.3-bcmath php8.3-redis php8.3-fileinfo php8.3-xml
```

- 安装 Redis
```bash
apt install redis-server
```
- 启用函数
`nano /etc/php/8.3/fpm/php.ini`，`ctrl+w` 搜索 `putenv`，`proc_open`，`pcntl_signal`，`pcntl_alarm` 在 `disable_functions` 一行有就去掉。
之后 `systemctl restart php8.3-fpm`

## 下载源代码
```bash
apt install git
git clone https://github.com/hiouttime/dujiaoka.git /var/www/dujiaoka
chown -R www-data:www-data /var/www/dujiaoka
chmod -R 755 /var/www/dujiaoka
chmod -R 775 /var/www/dujiaoka/storage /var/www/dujiaoka/bootstrap/cache
```
## 配置 nginx
- 假设你的域名是：`domain.com`
- 假设你的网站目录是：`/home/wwwroot/dujiaoka`
- 配置文件的存放目录是：`/usr/local/nginx/conf/vhost`
- 按下文教程配置时，注意修改演示配置中的域名和目录

```bash
nano /etc/nginx/sites-enabled/dujiaoka 
```
你可以参考我的配置文件
```bash
server
    {
        listen 80;
	listen [::]:80;
        server_name domain.com ;
        return 301 https://$server_name$request_uri;
    }

server
    {
        listen 443 ssl http2;
	listen [::]:443 ssl http2;
        server_name domain.com ;
        index index.html index.htm index.php default.html default.htm default.php;
        root  /var/www/dujiaoka/public;
        ssl_certificate /etc/nginx/sslcert/cert.crt;
        ssl_certificate_key /etc/nginx/sslcert/key.key;
        # openssl dhparam -out /usr/local/nginx/conf/ssl/dhparam.pem 2048
        #ssl_dhparam /usr/local/nginx/conf/ssl/dhparam.pem;

        location / {
    try_files $uri $uri/ /index.php?$query_string;
}
        #error_page   404   /404.html;

        # Deny access to PHP files in specific directory
        #location ~ /(wp-content|uploads|wp-includes|images)/.*\.php$ { deny all; }

        location ~ [^/]\.php(/|$)
        {
          
            fastcgi_pass  unix:/var/run/php/php8.3-fpm.sock;
           
            include snippets/fastcgi-php.conf;
        }


        location ~ .*\.(gif|jpg|jpeg|png|bmp|swf)$
        {
            expires      30d;
        }

        location ~ .*\.(js|css)?$
        {
            expires      12h;
        }

        location ~ /.well-known {
            allow all;
        }

        location ~ /\.
        {
            deny all;
        }

        access_log off;
    }

```
在 `/etc/nginx/sslcert/` 上传你的https证书 之后 `nginx -t` 没有报错就重启nginx `/etc/init.d/nginx restart`

## Composer 安装
```bash
cd /var/www/dujiaoka
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php
php -r "unlink('composer-setup.php');"
mv composer.phar /usr/local/bin/composer
composer install --no-dev --optimize-autoloader
```

## 创建初始 .env 文件

Web 安装向导要求 Laravel 能先正常启动并渲染 `/install` 页面，必须**先从模板生成一个 `.env`**（向导提交后会自动覆盖写入你填的真实配置）：

```bash
cd /var/www/dujiaoka
cp .env.default .env
chown www-data:www-data .env
chmod 664 .env
# 同步修一下 storage / bootstrap/cache 权限
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

> ⚠️ 跳过这一步直接访问站点会因为 Laravel 读不到 `APP_KEY` / `DB_*` 而报 500，页面一片空白或白屏。

## 访问安装页面
访问你的域名，未安装状态会自动跳转到 `/install` 向导。填写：
- MySQL 数据库名：`dujiaoka`
- MySQL 用户名：之前 `GRANT` 时创建的账号
- MySQL 密码：`你设置的密码`
- Redis 主机/端口：`127.0.0.1` / `6379`
- Redis 密码：`无需填写（如未设置密码）`
- 网站 URL：你的域名，如 `https://domain.com`
- 管理后台路径：如 `/admin`

提交后安装程序会：
1. 用你填的信息覆盖写入 `.env`
2. 自动生成 `APP_KEY`
3. 执行 `database/sql/install.sql` 初始化数据库
4. 在项目根目录创建 `install.lock` 锁文件，防止重复安装

## 安装后编辑配置文件

编辑 `/var/www/dujiaoka/.env`
- 将 `APP_DEBUG=true` 改为 `APP_DEBUG=false`（**生产环境必须关闭**）
- 系统会自动根据访问协议适配，无需手动配置 HTTPS
- 如使用反代，配置 `TRUSTED_PROXIES` 为反代服务器 IP

## 配置 Supervisor
先安装
```bash
apt install supervisor
```
创建配置文件
```bash
nano /etc/supervisor/conf.d/dujiaoka.conf
```
写入配置文件

- 注意修改网站目录和用户
```bash
[program:laravel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/dujiaoka/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/dujiaoka-worker.log
stopwaitsecs=3600
```
启动
```bash
supervisorctl reread
supervisorctl update
supervisorctl start laravel-worker:*
```

## 安装完成后

1. 访问 `https://your-domain.com/admin` 登录管理后台
2. 默认账号：`admin`，密码：`admin`
3. **立即修改默认密码**（要求至少8位，含大小写字母和数字）
4. 在后台配置支付方式、商品分类、商品信息等

## 参考来源
- https://www.digitalocean.com/community/tutorials/how-to-install-linux-nginx-mariadb-php-lemp-stack-on-debian-10
- https://github.com/hiouttime/dujiaoka
