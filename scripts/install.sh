#!/bin/bash
set -euo pipefail

# ============================================================
#  独角数卡 - 一键安装脚本
#  支持系统: Ubuntu 20.04+ / Debian 11+
#  用途: 自动安装 LNMP + Redis + Composer + Supervisor
# ============================================================

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

REPO_URL="https://github.com/tianyou-lab/dujiaoka.git"
WEB_ROOT="/var/www/dujiaoka"
PHP_VERSION="8.3"

log_info()  { echo -e "${GREEN}[INFO]${NC} $1"; }
log_warn()  { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }
log_step()  { echo -e "\n${CYAN}======== $1 ========${NC}\n"; }

check_root() {
    if [ "$(id -u)" -ne 0 ]; then
        log_error "请使用 root 用户运行此脚本"
        exit 1
    fi
}

check_os() {
    if [ ! -f /etc/os-release ]; then
        log_error "无法检测操作系统"
        exit 1
    fi
    . /etc/os-release
    case "$ID" in
        ubuntu|debian) ;;
        *) log_error "不支持的操作系统: $ID，仅支持 Ubuntu/Debian"; exit 1 ;;
    esac
    log_info "检测到操作系统: $PRETTY_NAME"
}

get_user_input() {
    echo ""
    echo -e "${CYAN}============================================${NC}"
    echo -e "${CYAN}       独角数卡 一键安装向导${NC}"
    echo -e "${CYAN}============================================${NC}"
    echo ""

    read -rp "请输入你的域名 (如 shop.example.com): " DOMAIN
    if [ -z "$DOMAIN" ]; then
        log_error "域名不能为空"
        exit 1
    fi

    read -rp "请输入数据库名称 [dujiaoka]: " DB_NAME
    DB_NAME=${DB_NAME:-dujiaoka}

    read -rp "请输入数据库用户名 [dujiaoka]: " DB_USER
    DB_USER=${DB_USER:-dujiaoka}

    while true; do
        read -rsp "请输入数据库密码: " DB_PASS
        echo
        if [ -z "$DB_PASS" ]; then
            log_error "数据库密码不能为空"
            continue
        fi
        if [ ${#DB_PASS} -lt 8 ]; then
            log_error "数据库密码至少8位"
            continue
        fi
        break
    done

    read -rp "是否自动申请 Let's Encrypt SSL 证书? (y/n) [y]: " APPLY_SSL
    APPLY_SSL=${APPLY_SSL:-y}

    if [[ "$APPLY_SSL" =~ ^[Yy]$ ]]; then
        read -rp "请输入用于 SSL 证书的邮箱: " SSL_EMAIL
        if [ -z "$SSL_EMAIL" ]; then
            log_warn "未填写邮箱，将跳过 SSL 证书申请"
            APPLY_SSL="n"
        fi
    fi

    echo ""
    log_info "配置信息确认:"
    echo "  域名: $DOMAIN"
    echo "  数据库: $DB_NAME / $DB_USER"
    echo "  SSL: $APPLY_SSL"
    echo ""
    read -rp "确认以上信息正确? (y/n) [y]: " CONFIRM
    CONFIRM=${CONFIRM:-y}
    if [[ ! "$CONFIRM" =~ ^[Yy]$ ]]; then
        log_info "已取消安装"
        exit 0
    fi
}

install_packages() {
    log_step "安装系统依赖"

    export DEBIAN_FRONTEND=noninteractive

    # --allow-releaseinfo-change: 云厂商镜像的 release 版本号漂移（如 13.3→13.4）会
    # 阻塞后续 apt 操作，必须先放行
    apt update -y --allow-releaseinfo-change
    apt install -y software-properties-common curl wget git unzip \
                   lsb-release apt-transport-https ca-certificates gnupg

    . /etc/os-release

    # PHP ${PHP_VERSION} 在不同发行版里的可用情况：
    #   - Debian 12 (bookworm): 默认仓库有 8.2，需要 Sury 才能装 8.3
    #   - Debian 13 (trixie)  : 默认仓库只有 8.4，需要 Sury 才能装 8.3
    #   - Ubuntu 22.04 (jammy): 默认仓库只有 8.1，需要 Ondrej PPA 才能装 8.3
    #   - Ubuntu 24.04 (noble): 默认仓库只有 8.3 ✓
    # 统一策略：Debian 全系走 Sury；Ubuntu 全系走 Ondrej PPA（幂等添加）
    if [ "$ID" = "debian" ]; then
        local sury_list="/etc/apt/sources.list.d/sury-php.list"
        if [ ! -f "$sury_list" ]; then
            log_info "添加 Sury PHP 仓库 ($(lsb_release -sc))..."
            wget -qO- https://packages.sury.org/php/apt.gpg \
                | gpg --dearmor -o /usr/share/keyrings/sury-php.gpg 2>/dev/null
            echo "deb [signed-by=/usr/share/keyrings/sury-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" \
                > "$sury_list"
            apt update -y
        else
            log_info "Sury 仓库已存在，跳过添加"
        fi
    elif [ "$ID" = "ubuntu" ]; then
        if ! grep -rqs "ondrej/php" /etc/apt/sources.list /etc/apt/sources.list.d/ 2>/dev/null; then
            log_info "添加 Ondrej PHP PPA..."
            add-apt-repository -y ppa:ondrej/php
            apt update -y
        else
            log_info "Ondrej PPA 已存在，跳过添加"
        fi
    else
        log_warn "未识别的发行版 ID=$ID，跳过 PHP 仓库配置（将使用系统自带 PHP）"
    fi

    log_info "安装 Nginx..."
    apt install -y nginx

    log_info "安装 MariaDB..."
    apt install -y mariadb-server mariadb-client

    log_info "安装 PHP ${PHP_VERSION}..."
    if ! apt install -y \
        php${PHP_VERSION} \
        php${PHP_VERSION}-fpm \
        php${PHP_VERSION}-mysql \
        php${PHP_VERSION}-gd \
        php${PHP_VERSION}-zip \
        php${PHP_VERSION}-opcache \
        php${PHP_VERSION}-curl \
        php${PHP_VERSION}-mbstring \
        php${PHP_VERSION}-intl \
        php${PHP_VERSION}-dom \
        php${PHP_VERSION}-bcmath \
        php${PHP_VERSION}-redis \
        php${PHP_VERSION}-fileinfo \
        php${PHP_VERSION}-xml; then
        log_error "PHP ${PHP_VERSION} 安装失败"
        log_error "可用的 PHP 包："
        apt-cache search "^php[0-9]+\.[0-9]+$" || true
        log_error "提示：如果你在 Debian 13 上看到这个错误，说明 Sury 源未生效，"
        log_error "  请检查 /etc/apt/sources.list.d/sury-php.list 与 apt update 输出"
        exit 1
    fi

    log_info "安装 Redis..."
    apt install -y redis-server

    log_info "安装 Supervisor..."
    apt install -y supervisor
}

configure_php() {
    log_step "配置 PHP"

    PHP_INI="/etc/php/${PHP_VERSION}/fpm/php.ini"
    if [ -f "$PHP_INI" ]; then
        for func in putenv proc_open pcntl_signal pcntl_alarm; do
            if grep -q "disable_functions.*${func}" "$PHP_INI"; then
                sed -i "s/\b${func}\b,\?//g" "$PHP_INI"
                log_info "已从禁用函数中移除: ${func}"
            fi
        done
        sed -i 's/^disable_functions\s*=\s*,*/disable_functions = /' "$PHP_INI"
    fi

    systemctl restart php${PHP_VERSION}-fpm
    log_info "PHP ${PHP_VERSION} 配置完成"
}

configure_database() {
    log_step "配置数据库"

    systemctl start mariadb
    systemctl enable mariadb

    mysql -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
    mysql -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';"
    mysql -e "FLUSH PRIVILEGES;"

    log_info "数据库 ${DB_NAME} 创建完成"
}

install_source() {
    log_step "下载源代码"

    if [ -d "$WEB_ROOT" ] && [ "$(ls -A $WEB_ROOT 2>/dev/null)" ]; then
        log_warn "目录 $WEB_ROOT 已存在且非空"
        read -rp "是否清空并重新安装? (y/n) [n]: " OVERWRITE
        OVERWRITE=${OVERWRITE:-n}
        if [[ "$OVERWRITE" =~ ^[Yy]$ ]]; then
            rm -rf "$WEB_ROOT"
        else
            log_info "跳过源代码下载"
            return
        fi
    fi

    git clone "$REPO_URL" "$WEB_ROOT"

    chown -R www-data:www-data "$WEB_ROOT"
    chmod -R 755 "$WEB_ROOT"
    chmod -R 775 "$WEB_ROOT/storage" "$WEB_ROOT/bootstrap/cache"

    log_info "源代码下载完成"
}

install_composer() {
    log_step "安装 Composer 依赖"

    if ! command -v composer &>/dev/null; then
        log_info "安装 Composer..."
        cd /tmp
        php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
        php composer-setup.php --quiet
        mv composer.phar /usr/local/bin/composer
        rm -f composer-setup.php
    fi

    cd "$WEB_ROOT"
    sudo -u www-data composer install --no-dev --optimize-autoloader --no-interaction

    log_info "Composer 依赖安装完成"
}

configure_nginx() {
    log_step "配置 Nginx"

    NGINX_CONF="/etc/nginx/sites-available/${DOMAIN}"

    if [[ "$APPLY_SSL" =~ ^[Yy]$ ]]; then
        cat > "$NGINX_CONF" <<NGINX_EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};
    root ${WEB_ROOT}/public;
    return 301 https://\$server_name\$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name ${DOMAIN};
    root ${WEB_ROOT}/public;
    index index.php index.html;

    ssl_certificate /etc/letsencrypt/live/${DOMAIN}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/${DOMAIN}/privkey.pem;

    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
    ssl_prefer_server_ciphers on;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ [^/]\.php(/|$) {
        fastcgi_pass unix:/var/run/php/php${PHP_VERSION}-fpm.sock;
        include snippets/fastcgi-php.conf;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
    }

    location ~ /\. {
        deny all;
    }

    location ~* \.(gif|jpg|jpeg|png|bmp|webp|ico|svg)$ {
        expires 30d;
    }

    location ~* \.(js|css)$ {
        expires 12h;
    }
}
NGINX_EOF
    else
        cat > "$NGINX_CONF" <<NGINX_EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};
    root ${WEB_ROOT}/public;
    index index.php index.html;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ [^/]\.php(/|$) {
        fastcgi_pass unix:/var/run/php/php${PHP_VERSION}-fpm.sock;
        include snippets/fastcgi-php.conf;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
    }

    location ~ /\. {
        deny all;
    }

    location ~* \.(gif|jpg|jpeg|png|bmp|webp|ico|svg)$ {
        expires 30d;
    }

    location ~* \.(js|css)$ {
        expires 12h;
    }
}
NGINX_EOF
    fi

    rm -f /etc/nginx/sites-enabled/default 2>/dev/null || true
    ln -sf "$NGINX_CONF" /etc/nginx/sites-enabled/

    nginx -t
    systemctl reload nginx

    log_info "Nginx 配置完成"
}

apply_ssl() {
    if [[ ! "$APPLY_SSL" =~ ^[Yy]$ ]]; then
        return
    fi

    log_step "申请 SSL 证书"

    if ! command -v certbot &>/dev/null; then
        apt install -y certbot python3-certbot-nginx
    fi

    certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos -m "$SSL_EMAIL" --redirect

    log_info "SSL 证书申请完成"
    log_info "证书将自动续期 (certbot renew)"
}

configure_supervisor() {
    log_step "配置 Supervisor 队列"

    cat > /etc/supervisor/conf.d/dujiaoka.conf <<SUP_EOF
[program:dujiaoka-worker]
process_name=%(program_name)s_%(process_num)02d
command=php ${WEB_ROOT}/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/dujiaoka-worker.log
stopwaitsecs=3600
SUP_EOF

    supervisorctl reread
    supervisorctl update

    log_info "Supervisor 队列配置完成"
}

configure_redis() {
    log_step "配置 Redis"

    systemctl enable redis-server
    systemctl start redis-server

    log_info "Redis 已启动"
}

print_result() {
    echo ""
    echo -e "${GREEN}============================================${NC}"
    echo -e "${GREEN}       独角数卡 安装完成！${NC}"
    echo -e "${GREEN}============================================${NC}"
    echo ""
    if [[ "$APPLY_SSL" =~ ^[Yy]$ ]]; then
        echo -e "  访问地址: ${CYAN}https://${DOMAIN}${NC}"
    else
        echo -e "  访问地址: ${CYAN}http://${DOMAIN}${NC}"
    fi
    echo -e "  网站目录: ${WEB_ROOT}"
    echo -e "  数据库名: ${DB_NAME}"
    echo -e "  数据库用户: ${DB_USER}"
    echo ""
    echo -e "${YELLOW}  请打开浏览器访问以上地址完成安装向导${NC}"
    echo ""
    echo -e "${YELLOW}  安装向导完成后：${NC}"
    echo -e "  1. 编辑 ${WEB_ROOT}/.env 将 APP_DEBUG 改为 false"
    echo -e "  2. 访问 /admin 登录后台 (默认: admin / admin)"
    echo -e "  3. ${RED}立即修改默认管理员密码！${NC}"
    echo ""
}

# ============ 主流程 ============

main() {
    check_root
    check_os
    get_user_input
    install_packages
    configure_php
    configure_database
    configure_redis
    install_source
    install_composer
    configure_nginx
    apply_ssl
    configure_supervisor
    print_result
}

main "$@"
