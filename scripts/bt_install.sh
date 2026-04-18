#!/bin/bash
# ============================================================
#  独角数卡 - 宝塔面板一键部署脚本
#  适用：已安装宝塔面板的 Ubuntu/Debian/CentOS 服务器
#  功能：自动检测/补齐依赖，零手动配置
#
#  使用方法（在宝塔终端或 SSH 执行）：
#    cd /www/wwwroot/你的域名   # 进入已创建的站点目录
#    bash scripts/bt_install.sh
#
#  或远程一键：
#    bash <(curl -sL https://raw.githubusercontent.com/tianyou-lab/dujiaoka/main/scripts/bt_install.sh)
# ============================================================

set -u

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

log_info()  { echo -e "${GREEN}[✓]${NC} $1"; }
log_warn()  { echo -e "${YELLOW}[!]${NC} $1"; }
log_error() { echo -e "${RED}[✗]${NC} $1"; }
log_step()  { echo -e "\n${CYAN}${BOLD}▶ $1${NC}\n"; }
log_todo()  { echo -e "${YELLOW}[TODO]${NC} $1"; }

REPO_URL="https://github.com/tianyou-lab/dujiaoka.git"
BT_WWW="/www/wwwroot"
BT_PHP_BASE="/www/server/php"
BT_USER="www"
BT_GROUP="www"

# 全局变量
SITE_PATH=""
DOMAIN=""
PHP_VERSION=""
PHP_BIN=""
COMPOSER_BIN=""
DB_HOST="127.0.0.1"
DB_PORT="3306"
DB_NAME=""
DB_USER=""
DB_PASS=""
REDIS_HOST="127.0.0.1"
REDIS_PORT="6379"
REDIS_PASS=""
SETUP_QUEUE="y"

# ============ 前置检查 ============

check_root() {
    if [ "$(id -u)" -ne 0 ]; then
        log_error "请使用 root 用户运行此脚本（宝塔终端默认即为 root）"
        exit 1
    fi
}

check_bt_panel() {
    log_step "检测宝塔面板环境"

    if [ ! -d "/www/server/panel" ]; then
        log_error "未检测到宝塔面板（/www/server/panel 不存在）"
        log_error "本脚本仅适用于宝塔面板环境，请先安装宝塔面板："
        echo "  Ubuntu/Debian: wget -O install.sh https://download.bt.cn/install/install-ubuntu_6.0.sh && bash install.sh"
        echo "  CentOS:        yum install -y wget && wget -O install.sh https://download.bt.cn/install/install_6.0.sh && sh install.sh"
        exit 1
    fi

    log_info "宝塔面板检测通过"
}

# ============ PHP 检测与配置 ============

detect_php_versions() {
    local versions=()
    if [ -d "$BT_PHP_BASE" ]; then
        for d in "$BT_PHP_BASE"/*; do
            if [ -d "$d" ] && [ -x "$d/bin/php" ]; then
                versions+=("$(basename "$d")")
            fi
        done
    fi
    echo "${versions[@]}"
}

select_php_version() {
    log_step "检测可用 PHP 版本"

    local versions
    versions=$(detect_php_versions)

    if [ -z "$versions" ]; then
        log_error "宝塔面板中未检测到任何 PHP"
        log_todo "请登录宝塔面板 → 软件商店 → 搜索 PHP → 安装 PHP 8.2 或 PHP 8.3"
        exit 1
    fi

    echo "已安装的 PHP 版本: $versions"
    echo ""

    local best=""
    for v in $versions; do
        if [ "$v" -ge 82 ] 2>/dev/null; then
            if [ -z "$best" ] || [ "$v" -gt "$best" ]; then
                best="$v"
            fi
        fi
    done

    if [ -z "$best" ]; then
        log_error "未检测到 PHP 8.2+ (本项目需要 PHP 8.2 或更高)"
        log_todo "请在宝塔面板安装 PHP 8.2 或 8.3"
        exit 1
    fi

    read -rp "请选择 PHP 版本 (直接回车使用推荐版本 $best): " input_ver
    PHP_VERSION=${input_ver:-$best}

    PHP_BIN="${BT_PHP_BASE}/${PHP_VERSION}/bin/php"
    if [ ! -x "$PHP_BIN" ]; then
        log_error "PHP 可执行文件不存在: $PHP_BIN"
        exit 1
    fi

    local php_real_ver
    php_real_ver=$("$PHP_BIN" -r 'echo PHP_VERSION;')
    log_info "使用 PHP: $php_real_ver ($PHP_BIN)"
}

check_php_extensions() {
    log_step "检测 PHP 扩展"

    local required=(fileinfo redis curl gd zip xml mbstring bcmath openssl pdo_mysql)
    local missing=()

    for ext in "${required[@]}"; do
        if "$PHP_BIN" -m | grep -qi "^${ext}$"; then
            log_info "扩展 $ext: 已安装"
        else
            log_warn "扩展 $ext: 缺失"
            missing+=("$ext")
        fi
    done

    if [ ${#missing[@]} -gt 0 ]; then
        log_error "缺少必要的 PHP 扩展: ${missing[*]}"
        echo ""
        log_todo "请按以下步骤安装（任选其一）："
        echo ""
        echo "  ${BOLD}方式 A - 宝塔面板 UI（推荐）：${NC}"
        echo "    1. 登录宝塔面板"
        echo "    2. 软件商店 → 已安装 → PHP-${PHP_VERSION} → 设置"
        echo "    3. 安装扩展 标签页 → 找到以下扩展点击「安装」："
        for ext in "${missing[@]}"; do
            echo "       - $ext"
        done
        echo ""
        echo "  ${BOLD}方式 B - 命令行（高级）：${NC}"
        echo "    cd ${BT_PHP_BASE}/${PHP_VERSION}/src"
        echo "    参考 ${BT_PHP_BASE}/${PHP_VERSION}/src/ext/<扩展名>/README 自行编译安装"
        echo ""
        read -rp "安装完扩展后按回车重新检测，或输入 skip 跳过: " ack
        if [ "$ack" != "skip" ]; then
            check_php_extensions
        fi
    fi
}

fix_disabled_functions() {
    log_step "修复 PHP 禁用函数"

    local php_ini="${BT_PHP_BASE}/${PHP_VERSION}/etc/php.ini"
    if [ ! -f "$php_ini" ]; then
        log_warn "未找到 php.ini: $php_ini"
        return
    fi

    local need_functions=(putenv proc_open pcntl_signal pcntl_alarm)
    local changed=0

    cp -a "$php_ini" "${php_ini}.dujiaoka.bak.$(date +%s)"

    for func in "${need_functions[@]}"; do
        if grep -E "^\s*disable_functions\s*=" "$php_ini" | grep -qw "$func"; then
            sed -i -E "s/(^\s*disable_functions\s*=[^#]*)\b${func}\b,?/\1/" "$php_ini"
            sed -i -E "s/,\s*,/,/g" "$php_ini"
            sed -i -E "s/(disable_functions\s*=\s*),/\1/" "$php_ini"
            sed -i -E "s/,\s*$//" "$php_ini"
            log_info "已从 disable_functions 移除: $func"
            changed=1
        fi
    done

    if [ "$changed" = "1" ]; then
        if systemctl list-units --type=service 2>/dev/null | grep -q "php-fpm-${PHP_VERSION}"; then
            systemctl restart "php-fpm-${PHP_VERSION}" 2>/dev/null || true
        fi
        if [ -f "/etc/init.d/php-fpm-${PHP_VERSION}" ]; then
            "/etc/init.d/php-fpm-${PHP_VERSION}" restart >/dev/null 2>&1 || true
        fi
        log_info "PHP-FPM 已重启"
    else
        log_info "无需修改"
    fi
}

# ============ Composer ============

setup_composer() {
    log_step "检测 / 安装 Composer"

    COMPOSER_BIN="${BT_PHP_BASE}/${PHP_VERSION}/bin/composer"

    if [ ! -x "$COMPOSER_BIN" ]; then
        if [ -x "/usr/local/bin/composer" ]; then
            COMPOSER_BIN="/usr/local/bin/composer"
        elif command -v composer >/dev/null 2>&1; then
            COMPOSER_BIN="$(command -v composer)"
        else
            log_info "未找到 composer，开始下载安装..."
            cd /tmp
            "$PHP_BIN" -r "copy('https://mirrors.aliyun.com/composer/composer.phar', '/usr/local/bin/composer');"
            chmod +x /usr/local/bin/composer
            COMPOSER_BIN="/usr/local/bin/composer"
        fi
    fi

    log_info "Composer: $COMPOSER_BIN"
    log_info "$("$PHP_BIN" "$COMPOSER_BIN" --version 2>&1 | head -1)"

    # 配置国内镜像以加速
    "$PHP_BIN" "$COMPOSER_BIN" config -g repos.packagist composer https://mirrors.aliyun.com/composer/ >/dev/null 2>&1 || true
    log_info "已配置阿里云 Composer 镜像"
}

# ============ 站点 / 代码 ============

get_site_input() {
    log_step "站点信息确认"

    # 如果当前目录就是 Laravel 项目根目录，直接用当前目录
    if [ -f "$(pwd)/artisan" ] && [ -f "$(pwd)/composer.json" ]; then
        SITE_PATH="$(pwd)"
        log_info "检测到当前目录为项目根目录: $SITE_PATH"
    else
        echo "请输入站点域名或完整路径。例如："
        echo "  - 域名：shop.example.com  (将使用 /www/wwwroot/shop.example.com)"
        echo "  - 路径：/www/wwwroot/myshop"
        read -rp "站点域名或路径: " input_site
        if [ -z "$input_site" ]; then
            log_error "输入不能为空"
            exit 1
        fi
        if [[ "$input_site" == /* ]]; then
            SITE_PATH="$input_site"
        else
            SITE_PATH="${BT_WWW}/${input_site}"
            DOMAIN="$input_site"
        fi
    fi

    if [ -z "$DOMAIN" ]; then
        DOMAIN="$(basename "$SITE_PATH")"
    fi

    log_info "站点路径: $SITE_PATH"
    log_info "站点域名: $DOMAIN"

    if [ ! -d "$SITE_PATH" ]; then
        log_error "站点目录不存在: $SITE_PATH"
        log_todo "请先在宝塔面板 → 网站 → 添加站点，创建该站点"
        exit 1
    fi
}

download_source() {
    log_step "检查源代码"

    if [ -f "$SITE_PATH/artisan" ] && [ -f "$SITE_PATH/composer.json" ]; then
        log_info "源代码已存在，跳过下载"
        return
    fi

    log_info "目录为空或缺少源码，准备下载..."

    if ! command -v git >/dev/null 2>&1; then
        log_info "安装 git..."
        if command -v apt-get >/dev/null 2>&1; then
            apt-get install -y git
        elif command -v yum >/dev/null 2>&1; then
            yum install -y git
        fi
    fi

    cd "$SITE_PATH"
    # 保留宝塔建站时自动生成的默认文件（如 404.html），只清理明显占位文件
    rm -f index.html 2>/dev/null || true

    if [ -n "$(ls -A "$SITE_PATH" 2>/dev/null)" ]; then
        log_warn "目标目录非空，将拉取到临时目录后移动"
        local tmp_dir="/tmp/dujiaoka_$$"
        git clone --depth=1 "$REPO_URL" "$tmp_dir"
        shopt -s dotglob
        mv "$tmp_dir"/* "$SITE_PATH/" 2>/dev/null || true
        shopt -u dotglob
        rm -rf "$tmp_dir"
    else
        git clone --depth=1 "$REPO_URL" "$SITE_PATH"
    fi

    log_info "源代码下载完成"
}

# ============ 数据库 ============

get_db_input() {
    log_step "数据库信息"

    echo "请输入宝塔面板创建站点时绑定的数据库信息："
    echo "（位置：宝塔面板 → 数据库 → 对应数据库右侧「管理」）"
    echo ""

    read -rp "数据库主机 [127.0.0.1]: " input
    DB_HOST=${input:-127.0.0.1}

    read -rp "数据库端口 [3306]: " input
    DB_PORT=${input:-3306}

    while true; do
        read -rp "数据库名: " DB_NAME
        [ -n "$DB_NAME" ] && break
        log_error "数据库名不能为空"
    done

    while true; do
        read -rp "数据库用户名: " DB_USER
        [ -n "$DB_USER" ] && break
        log_error "数据库用户名不能为空"
    done

    while true; do
        read -rsp "数据库密码: " DB_PASS
        echo
        [ -n "$DB_PASS" ] && break
        log_error "数据库密码不能为空"
    done
}

test_db_connection() {
    log_step "测试数据库连接"

    local result
    result=$("$PHP_BIN" -r "
        try {
            \$pdo = new PDO('mysql:host=${DB_HOST};port=${DB_PORT};dbname=${DB_NAME}', '${DB_USER}', '${DB_PASS}');
            echo 'OK';
        } catch (Exception \$e) {
            echo 'ERR: ' . \$e->getMessage();
        }
    " 2>&1)

    if [[ "$result" == "OK" ]]; then
        log_info "数据库连接成功"
    else
        log_error "数据库连接失败: $result"
        log_todo "请检查："
        echo "  1. 宝塔面板 → 数据库 → 数据库是否存在"
        echo "  2. 数据库用户名/密码是否正确"
        echo "  3. 数据库是否允许 ${DB_HOST} 连接（默认 localhost）"
        exit 1
    fi
}

# ============ Redis ============

detect_redis() {
    log_step "检测 Redis"

    local result
    result=$("$PHP_BIN" -r "
        try {
            \$r = new Redis();
            \$r->connect('${REDIS_HOST}', ${REDIS_PORT}, 2);
            echo 'OK';
        } catch (Exception \$e) {
            echo 'ERR';
        }
    " 2>&1)

    if [[ "$result" == "OK" ]]; then
        log_info "Redis 连接成功 (${REDIS_HOST}:${REDIS_PORT})"
    else
        log_warn "Redis 未启动或无法连接（非致命，可稍后配置）"
        log_todo "请在宝塔面板 → 软件商店 → 已安装 → Redis → 启动"
    fi
}

# ============ .env ============

setup_env() {
    log_step "配置 .env"

    cd "$SITE_PATH"

    if [ ! -f .env ]; then
        if [ -f .env.default ]; then
            cp .env.default .env
            log_info "已从 .env.default 创建 .env"
        elif [ -f .env.example ]; then
            cp .env.example .env
            log_info "已从 .env.example 创建 .env"
        else
            log_error "未找到 .env 模板"
            exit 1
        fi
    else
        log_info ".env 已存在，将更新 DB 配置"
        cp .env ".env.bak.$(date +%s)"
    fi

    # 更新 DB 配置
    env_set() {
        local key="$1"
        local value="$2"
        local escaped
        escaped=$(printf '%s' "$value" | sed -e 's/[\/&|]/\\&/g')
        if grep -qE "^\s*${key}\s*=" .env; then
            sed -i -E "s|^\s*${key}\s*=.*|${key}=${escaped}|" .env
        else
            echo "${key}=${value}" >> .env
        fi
    }

    env_set "APP_URL"     "https://${DOMAIN}"
    env_set "DB_HOST"     "$DB_HOST"
    env_set "DB_PORT"     "$DB_PORT"
    env_set "DB_DATABASE" "$DB_NAME"
    env_set "DB_USERNAME" "$DB_USER"
    env_set "DB_PASSWORD" "$DB_PASS"
    env_set "REDIS_HOST"  "$REDIS_HOST"
    env_set "REDIS_PORT"  "$REDIS_PORT"

    log_info ".env 配置已写入"
}

# ============ Composer 安装 ============

install_dependencies() {
    log_step "安装 Composer 依赖"

    cd "$SITE_PATH"

    # 安装前先把目录所有权交给 www，确保 composer 可写
    chown -R "${BT_USER}:${BT_GROUP}" "$SITE_PATH"
    mkdir -p "$SITE_PATH/storage/logs" "$SITE_PATH/bootstrap/cache"
    chmod -R 775 "$SITE_PATH/storage" "$SITE_PATH/bootstrap/cache"

    # 关键：先 --no-scripts 安装，避免 filament:upgrade 在 key 未生成/DB 未迁移前报错
    log_info "第 1 步：安装依赖（跳过 post 脚本）..."
    if ! sudo -u "$BT_USER" -H \
        env COMPOSER_ALLOW_SUPERUSER=0 COMPOSER_HOME=/tmp/composer-$$ \
        "$PHP_BIN" "$COMPOSER_BIN" install \
            --no-dev \
            --optimize-autoloader \
            --no-interaction \
            --no-scripts \
            --working-dir="$SITE_PATH"; then
        log_error "Composer 安装失败，请查看上方错误信息"
        exit 1
    fi

    log_info "依赖安装完成"
}

setup_app_key() {
    log_step "生成应用密钥"

    cd "$SITE_PATH"

    local cur_key
    cur_key=$(grep -E "^APP_KEY=" .env | cut -d= -f2-)
    if [ -z "$cur_key" ] || [ "$cur_key" = "" ]; then
        sudo -u "$BT_USER" "$PHP_BIN" artisan key:generate --force
        log_info "APP_KEY 已生成"
    else
        log_info "APP_KEY 已存在，跳过"
    fi
}

init_database() {
    # 独角数卡不使用 Laravel migration，全部表结构 + 初始数据在
    # database/sql/install.sql 里一次性建立。这里直接用 mysql 客户端
    # 导入 SQL，与 Web 安装向导（Installer::install）保持同一语义。
    log_step "初始化数据库（导入 install.sql）"

    cd "$SITE_PATH"

    local install_sql="$SITE_PATH/database/sql/install.sql"
    local install_lock="$SITE_PATH/install.lock"

    if [ -f "$install_lock" ]; then
        log_info "install.lock 已存在，跳过数据库初始化"
        return 0
    fi

    if [ ! -f "$install_sql" ]; then
        log_error "未找到 $install_sql，请确认源代码完整"
        exit 1
    fi

    local mysql_bin=""
    if command -v mysql >/dev/null 2>&1; then
        mysql_bin=$(command -v mysql)
    elif [ -x "/www/server/mysql/bin/mysql" ]; then
        mysql_bin="/www/server/mysql/bin/mysql"
    else
        log_error "未找到 mysql 客户端（请确认宝塔 MySQL 已安装并在 PATH 中）"
        exit 1
    fi
    log_info "使用 mysql 客户端: $mysql_bin"

    # 幂等检查：如果 settings 表已存在，视为已初始化，直接打锁
    local settings_exists
    settings_exists=$(MYSQL_PWD="$DB_PASS" "$mysql_bin" \
        -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" \
        -sN -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}' AND table_name='settings';" 2>/dev/null || echo 0)

    if [ "${settings_exists:-0}" -gt 0 ]; then
        log_warn "数据库 ${DB_NAME} 中已存在 settings 表，视为已初始化"
        log_warn "如需全新安装，请先手动清空数据库中所有表后再运行本脚本"
        touch "$install_lock"
        chown "${BT_USER}:${BT_GROUP}" "$install_lock"
        return 0
    fi

    log_info "开始导入 install.sql ..."
    if MYSQL_PWD="$DB_PASS" "$mysql_bin" \
        -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "$DB_NAME" \
        --default-character-set=utf8mb4 \
        < "$install_sql"; then
        log_info "数据库初始化完成"
    else
        log_error "install.sql 导入失败，请检查数据库账号权限与字符集"
        exit 1
    fi

    touch "$install_lock"
    chown "${BT_USER}:${BT_GROUP}" "$install_lock"
    log_info "已创建 install.lock 锁文件（阻止二次访问 /install 向导）"
}

finalize_filament() {
    log_step "Filament 升级与缓存"

    cd "$SITE_PATH"

    sudo -u "$BT_USER" "$PHP_BIN" artisan filament:upgrade 2>/dev/null || log_warn "filament:upgrade 跳过"
    sudo -u "$BT_USER" "$PHP_BIN" artisan storage:link 2>/dev/null || true
    sudo -u "$BT_USER" "$PHP_BIN" artisan config:cache
    sudo -u "$BT_USER" "$PHP_BIN" artisan route:cache 2>/dev/null || true
    sudo -u "$BT_USER" "$PHP_BIN" artisan view:cache 2>/dev/null || true
    log_info "缓存构建完成"
}

# ============ 权限 ============

fix_permissions() {
    log_step "修复目录权限"

    chown -R "${BT_USER}:${BT_GROUP}" "$SITE_PATH"
    find "$SITE_PATH" -type d -exec chmod 755 {} \;
    find "$SITE_PATH" -type f -exec chmod 644 {} \;
    chmod -R 775 "$SITE_PATH/storage" "$SITE_PATH/bootstrap/cache"
    chmod +x "$SITE_PATH/artisan"
    log_info "权限修复完成 (${BT_USER}:${BT_GROUP})"
}

# ============ Supervisor 队列 ============

setup_supervisor() {
    log_step "配置队列守护进程"

    read -rp "是否配置队列 Supervisor 守护进程? (y/n) [y]: " ans
    ans=${ans:-y}
    if [[ ! "$ans" =~ ^[Yy]$ ]]; then
        log_warn "已跳过队列配置，后续请手动配置"
        return
    fi

    local bt_sup_dir="/www/server/panel/plugin/supervisor"
    local sys_sup_dir="/etc/supervisor/conf.d"
    local sup_conf=""

    if [ -d "$bt_sup_dir" ] && [ -d "/etc/supervisord.d" ]; then
        sup_conf="/etc/supervisord.d/dujiaoka-${DOMAIN}.ini"
    elif [ -d "$sys_sup_dir" ]; then
        sup_conf="${sys_sup_dir}/dujiaoka-${DOMAIN}.conf"
    elif [ -d "/etc/supervisord.d" ]; then
        sup_conf="/etc/supervisord.d/dujiaoka-${DOMAIN}.ini"
    else
        log_warn "未检测到 Supervisor，跳过队列配置"
        log_todo "请在宝塔面板 → 软件商店 → 搜索「Supervisor 管理器」→ 安装"
        log_todo "安装后重新运行本脚本，或手动添加队列进程："
        echo "   运行用户: www"
        echo "   运行目录: $SITE_PATH"
        echo "   启动命令: $PHP_BIN artisan queue:work --sleep=3 --tries=3 --max-time=3600"
        return
    fi

    cat > "$sup_conf" <<SUP_EOF
[program:dujiaoka-${DOMAIN}]
process_name=%(program_name)s_%(process_num)02d
command=${PHP_BIN} ${SITE_PATH}/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=${BT_USER}
numprocs=1
redirect_stderr=true
stdout_logfile=${SITE_PATH}/storage/logs/worker.log
stopwaitsecs=3600
SUP_EOF

    mkdir -p "$SITE_PATH/storage/logs"
    chown -R "${BT_USER}:${BT_GROUP}" "$SITE_PATH/storage/logs"

    if command -v supervisorctl >/dev/null 2>&1; then
        supervisorctl reread >/dev/null 2>&1 || true
        supervisorctl update >/dev/null 2>&1 || true
        supervisorctl start "dujiaoka-${DOMAIN}:*" >/dev/null 2>&1 || true
    fi

    log_info "队列配置已写入: $sup_conf"
    log_todo "可登录宝塔面板 → Supervisor 管理器 查看进程状态"
}

# ============ 完成提示 ============

print_final() {
    echo ""
    echo -e "${GREEN}${BOLD}============================================${NC}"
    echo -e "${GREEN}${BOLD}    独角数卡 部署完成！${NC}"
    echo -e "${GREEN}${BOLD}============================================${NC}"
    echo ""
    echo -e "  访问地址:   ${CYAN}https://${DOMAIN}${NC}"
    echo -e "  后台地址:   ${CYAN}https://${DOMAIN}/admin${NC}"
    echo -e "  默认账号:   ${YELLOW}admin / admin${NC}  ${RED}(请立即修改！)${NC}"
    echo ""
    echo -e "  站点目录:   $SITE_PATH"
    echo -e "  PHP 版本:   $PHP_VERSION ($PHP_BIN)"
    echo -e "  数据库:     ${DB_USER}@${DB_HOST}:${DB_PORT}/${DB_NAME}"
    echo ""
    log_todo "后续配置清单："
    echo "  1. 宝塔面板 → 网站 → 你的站点 → 网站目录 → 运行目录改为 ${BOLD}/public${NC}"
    echo "  2. 宝塔面板 → 网站 → 你的站点 → 伪静态 → 选择 ${BOLD}laravel5${NC}"
    echo "  3. 宝塔面板 → 网站 → 你的站点 → SSL → 申请 Let's Encrypt 或上传证书"
    echo "  4. 登录 /admin 后修改默认密码"
    echo ""
}

# ============ 主流程 ============

main() {
    clear
    echo -e "${CYAN}${BOLD}"
    cat <<'BANNER'
  ____        _ _                    _
 |  _ \ _   _(_|_) __ _  ___   ___  | | ____ _
 | | | | | | | | |/ _` |/ _ \ / _ \ | |/ / _` |
 | |_| | |_| | | | (_| | (_) | (_) ||   < (_| |
 |____/ \__,_| |_|\__,_|\___/ \___/ |_|\_\__,_|
            |__/    宝塔一键部署脚本
BANNER
    echo -e "${NC}"

    check_root
    check_bt_panel
    select_php_version
    check_php_extensions
    fix_disabled_functions
    setup_composer
    get_site_input
    download_source
    get_db_input
    test_db_connection
    detect_redis
    setup_env
    install_dependencies
    init_database
    setup_app_key
    finalize_filament
    fix_permissions
    setup_supervisor
    print_final
}

main "$@"
