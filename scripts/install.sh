#!/bin/bash
set -euo pipefail

# ============================================================
#  独角数卡 - 一键安装脚本 v2
#  支持系统: Ubuntu 20.04+ / Debian 11+
#  流程: 探查 → 规划 → 确认 → 应用 → 验证 → 部署
# ============================================================

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

REPO_URL="https://github.com/tianyou-lab/dujiaoka.git"
WEB_ROOT="/var/www/dujiaoka"
PHP_VERSION="8.3"
REQ_MARIADB_MIN="10.6"
REQ_NGINX_MIN="1.18"
REQ_REDIS_MIN="6.0"
REQ_COMPOSER_MIN="2.0"

# 需求扩展列表（Laravel + Filament + dujiaoka 业务）
REQ_PHP_EXT=(fileinfo redis gd curl zip xml mbstring bcmath intl dom mysql opcache pcntl)
# 必须启用的 PHP 函数（禁用会导致运行时错误）
REQ_PHP_FUNC=(putenv proc_open pcntl_signal pcntl_alarm exec)

# 规划阶段累积的动作清单
declare -a PLAN_INSTALL=()
declare -a PLAN_UPGRADE=()
declare -a PLAN_CONFIGURE=()
declare -a PLAN_NOTES=()

log_info()  { echo -e "${GREEN}[INFO]${NC} $1"; }
log_warn()  { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }
log_step()  { echo -e "\n${CYAN}${BOLD}======== $1 ========${NC}\n"; }
log_ok()    { echo -e "  ${GREEN}✓${NC} $1"; }
log_miss()  { echo -e "  ${RED}✗${NC} $1"; }
log_todo()  { echo -e "  ${YELLOW}➜${NC} $1"; }

# =================================================================
# 阶段 0：环境前置检查
# =================================================================

check_root() {
    if [ "$(id -u)" -ne 0 ]; then
        log_error "请使用 root 用户运行此脚本"
        exit 1
    fi
}

check_os() {
    if [ ! -f /etc/os-release ]; then
        log_error "无法检测操作系统（/etc/os-release 不存在）"
        exit 1
    fi
    . /etc/os-release

    case "$ID" in
        ubuntu)
            local ver_num
            ver_num=$(awk -F. '{printf "%d%02d", $1, $2}' <<<"${VERSION_ID:-0.0}")
            if [ "${ver_num:-0}" -lt 2004 ]; then
                log_error "Ubuntu 版本过低: ${VERSION_ID}，最低要求 Ubuntu 20.04"
                exit 1
            fi
            OS_KIND="ubuntu"
            OS_CODENAME="${UBUNTU_CODENAME:-${VERSION_CODENAME:-unknown}}"
            ;;
        debian)
            local ver_major="${VERSION_ID:-0}"
            if [ "${ver_major:-0}" -lt 11 ]; then
                log_error "Debian 版本过低: ${VERSION_ID}，最低要求 Debian 11"
                exit 1
            fi
            OS_KIND="debian"
            OS_CODENAME="${VERSION_CODENAME:-unknown}"
            ;;
        *)
            log_error "不支持的操作系统: $ID ($PRETTY_NAME)"
            log_error "本脚本仅支持 Ubuntu 20.04+ 和 Debian 11+"
            exit 1
            ;;
    esac
}

# =================================================================
# 阶段 1：版本比较工具
# =================================================================

# 比较两个版本号：version_ge "1.2.3" "1.2"  → 返回 0 表示前者 >= 后者
version_ge() {
    [ "$(printf '%s\n%s\n' "$2" "$1" | sort -V | head -n1)" = "$2" ]
}

# =================================================================
# 阶段 2：探查当前服务器状态
# =================================================================

# 全局状态表（每个 key 对应一个服务/工具）
#   STATE[key]=status            # ok / missing / outdated / misconfigured
#   DETAIL[key]=detail string    # 供显示用的详情
declare -A STATE=()
declare -A DETAIL=()

detect_state() {
    log_step "探查服务器当前状态"

    # ---------- 操作系统 ----------
    STATE[os]="ok"
    DETAIL[os]="${PRETTY_NAME} ($OS_KIND / $OS_CODENAME)"

    # ---------- Nginx ----------
    if command -v nginx >/dev/null 2>&1; then
        local v
        v=$(nginx -v 2>&1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1)
        if version_ge "${v:-0}" "$REQ_NGINX_MIN"; then
            STATE[nginx]="ok"
            DETAIL[nginx]="v${v}"
        else
            STATE[nginx]="outdated"
            DETAIL[nginx]="v${v} (需 >= $REQ_NGINX_MIN)"
        fi
    else
        STATE[nginx]="missing"
        DETAIL[nginx]="未安装"
    fi

    # ---------- MariaDB / MySQL ----------
    local mysql_bin=""
    if command -v mariadb >/dev/null 2>&1; then
        mysql_bin="mariadb"
    elif command -v mysql >/dev/null 2>&1; then
        mysql_bin="mysql"
    fi
    if [ -n "$mysql_bin" ]; then
        local v
        v=$("$mysql_bin" --version 2>&1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1)
        if [ -n "$v" ] && version_ge "$v" "$REQ_MARIADB_MIN"; then
            STATE[mariadb]="ok"
            DETAIL[mariadb]="${mysql_bin} v${v}"
        else
            STATE[mariadb]="outdated"
            DETAIL[mariadb]="${mysql_bin} v${v:-?} (需 >= $REQ_MARIADB_MIN)"
        fi
    else
        STATE[mariadb]="missing"
        DETAIL[mariadb]="未安装"
    fi

    # ---------- PHP ----------
    local php_bin="/usr/bin/php${PHP_VERSION}"
    if [ -x "$php_bin" ]; then
        local v
        v=$("$php_bin" -r 'echo PHP_VERSION;')
        STATE[php]="ok"
        DETAIL[php]="v${v} ($php_bin)"
        PHP_BIN="$php_bin"

        # 探查扩展：用 extension_loaded() 比 `php -m` 输出可靠
        # （`Zend OPcache` 这种带空格/混合大小写的名字 -m 里输出和 load 名不同）
        local missing_ext=()
        for ext in "${REQ_PHP_EXT[@]}"; do
            local probe="$ext"
            [ "$ext" = "mysql" ] && probe="pdo_mysql"
            [ "$ext" = "dom" ]   && probe="dom"
            if ! "$php_bin" -r "exit(extension_loaded('${probe}') ? 0 : 1);" >/dev/null 2>&1; then
                missing_ext+=("$ext")
            fi
        done
        if [ ${#missing_ext[@]} -gt 0 ]; then
            STATE[php_ext]="missing"
            DETAIL[php_ext]="缺失扩展: ${missing_ext[*]}"
            MISSING_PHP_EXT=("${missing_ext[@]}")
        else
            STATE[php_ext]="ok"
            DETAIL[php_ext]="全部 ${#REQ_PHP_EXT[@]} 个扩展已加载"
            MISSING_PHP_EXT=()
        fi

        # 探查禁用函数
        local disabled
        disabled=$("$php_bin" -r 'echo ini_get("disable_functions") ?: "";')
        local bad_funcs=()
        for func in "${REQ_PHP_FUNC[@]}"; do
            if [[ ",$disabled," == *",$func,"* ]]; then
                bad_funcs+=("$func")
            fi
        done
        if [ ${#bad_funcs[@]} -gt 0 ]; then
            STATE[php_func]="misconfigured"
            DETAIL[php_func]="被禁用: ${bad_funcs[*]}"
            BAD_PHP_FUNC=("${bad_funcs[@]}")
        else
            STATE[php_func]="ok"
            DETAIL[php_func]="所需函数均可用"
            BAD_PHP_FUNC=()
        fi
    else
        STATE[php]="missing"
        DETAIL[php]="未安装 php${PHP_VERSION}"
        STATE[php_ext]="missing"
        DETAIL[php_ext]="(PHP 未装，无法检测)"
        STATE[php_func]="missing"
        DETAIL[php_func]="(PHP 未装，无法检测)"
        MISSING_PHP_EXT=("${REQ_PHP_EXT[@]}")
        BAD_PHP_FUNC=()
        PHP_BIN=""
    fi

    # ---------- Redis ----------
    if command -v redis-server >/dev/null 2>&1; then
        local v
        v=$(redis-server --version 2>&1 | grep -oE 'v=[0-9]+\.[0-9]+\.[0-9]+' | cut -d= -f2 | head -1)
        if [ -n "$v" ] && version_ge "$v" "$REQ_REDIS_MIN"; then
            STATE[redis]="ok"
            DETAIL[redis]="v${v}"
        else
            STATE[redis]="outdated"
            DETAIL[redis]="v${v:-?} (需 >= $REQ_REDIS_MIN)"
        fi
    else
        STATE[redis]="missing"
        DETAIL[redis]="未安装"
    fi

    # ---------- Composer ----------
    if command -v composer >/dev/null 2>&1; then
        local v
        v=$(composer --version 2>&1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1)
        if [ -n "$v" ] && version_ge "$v" "$REQ_COMPOSER_MIN"; then
            STATE[composer]="ok"
            DETAIL[composer]="v${v}"
        else
            STATE[composer]="outdated"
            DETAIL[composer]="v${v:-?} (需 >= $REQ_COMPOSER_MIN)"
        fi
    else
        STATE[composer]="missing"
        DETAIL[composer]="未安装"
    fi

    # ---------- Supervisor ----------
    if command -v supervisord >/dev/null 2>&1; then
        STATE[supervisor]="ok"
        DETAIL[supervisor]="已安装"
    else
        STATE[supervisor]="missing"
        DETAIL[supervisor]="未安装（队列守护进程需要）"
    fi

    # ---------- Git ----------
    if command -v git >/dev/null 2>&1; then
        STATE[git]="ok"
        DETAIL[git]="已安装"
    else
        STATE[git]="missing"
        DETAIL[git]="未安装（拉取源码需要）"
    fi

    # ---------- 打印报告 ----------
    echo ""
    printf "  %-14s %-16s %s\n" "组件" "状态" "详情"
    printf "  %-14s %-16s %s\n" "────────" "────" "────────"
    for key in os nginx mariadb php php_ext php_func redis composer supervisor git; do
        local s="${STATE[$key]:-unknown}"
        local color="$GREEN"
        case "$s" in
            ok)             color="$GREEN" ;;
            missing)        color="$RED" ;;
            outdated)       color="$YELLOW" ;;
            misconfigured)  color="$YELLOW" ;;
        esac
        printf "  %-14s ${color}%-16s${NC} %s\n" "$key" "$s" "${DETAIL[$key]:-}"
    done
    echo ""
}

# =================================================================
# 阶段 3：根据探查结果构建动作清单
# =================================================================

build_plan() {
    log_step "生成安装计划"

    [ "${STATE[nginx]}" != "ok" ]      && PLAN_INSTALL+=("nginx")
    [ "${STATE[mariadb]}" != "ok" ]    && PLAN_INSTALL+=("mariadb-server" "mariadb-client")
    [ "${STATE[redis]}" != "ok" ]      && PLAN_INSTALL+=("redis-server")
    [ "${STATE[supervisor]}" != "ok" ] && PLAN_INSTALL+=("supervisor")
    [ "${STATE[git]}" != "ok" ]        && PLAN_INSTALL+=("git" "unzip")

    if [ "${STATE[php]}" != "ok" ]; then
        PLAN_INSTALL+=("php${PHP_VERSION}" "php${PHP_VERSION}-fpm")
    fi

    # PHP 扩展 → apt 包
    # 映射规则：
    #   pcntl   → 无需单独 apt 包（Sury/Ondrej 的 php${V}-cli 已内置）
    #   dom     → 由 php${V}-xml 提供（XML 扩展包含 DOM）
    #   mysql   → php${V}-mysql（PDO_MYSQL 在这个包里）
    #   其他    → php${V}-<ext>
    if [ "${STATE[php_ext]:-}" = "missing" ] && [ ${#MISSING_PHP_EXT[@]} -gt 0 ]; then
        for ext in "${MISSING_PHP_EXT[@]}"; do
            case "$ext" in
                pcntl)    continue ;;
                dom|xml)  PLAN_INSTALL+=("php${PHP_VERSION}-xml") ;;
                *)        PLAN_INSTALL+=("php${PHP_VERSION}-${ext}") ;;
            esac
        done
    fi

    # PHP 禁用函数 → 需要修改 php.ini
    if [ "${STATE[php_func]:-}" = "misconfigured" ]; then
        PLAN_CONFIGURE+=("解禁 PHP 函数: ${BAD_PHP_FUNC[*]}")
    fi

    # Composer
    if [ "${STATE[composer]}" != "ok" ]; then
        PLAN_CONFIGURE+=("通过 getcomposer.org 安装 Composer ${REQ_COMPOSER_MIN}+")
    fi

    # 站点部署始终要做的事
    PLAN_CONFIGURE+=("克隆/更新源码到 ${WEB_ROOT}")
    PLAN_CONFIGURE+=("composer install --no-dev")
    PLAN_CONFIGURE+=("初始化数据库（建库、导入 install.sql）")
    PLAN_CONFIGURE+=("生成 .env 并写入 APP_KEY")
    PLAN_CONFIGURE+=("创建 install.lock")
    PLAN_CONFIGURE+=("配置 Nginx 站点 + 伪静态")
    [[ "${APPLY_SSL:-n}" =~ ^[Yy]$ ]] && PLAN_CONFIGURE+=("申请 Let's Encrypt SSL 证书 (certbot)")
    PLAN_CONFIGURE+=("配置 Supervisor 队列守护进程")

    # 去重 PLAN_INSTALL
    if [ ${#PLAN_INSTALL[@]} -gt 0 ]; then
        mapfile -t PLAN_INSTALL < <(printf '%s\n' "${PLAN_INSTALL[@]}" | awk '!seen[$0]++')
    fi

    # ---------- 打印规划 ----------
    echo -e "${BOLD}将要进行的动作：${NC}"
    echo ""

    if [ ${#PLAN_INSTALL[@]} -gt 0 ]; then
        echo -e "${CYAN}[apt 安装包]${NC}"
        for p in "${PLAN_INSTALL[@]}"; do
            log_todo "apt install $p"
        done
        echo ""
    else
        log_ok "所有 apt 包均已就绪，无需安装"
        echo ""
    fi

    echo -e "${CYAN}[配置与部署]${NC}"
    for c in "${PLAN_CONFIGURE[@]}"; do
        log_todo "$c"
    done
    echo ""

    read -rp "确认执行以上计划? (y/n) [y]: " PLAN_OK
    PLAN_OK=${PLAN_OK:-y}
    if [[ ! "$PLAN_OK" =~ ^[Yy]$ ]]; then
        log_info "已取消"
        exit 0
    fi
}

# =================================================================
# 阶段 4：执行安装 / 升级
# =================================================================

prepare_apt() {
    export DEBIAN_FRONTEND=noninteractive
    apt update -y --allow-releaseinfo-change

    # 基础工具：curl wget git unzip 等。software-properties-common 仅 Ubuntu 需要
    # （提供 add-apt-repository 命令），Debian minimal 镜像可能没有该包
    local base=(curl wget unzip lsb-release apt-transport-https ca-certificates gnupg)
    if [ "$OS_KIND" = "ubuntu" ]; then
        base+=(software-properties-common)
    fi
    apt install -y "${base[@]}"
}

add_php_repo() {
    if [ "$OS_KIND" = "debian" ]; then
        local sury_list="/etc/apt/sources.list.d/sury-php.list"
        if [ ! -f "$sury_list" ]; then
            log_info "添加 Sury PHP 仓库（codename=${OS_CODENAME}）..."
            wget -qO- https://packages.sury.org/php/apt.gpg \
                | gpg --dearmor -o /usr/share/keyrings/sury-php.gpg 2>/dev/null
            echo "deb [signed-by=/usr/share/keyrings/sury-php.gpg] https://packages.sury.org/php/ ${OS_CODENAME} main" \
                > "$sury_list"
            apt update -y
        fi
    else
        if ! grep -rqs "ondrej/php" /etc/apt/sources.list /etc/apt/sources.list.d/ 2>/dev/null; then
            log_info "添加 Ondrej PHP PPA..."
            add-apt-repository -y ppa:ondrej/php
            apt update -y
        fi
    fi
}

apply_plan() {
    if [ ${#PLAN_INSTALL[@]} -eq 0 ]; then
        log_info "apt 包已齐全，跳过安装阶段"
        return 0
    fi

    log_step "应用 apt 安装计划"

    # 如果包含 PHP 包，先加 Sury/Ondrej 源
    local has_php=0
    for p in "${PLAN_INSTALL[@]}"; do
        [[ "$p" == php${PHP_VERSION}* ]] && has_php=1 && break
    done
    [ "$has_php" = "1" ] && add_php_repo

    log_info "apt install -y ${PLAN_INSTALL[*]}"
    if ! apt install -y "${PLAN_INSTALL[@]}"; then
        log_error "apt 安装失败。诊断信息："
        echo ""
        log_error "可用的 PHP 包："
        apt-cache search "^php[0-9]+\.[0-9]+$" 2>/dev/null || true
        log_error ""
        log_error "常见原因："
        log_error "  1. Sury/Ondrej 源未生效（检查 /etc/apt/sources.list.d/ 下）"
        log_error "  2. 镜像源没同步该 codename → 换成官方源重试（参考下方命令）"
        log_error "  3. 包名在当前发行版下不一致 → 手动确认 apt-cache search 结果"
        echo ""
        log_error "切回 Debian 官方源命令："
        echo "    cat > /etc/apt/sources.list <<EOF"
        echo "    deb http://deb.debian.org/debian ${OS_CODENAME} main contrib non-free non-free-firmware"
        echo "    deb http://deb.debian.org/debian ${OS_CODENAME}-updates main contrib non-free non-free-firmware"
        echo "    deb http://security.debian.org/debian-security ${OS_CODENAME}-security main contrib non-free non-free-firmware"
        echo "    EOF"
        echo "    apt update --allow-releaseinfo-change && apt install -y ${PLAN_INSTALL[*]}"
        exit 1
    fi

    log_info "apt 安装完成"
}

fix_php_config() {
    if [ ${#BAD_PHP_FUNC[@]} -eq 0 ]; then
        return 0
    fi

    log_step "解禁 PHP 函数"

    local php_ini="/etc/php/${PHP_VERSION}/fpm/php.ini"
    local cli_ini="/etc/php/${PHP_VERSION}/cli/php.ini"
    for ini in "$php_ini" "$cli_ini"; do
        [ -f "$ini" ] || continue
        cp -a "$ini" "${ini}.dujiaoka.bak.$(date +%s)"
        for func in "${BAD_PHP_FUNC[@]}"; do
            sed -i -E "s/(^\s*disable_functions\s*=[^#]*)\b${func}\b,?/\1/" "$ini"
        done
        sed -i -E 's/,\s*,/,/g; s/(disable_functions\s*=\s*),/\1/; s/,\s*$//' "$ini"
        log_info "已处理: $ini"
    done

    systemctl restart "php${PHP_VERSION}-fpm" 2>/dev/null || true
    log_info "php${PHP_VERSION}-fpm 已重启"
}

install_composer_if_missing() {
    if [ "${STATE[composer]}" = "ok" ]; then
        return 0
    fi
    log_step "安装 Composer"
    cd /tmp
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    php composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
    rm -f composer-setup.php
    log_info "Composer 已安装到 /usr/local/bin/composer"
}

# =================================================================
# 阶段 5：再校验
# =================================================================

verify_after_apply() {
    log_step "验证依赖是否全部就绪"

    detect_state  # 重新探查

    local failed=0
    for key in nginx mariadb php php_ext php_func redis composer supervisor git; do
        local s="${STATE[$key]:-missing}"
        if [ "$s" != "ok" ]; then
            log_miss "$key: ${DETAIL[$key]}"
            failed=1
        fi
    done

    if [ "$failed" = "1" ]; then
        log_error "部分依赖仍未就绪，请查看上方表格排查后再跑一次脚本"
        exit 1
    fi

    log_info "所有依赖就绪，进入应用部署阶段"
}

# =================================================================
# 阶段 6：应用部署
# =================================================================

get_user_input() {
    echo ""
    echo -e "${CYAN}${BOLD}============================================${NC}"
    echo -e "${CYAN}${BOLD}       独角数卡 一键安装向导${NC}"
    echo -e "${CYAN}${BOLD}============================================${NC}"
    echo ""

    read -rp "请输入你的域名 (如 shop.example.com): " DOMAIN
    [ -z "$DOMAIN" ] && { log_error "域名不能为空"; exit 1; }

    read -rp "请输入数据库名称 [dujiaoka]: " DB_NAME
    DB_NAME=${DB_NAME:-dujiaoka}

    read -rp "请输入数据库用户名 [dujiaoka]: " DB_USER
    DB_USER=${DB_USER:-dujiaoka}

    while true; do
        read -rsp "请输入数据库密码（至少 8 位）: " DB_PASS
        echo
        if [ -z "$DB_PASS" ]; then
            log_error "数据库密码不能为空"; continue
        elif [ ${#DB_PASS} -lt 8 ]; then
            log_error "数据库密码至少 8 位"; continue
        fi
        break
    done

    read -rp "是否自动申请 Let's Encrypt SSL 证书? (y/n) [y]: " APPLY_SSL
    APPLY_SSL=${APPLY_SSL:-y}
    if [[ "$APPLY_SSL" =~ ^[Yy]$ ]]; then
        read -rp "请输入用于 SSL 证书的邮箱: " SSL_EMAIL
        if [ -z "$SSL_EMAIL" ]; then
            log_warn "未填写邮箱，将跳过 SSL"; APPLY_SSL="n"
        fi
    fi

    echo ""
    log_info "配置确认:  域名=${DOMAIN}  数据库=${DB_NAME}/${DB_USER}  SSL=${APPLY_SSL}"
    read -rp "以上信息是否正确? (y/n) [y]: " OK
    OK=${OK:-y}
    [[ ! "$OK" =~ ^[Yy]$ ]] && { log_info "已取消"; exit 0; }
}

start_services() {
    log_step "启动服务"
    systemctl enable --now mariadb
    systemctl enable --now redis-server 2>/dev/null || systemctl enable --now redis 2>/dev/null || true
    systemctl enable --now "php${PHP_VERSION}-fpm"
    systemctl enable --now nginx
    log_info "MariaDB / Redis / PHP-FPM / Nginx 已启动并设为开机自启"
}

configure_database() {
    log_step "创建数据库与账号"

    mysql -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
    mysql -e "ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
    mysql -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';"
    mysql -e "FLUSH PRIVILEGES;"
    log_info "数据库 ${DB_NAME} / 用户 ${DB_USER} 已就绪"
}

install_source() {
    log_step "下载源代码"

    if [ -d "$WEB_ROOT/.git" ]; then
        log_info "检测到已有 git 仓库，执行 git pull"
        cd "$WEB_ROOT" && git pull --ff-only || log_warn "pull 失败，继续使用现有代码"
    elif [ -d "$WEB_ROOT" ] && [ "$(ls -A $WEB_ROOT 2>/dev/null)" ]; then
        log_warn "目录 $WEB_ROOT 已存在且非空但非 git 仓库"
        read -rp "是否清空并重新 clone? (y/n) [n]: " OV
        OV=${OV:-n}
        if [[ "$OV" =~ ^[Yy]$ ]]; then
            rm -rf "$WEB_ROOT"
            git clone "$REPO_URL" "$WEB_ROOT"
        fi
    else
        mkdir -p "$(dirname "$WEB_ROOT")"
        git clone "$REPO_URL" "$WEB_ROOT"
    fi

    chown -R www-data:www-data "$WEB_ROOT"
    find "$WEB_ROOT" -type d -exec chmod 755 {} \;
    find "$WEB_ROOT" -type f -exec chmod 644 {} \;
    chmod -R 775 "$WEB_ROOT/storage" "$WEB_ROOT/bootstrap/cache"
    chmod +x "$WEB_ROOT/artisan"
    log_info "源代码就绪"
}

install_app_dependencies() {
    log_step "composer install"
    cd "$WEB_ROOT"
    sudo -u www-data -H composer install --no-dev --optimize-autoloader --no-interaction --no-scripts
    log_info "Composer 依赖已安装"
}

setup_env_file() {
    log_step "生成 .env"
    cd "$WEB_ROOT"

    if [ -f .env ]; then
        log_info ".env 已存在，备份后覆盖写入 DB 配置"
        cp .env ".env.bak.$(date +%s)"
    else
        cp .env.default .env
    fi

    set_env() {
        local k="$1"; local v="$2"
        local esc; esc=$(printf '%s' "$v" | sed -e 's/[\/&|]/\\&/g')
        if grep -qE "^\s*${k}\s*=" .env; then
            sed -i -E "s|^\s*${k}\s*=.*|${k}=${esc}|" .env
        else
            echo "${k}=${v}" >> .env
        fi
    }
    set_env "APP_URL"     "https://${DOMAIN}"
    set_env "DB_HOST"     "127.0.0.1"
    set_env "DB_PORT"     "3306"
    set_env "DB_DATABASE" "$DB_NAME"
    set_env "DB_USERNAME" "$DB_USER"
    set_env "DB_PASSWORD" "$DB_PASS"
    set_env "REDIS_HOST"  "127.0.0.1"
    set_env "REDIS_PORT"  "6379"
    set_env "CACHE_DRIVER"      "redis"
    set_env "QUEUE_CONNECTION"  "redis"
    set_env "SESSION_DRIVER"    "redis"

    chown www-data:www-data .env
    chmod 664 .env

    # 生成 APP_KEY
    sudo -u www-data -H php artisan key:generate --force
    log_info ".env + APP_KEY 已生成"
}

init_database() {
    log_step "初始化数据库（导入 install.sql）"
    cd "$WEB_ROOT"

    if [ -f install.lock ]; then
        log_info "install.lock 已存在，跳过"
        return
    fi

    local sql="$WEB_ROOT/database/sql/install.sql"
    [ ! -f "$sql" ] && { log_error "未找到 $sql"; exit 1; }

    # 如果 settings 表已存在，视为已初始化
    local exists
    exists=$(MYSQL_PWD="$DB_PASS" mysql -h 127.0.0.1 -u "$DB_USER" \
        -sN -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}' AND table_name='settings';" 2>/dev/null || echo 0)
    if [ "${exists:-0}" -gt 0 ]; then
        log_warn "settings 表已存在，视为已初始化，直接打锁"
        touch install.lock
        chown www-data:www-data install.lock
        return
    fi

    MYSQL_PWD="$DB_PASS" mysql -h 127.0.0.1 -u "$DB_USER" "$DB_NAME" \
        --default-character-set=utf8mb4 < "$sql"
    log_info "install.sql 已导入"

    touch install.lock
    chown www-data:www-data install.lock
    log_info "install.lock 已创建"
}

finalize_app() {
    log_step "Filament 升级与缓存"
    cd "$WEB_ROOT"

    sudo -u www-data -H php artisan filament:upgrade 2>/dev/null || log_warn "filament:upgrade 跳过"

    if ! sudo -u www-data -H php artisan storage:link 2>/dev/null; then
        local link="$WEB_ROOT/public/storage"
        [ ! -L "$link" ] && ln -s "$WEB_ROOT/storage/app/public" "$link" \
            && chown -h www-data:www-data "$link" && log_info "已手动创建 public/storage 软链"
    fi

    sudo -u www-data -H php artisan config:cache
    sudo -u www-data -H php artisan route:cache 2>/dev/null || true
    sudo -u www-data -H php artisan view:cache 2>/dev/null || true
    log_info "缓存已构建"
}

configure_nginx() {
    log_step "配置 Nginx 站点"
    local conf="/etc/nginx/sites-available/${DOMAIN}"

    if [[ "$APPLY_SSL" =~ ^[Yy]$ ]]; then
        cat > "$conf" <<NGINX_EOF
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
    ssl_prefer_server_ciphers on;

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
    location ~ /\. { deny all; }
    location ~* \.(gif|jpg|jpeg|png|bmp|webp|ico|svg)$ { expires 30d; }
    location ~* \.(js|css)$ { expires 12h; }
}
NGINX_EOF
    else
        cat > "$conf" <<NGINX_EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};
    root ${WEB_ROOT}/public;
    index index.php index.html;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }
    location ~ [^/]\.php(/|$) {
        fastcgi_pass unix:/var/run/php/php${PHP_VERSION}-fpm.sock;
        include snippets/fastcgi-php.conf;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
    }
    location ~ /\. { deny all; }
    location ~* \.(gif|jpg|jpeg|png|bmp|webp|ico|svg)$ { expires 30d; }
    location ~* \.(js|css)$ { expires 12h; }
}
NGINX_EOF
    fi

    rm -f /etc/nginx/sites-enabled/default 2>/dev/null || true
    ln -sf "$conf" /etc/nginx/sites-enabled/

    # SSL 尚未申请前 listen 443 会失败，所以先 reload HTTP-only 版本，SSL 申请时 certbot 会替换成 SSL 版本
    if [[ "$APPLY_SSL" =~ ^[Yy]$ ]] && [ ! -f "/etc/letsencrypt/live/${DOMAIN}/fullchain.pem" ]; then
        # 临时先输出纯 HTTP 配置，SSL 申请成功后再写 SSL 版本
        cat > "$conf" <<NGINX_EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};
    root ${WEB_ROOT}/public;
    index index.php index.html;
    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
    location ~ [^/]\.php(/|$) {
        fastcgi_pass unix:/var/run/php/php${PHP_VERSION}-fpm.sock;
        include snippets/fastcgi-php.conf;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
    }
}
NGINX_EOF
    fi

    nginx -t
    systemctl reload nginx
    log_info "Nginx 已加载配置"
}

apply_ssl() {
    [[ ! "$APPLY_SSL" =~ ^[Yy]$ ]] && return

    log_step "申请 SSL 证书"
    if ! command -v certbot >/dev/null 2>&1; then
        apt install -y certbot python3-certbot-nginx
    fi

    certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos \
            -m "$SSL_EMAIL" --redirect \
        || { log_warn "Certbot 申请失败，稍后可手动执行 certbot --nginx -d ${DOMAIN}"; return; }

    # 重新写入带 SSL 的完整配置
    configure_nginx
    log_info "SSL 证书已申请，certbot 自动续期已启用"
}

configure_supervisor() {
    log_step "配置 Supervisor 队列"
    cat > /etc/supervisor/conf.d/dujiaoka.conf <<SUP_EOF
[program:dujiaoka-worker]
process_name=%(program_name)s_%(process_num)02d
command=/usr/bin/php${PHP_VERSION} ${WEB_ROOT}/artisan queue:work --sleep=3 --tries=3 --max-time=3600
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
    log_info "队列守护进程已配置"
}

print_result() {
    echo ""
    echo -e "${GREEN}${BOLD}============================================${NC}"
    echo -e "${GREEN}${BOLD}       独角数卡 安装完成！${NC}"
    echo -e "${GREEN}${BOLD}============================================${NC}"
    echo ""
    if [[ "$APPLY_SSL" =~ ^[Yy]$ ]]; then
        echo -e "  访问地址: ${CYAN}https://${DOMAIN}${NC}"
        echo -e "  后台地址: ${CYAN}https://${DOMAIN}/admin${NC}"
    else
        echo -e "  访问地址: ${CYAN}http://${DOMAIN}${NC}"
        echo -e "  后台地址: ${CYAN}http://${DOMAIN}/admin${NC}"
    fi
    echo -e "  默认账号: ${YELLOW}admin / admin${NC} ${RED}(请立即修改！)${NC}"
    echo ""
    echo -e "  网站目录: ${WEB_ROOT}"
    echo -e "  数据库:   ${DB_NAME} @ 127.0.0.1:3306"
    echo ""
    echo -e "${YELLOW}  数据库已由 install.sql 初始化完毕，install.lock 已生成。${NC}"
    echo -e "${YELLOW}  可直接访问后台，无需再走 /install 向导。${NC}"
    echo ""
}

# =================================================================
# 主流程
# =================================================================

main() {
    clear
    echo -e "${CYAN}${BOLD}"
    cat <<'BANNER'
  ____        _ _                    _
 |  _ \ _   _(_|_) __ _  ___   ___  | | ____ _
 | | | | | | | | |/ _` |/ _ \ / _ \ | |/ / _` |
 | |_| | |_| | | | (_| | (_) | (_) ||   < (_| |
 |____/ \__,_| |_|\__,_|\___/ \___/ |_|\_\__,_|
            |__/    一键部署 v2 (preflight→plan→apply→verify)
BANNER
    echo -e "${NC}"

    check_root
    check_os
    detect_state
    get_user_input
    build_plan

    prepare_apt
    apply_plan
    fix_php_config
    install_composer_if_missing

    verify_after_apply

    start_services
    configure_database
    install_source
    install_app_dependencies
    setup_env_file
    init_database
    finalize_app
    configure_nginx
    apply_ssl
    configure_supervisor

    print_result
}

main "$@"
