#!/usr/bin/env bash
set -euo pipefail
IFS=$'\n\t'

SCRIPT_VERSION="1.0.8"
SCRIPT_CWD="${PWD}"
DEFAULT_REPO_URL="https://github.com/TerminalAddict/andrea-helpdesk.git"
DEFAULT_REPO_REF="main"
DOCS_INSTALL_URL="https://docs.andreahelpdesk.com/install/"
DOCS_FTP_URL="https://docs.andreahelpdesk.com/install/#2-ftp--sftp-install"
INSTALL_LOG="${INSTALL_LOG:-$(pwd)/install-cli.log}"

EXIT_PREREQ=10
EXIT_PHP=11
EXIT_SSH=12
EXIT_DOCROOT=13
EXIT_DB=14
EXIT_ENV=15
EXIT_INSTALL=16
EXIT_VERIFY=17

PKG_MANAGER="unknown"
LAST_STEP="initialising"

INSTALL_MODE=""
REPO_URL="$DEFAULT_REPO_URL"
REPO_REF="$DEFAULT_REPO_REF"
WORK_DIR=""
INSTALL_DIR=""
DOCROOT=""
EXPECTED_DOCROOT=""

LOCAL_HOST=""
PROD_HOST=""
REMOTE_USER=""
REMOTE_PATH=""
REMOTE_TARGET=""
REMOTE_DOCROOT=""

APP_URL=""
APP_TIMEZONE=""
JWT_SECRET=""
STORAGE_PATH=""

DB_HOST="localhost"
DB_PORT="3306"
DB_DATABASE=""
DB_USERNAME=""
DB_PASSWORD=""

ADMIN_NAME=""
ADMIN_EMAIL=""
ADMIN_PASSWORD=""

MASKED_DB_PASSWORD="********"
MASKED_ADMIN_PASSWORD="********"
CRON_STATUS="not installed"
TTY_FD=""
DB_BACKUP_PATH=""

print_user_message() {
    if [[ -n "${TTY_FD:-}" ]]; then
        cat >&"$TTY_FD"
    else
        cat >&2
    fi
}

on_error() {
    local exit_code=$?
    if [[ $exit_code -ne 0 ]]; then
        log_error "The installer failed during: ${LAST_STEP}"
        log_error "See ${INSTALL_LOG} for the full transcript."
        print_user_message <<EOF

The installer could not continue.

What happened:
- The install failed during: ${LAST_STEP}

What to do next:
- Read the error shown above
- Check the installer log for the full command output:
  ${INSTALL_LOG}
- Correct the problem and rerun the installer

EOF
    fi
    exit "$exit_code"
}
trap on_error ERR

timestamp() {
    date '+%Y-%m-%d %H:%M:%S'
}

append_log() {
    local level=$1
    shift
    printf '%s [%s] %s\n' "$(timestamp)" "$level" "$*" >> "$INSTALL_LOG"
}

log_info() {
    printf '[INFO] %s\n' "$*"
    append_log "INFO" "$*"
}

log_warn() {
    printf '[WARN] %s\n' "$*"
    append_log "WARN" "$*"
}

log_ok() {
    printf '[OK] %s\n' "$*"
    append_log "OK" "$*"
}

log_error() {
    printf '[ERROR] %s\n' "$*" >&2
    append_log "ERROR" "$*"
}

die() {
    local code=$1
    shift
    log_error "$*"
    print_user_message <<EOF

The installer cannot continue.

Reason:
- $*

What to do next:
- Fix the problem shown above
- If you need more detail, check:
  ${INSTALL_LOG}
- Then rerun the installer

EOF
    exit "$code"
}

run_step() {
    local message=$1
    shift
    LAST_STEP="$message"
    log_info "$message"
    printf '%s [STEP] %s\n' "$(timestamp)" "$message" >> "$INSTALL_LOG"
    if ! "$@" >> "$INSTALL_LOG" 2>&1; then
        log_error "Failed: ${message}"
        log_error "See ${INSTALL_LOG} for the full command output."
        return 1
    fi
    log_ok "$message"
}

run_step_capture() {
    local message=$1
    shift
    LAST_STEP="$message"
    log_info "$message"
    local output
    if ! output=$("$@" 2>&1); then
        printf '%s\n' "$output" >> "$INSTALL_LOG"
        log_error "$output"
        return 1
    fi
    printf '%s\n' "$output" >> "$INSTALL_LOG"
    log_ok "$message"
    printf '%s\n' "$output"
}

confirm() {
    local prompt=$1
    local answer
    printf '%s [y/N]: ' "$prompt" >&"$TTY_FD"
    read -r -u "$TTY_FD" answer
    answer=${answer:-N}
    [[ "$answer" =~ ^[Yy]([Ee][Ss])?$ ]]
}

prompt_value() {
    local var_name=$1
    local prompt=$2
    local default=${3-}
    local value
    if [[ -n "$default" ]]; then
        printf '%s [%s]: ' "$prompt" "$default" >&"$TTY_FD"
        read -r -u "$TTY_FD" value
        value=${value:-$default}
    else
        printf '%s: ' "$prompt" >&"$TTY_FD"
        read -r -u "$TTY_FD" value
    fi
    printf -v "$var_name" '%s' "$value"
}

prompt_secret() {
    local var_name=$1
    local prompt=$2
    local value
    printf '%s: ' "$prompt" >&"$TTY_FD"
    read -r -s -u "$TTY_FD" value
    printf '\n' >&"$TTY_FD"
    printf -v "$var_name" '%s' "$value"
}

prompt_choice() {
    local var_name=$1
    local prompt=$2
    shift 2
    local options=("$@")
    local answer
    while true; do
        printf '%s\n' "$prompt" >&"$TTY_FD"
        local i=1
        for option in "${options[@]}"; do
            printf '  %d. %s\n' "$i" "$option" >&"$TTY_FD"
            i=$((i + 1))
        done
        printf 'Choose an option [1-%s]: ' "${#options[@]}" >&"$TTY_FD"
        read -r -u "$TTY_FD" answer
        if [[ "$answer" =~ ^[0-9]+$ ]] && (( answer >= 1 && answer <= ${#options[@]} )); then
            printf -v "$var_name" '%s' "${options[$((answer - 1))]}"
            return 0
        fi
        log_warn "Please enter a number between 1 and ${#options[@]}."
    done
}

mask_value() {
    local value=$1
    if [[ -z "$value" ]]; then
        printf '(empty)'
    else
        printf '********'
    fi
}

shell_quote_sh() {
    local value=$1
    printf "'%s'" "$(printf '%s' "$value" | sed "s/'/'\\\\''/g")"
}

php_single_quote() {
    local value=$1
    value=${value//\\/\\\\}
    value=${value//\'/\\\'}
    printf '%s' "$value"
}

init_tty() {
    if [[ -t 0 ]]; then
        TTY_FD=0
        return 0
    fi
    if exec 3<>/dev/tty 2>/dev/null; then
        TTY_FD=3
        return 0
    fi
    die "$EXIT_PREREQ" "Interactive input requires a TTY. Re-run this installer from a terminal."
}

canonicalize_path() {
    local value=$1
    while [[ "$value" != "/" && "$value" == */ ]]; do
        value=${value%/}
    done
    printf '%s' "$value"
}

copy_file_remote() {
    local local_path=$1
    local remote_path=$2
    ssh "$REMOTE_TARGET" "umask 077 && cat > $(shell_quote_sh "$remote_path")" < "$local_path" >> "$INSTALL_LOG" 2>&1
}

detect_package_manager() {
    if command -v apt-get >/dev/null 2>&1; then
        PKG_MANAGER="apt"
    elif command -v dnf >/dev/null 2>&1; then
        PKG_MANAGER="dnf"
    elif command -v yum >/dev/null 2>&1; then
        PKG_MANAGER="yum"
    elif command -v pacman >/dev/null 2>&1; then
        PKG_MANAGER="pacman"
    elif command -v zypper >/dev/null 2>&1; then
        PKG_MANAGER="zypper"
    elif command -v emerge >/dev/null 2>&1; then
        PKG_MANAGER="portage"
    else
        PKG_MANAGER="unknown"
    fi
}

install_hint_for() {
    local tool=$1
    case "$PKG_MANAGER:$tool" in
        apt:git) echo "sudo apt install git" ;;
        apt:composer) echo "sudo apt install composer" ;;
        apt:make) echo "sudo apt install make" ;;
        apt:php) echo "sudo apt install php php-cli php-mysql php-mbstring php-openssl" ;;
        apt:rsync) echo "sudo apt install rsync" ;;
        apt:ssh) echo "sudo apt install openssh-client" ;;

        yum:git) echo "sudo yum install git" ;;
        yum:composer) echo "sudo yum install composer" ;;
        yum:make) echo "sudo yum install make" ;;
        yum:php) echo "sudo yum install php php-cli php-mysqlnd php-mbstring php-opcache" ;;
        yum:rsync) echo "sudo yum install rsync" ;;
        yum:ssh) echo "sudo yum install openssh-clients" ;;

        dnf:git) echo "sudo dnf install git" ;;
        dnf:composer) echo "sudo dnf install composer" ;;
        dnf:make) echo "sudo dnf install make" ;;
        dnf:php) echo "sudo dnf install php php-cli php-mysqlnd php-mbstring php-opcache" ;;
        dnf:rsync) echo "sudo dnf install rsync" ;;
        dnf:ssh) echo "sudo dnf install openssh-clients" ;;

        pacman:git) echo "sudo pacman -S git" ;;
        pacman:composer) echo "sudo pacman -S composer" ;;
        pacman:make) echo "sudo pacman -S make" ;;
        pacman:php) echo "sudo pacman -S php php-gd php-intl" ;;
        pacman:rsync) echo "sudo pacman -S rsync" ;;
        pacman:ssh) echo "sudo pacman -S openssh" ;;

        zypper:git) echo "sudo zypper install git" ;;
        zypper:composer) echo "sudo zypper install composer" ;;
        zypper:make) echo "sudo zypper install make" ;;
        zypper:php) echo "sudo zypper install php8 php8-mysql php8-mbstring php8-openssl" ;;
        zypper:rsync) echo "sudo zypper install rsync" ;;
        zypper:ssh) echo "sudo zypper install openssh" ;;

        portage:git) echo "sudo emerge dev-vcs/git" ;;
        portage:composer) echo "sudo emerge dev-php/composer" ;;
        portage:make) echo "sudo emerge sys-devel/make" ;;
        portage:php) echo "sudo emerge dev-lang/php" ;;
        portage:rsync) echo "sudo emerge net-misc/rsync" ;;
        portage:ssh) echo "sudo emerge net-misc/openssh" ;;

        *) echo "Install ${tool} using your system package manager." ;;
    esac
}

require_command() {
    local tool=$1
    if ! command -v "$tool" >/dev/null 2>&1; then
        die "$EXIT_PREREQ" "Required command '${tool}' is not installed. Try: $(install_hint_for "$tool")"
    fi
    log_ok "Found command: ${tool}"
}

require_remote_command() {
    local tool=$1
    if ! ssh "$REMOTE_TARGET" "command -v $(shell_quote_sh "$tool") >/dev/null 2>&1"; then
        die "$EXIT_PREREQ" "Remote host is missing '${tool}'. Install it on ${REMOTE_TARGET}. Suggested command: $(install_hint_for "$tool")"
    fi
    log_ok "Remote host has command: ${tool}"
}

require_php_version() {
    local version
    version=$(php -r 'echo PHP_VERSION;')
    if ! php -r 'exit(version_compare(PHP_VERSION, "8.1.0", ">=") ? 0 : 1);'; then
        die "$EXIT_PHP" "PHP 8.1 or newer is required. Current version: ${version}. Install or upgrade PHP first."
    fi
    log_ok "Local PHP version is ${version}"
}

require_remote_php_version() {
    local version
    version=$(ssh "$REMOTE_TARGET" "php -r 'echo PHP_VERSION;'")
    if ! ssh "$REMOTE_TARGET" "php -r 'exit(version_compare(PHP_VERSION, \"8.1.0\", \">=\") ? 0 : 1);'"; then
        die "$EXIT_PHP" "Remote PHP 8.1 or newer is required. Current version on ${REMOTE_TARGET}: ${version}"
    fi
    log_ok "Remote PHP version is ${version}"
}

require_php_extension() {
    local ext=$1
    local required=${2:-required}
    if php -m | awk '{print tolower($0)}' | grep -qx "$(printf '%s' "$ext" | tr '[:upper:]' '[:lower:]')"; then
        log_ok "Local PHP extension present: ${ext}"
        return 0
    fi
    if [[ "$required" == "required" ]]; then
        die "$EXIT_PHP" "Missing required PHP extension '${ext}'. Install it before continuing."
    fi
    log_warn "Local PHP extension '${ext}' is missing (${required})."
}

require_remote_php_extension() {
    local ext=$1
    local required=${2:-required}
    if ssh "$REMOTE_TARGET" "php -m | awk '{print tolower(\$0)}' | grep -qx $(shell_quote_sh "$(printf '%s' "$ext" | tr '[:upper:]' '[:lower:]')")"; then
        log_ok "Remote PHP extension present: ${ext}"
        return 0
    fi
    if [[ "$required" == "required" ]]; then
        die "$EXIT_PHP" "Remote host is missing required PHP extension '${ext}'. Install it before continuing."
    fi
    log_warn "Remote PHP extension '${ext}' is missing (${required})."
}

detect_timezone() {
    local tz=""
    if command -v timedatectl >/dev/null 2>&1; then
        tz=$(timedatectl show -p Timezone --value 2>/dev/null || true)
    fi
    if [[ -z "$tz" ]] && [[ -r /etc/timezone ]]; then
        tz=$(tr -d '\n' < /etc/timezone)
    fi
    if [[ -z "$tz" ]]; then
        tz=$(php -r 'echo date_default_timezone_get();' 2>/dev/null || true)
    fi
    printf '%s' "${tz:-UTC}"
}

generate_jwt_secret_if_needed() {
    if [[ -z "$JWT_SECRET" ]]; then
        JWT_SECRET=$(php -r 'echo bin2hex(random_bytes(32));')
        log_ok "Generated a secure JWT secret automatically."
    fi
}

check_write_parent() {
    local target=$1
    local parent
    parent=$(dirname "$target")
    if [[ -d "$target" ]]; then
        [[ -w "$target" ]]
        return
    fi
    [[ -d "$parent" && -w "$parent" ]]
}

choose_install_mode() {
    local choice
    prompt_choice choice "Where should Andrea Helpdesk be installed?" "Local install (this machine)" "Remote install (deploy to another server over SSH)"
    if [[ "$choice" == "Local install (this machine)" ]]; then
        INSTALL_MODE="local"
        LOCAL_HOST=$(hostname -s 2>/dev/null || hostname || echo "localhost")
    else
        INSTALL_MODE="remote"
        LOCAL_HOST=$(hostname -s 2>/dev/null || hostname || echo "localhost")
    fi
    log_ok "Install mode selected: ${INSTALL_MODE}"
}

collect_remote_details() {
    prompt_value REMOTE_USER "Remote SSH user" "deploy"
    prompt_value PROD_HOST "Remote host or SSH alias"
    prompt_value REMOTE_PATH "Remote install path" "/var/www/html/andrea-helpdesk"
    prompt_value WORK_DIR "Local working copy directory" "${PWD}/andrea-helpdesk-deploy"
    REMOTE_TARGET="${REMOTE_USER}@${PROD_HOST}"
}

write_makefile_local() {
    cat > "${WORK_DIR}/Makefile.local" <<EOF
# Generated by bin/install-cli.sh on $(timestamp)
LOCAL_HOST  = ${LOCAL_HOST}
PROD_HOST   = ${PROD_HOST}
REMOTE_USER = ${REMOTE_USER}
REMOTE_PATH = ${REMOTE_PATH}
EOF
    log_ok "Wrote ${WORK_DIR}/Makefile.local"
}

check_ssh_access() {
    LAST_STEP="checking SSH access"
    log_info "Checking SSH access to ${REMOTE_TARGET}"
    if ! ssh -o BatchMode=yes -o ConnectTimeout=8 "$REMOTE_TARGET" "printf ok" >> "$INSTALL_LOG" 2>&1; then
        die "$EXIT_SSH" "Could not connect to ${REMOTE_TARGET} over SSH. This installer currently expects SSH key-based or agent-backed access."
    fi
    log_ok "SSH access verified for ${REMOTE_TARGET}"
}

collect_repo_details() {
    prompt_value REPO_URL "Git repository URL" "$DEFAULT_REPO_URL"
    prompt_value REPO_REF "Git branch, tag, or ref" "$DEFAULT_REPO_REF"
    if [[ "$REPO_URL" != "$DEFAULT_REPO_URL" ]]; then
        cat >&"$TTY_FD" <<EOF

Advanced override detected.

You have chosen a non-default repository URL:
  ${REPO_URL}

This installer will execute code from that repository, including Composer dependencies,
Make targets, PHP scripts, and shell commands. Continue only if you trust that source.

EOF
        if ! confirm "Continue with the non-default repository?"; then
            die "$EXIT_INSTALL" "Installer cancelled because a non-default repository was not approved."
        fi
    fi
    if [[ "$INSTALL_MODE" == "local" ]]; then
        prompt_value INSTALL_DIR "Local install directory" "/var/www/andrea-helpdesk"
        WORK_DIR="$INSTALL_DIR"
    fi
}

ensure_target_directory_clear() {
    local dir=$1
    if [[ -e "$dir" && -n "$(find "$dir" -mindepth 1 -maxdepth 1 2>/dev/null | head -n 1 || true)" ]]; then
        cat >&"$TTY_FD" <<EOF

The target directory already exists and is not empty:
  ${dir}

The installer can clear this directory before cloning Andrea Helpdesk into it.
This will permanently remove the existing contents of that directory.

EOF
        if ! confirm "Clear the target directory before continuing?"; then
            die "$EXIT_INSTALL" "Installer cancelled. Choose a different directory or clear '${dir}' manually and rerun."
        fi
        if ! confirm "Are you sure you want to permanently delete the existing contents of ${dir}?"; then
            die "$EXIT_INSTALL" "Installer cancelled. No files were removed."
        fi
        LAST_STEP="clearing target directory ${dir}"
        log_warn "Clearing existing contents of ${dir} before clone."
        find "$dir" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} + >> "$INSTALL_LOG" 2>&1 || die "$EXIT_INSTALL" "Could not clear '${dir}'. Check permissions and rerun the installer."
        log_ok "Cleared target directory: ${dir}"
    fi
}

clone_repo() {
    ensure_target_directory_clear "$WORK_DIR"
    mkdir -p "$(dirname "$WORK_DIR")"
    run_step "Cloning Andrea Helpdesk into ${WORK_DIR}" git clone "$REPO_URL" "$WORK_DIR"
    cd "$WORK_DIR"
    if [[ "$REPO_REF" != "$DEFAULT_REPO_REF" ]]; then
        run_step "Checking out ${REPO_REF}" git checkout "$REPO_REF"
    fi
}

collect_webroot() {
    if [[ "$INSTALL_MODE" == "local" ]]; then
        EXPECTED_DOCROOT="${INSTALL_DIR}/public_html"
        prompt_value DOCROOT "Document root for this site" "$EXPECTED_DOCROOT"
    else
        EXPECTED_DOCROOT="${REMOTE_PATH}/public_html"
        prompt_value DOCROOT "Document root on the remote server" "$EXPECTED_DOCROOT"
        REMOTE_DOCROOT="$DOCROOT"
    fi
}

validate_docroot_layout() {
    DOCROOT=$(canonicalize_path "$DOCROOT")
    EXPECTED_DOCROOT=$(canonicalize_path "$EXPECTED_DOCROOT")
    if [[ "$DOCROOT" != "$EXPECTED_DOCROOT" ]]; then
        cat <<EOF

Andrea Helpdesk cannot be installed in this layout.

Why:
- only public_html/ is designed to be web-exposed
- the rest of the repository contains application code, configuration, migrations, and operational files
- placing the whole project directly under the document root would expose files that should stay private

This installer does not support "single public directory" hosting layouts in v1.

Please use the FTP / SFTP install guidance instead:
  ${DOCS_FTP_URL}

EOF
        exit "$EXIT_DOCROOT"
    fi
    log_ok "Document root layout is valid: ${DOCROOT}"
}

validate_local_path_permissions() {
    if ! check_write_parent "$WORK_DIR"; then
        die "$EXIT_INSTALL" "You do not have write access to create '${WORK_DIR}'. Choose another path or fix permissions."
    fi
}

validate_remote_path_permissions() {
    if ! ssh "$REMOTE_TARGET" "test -d $(shell_quote_sh "$(dirname "$REMOTE_PATH")") && test -w $(shell_quote_sh "$(dirname "$REMOTE_PATH")")"; then
        log_warn "Remote parent path may not be writable. The installer will continue because ${REMOTE_PATH} was created successfully."
    fi
}

prepare_remote_target_path() {
    ssh "$REMOTE_TARGET" "mkdir -p $(shell_quote_sh "$REMOTE_PATH")" >> "$INSTALL_LOG" 2>&1 || die "$EXIT_SSH" "Could not create or access remote path ${REMOTE_PATH}"
    log_ok "Remote install path is ready: ${REMOTE_PATH}"
}

ask_database_ready() {
    cat <<'EOF'

Before continuing, you must already have:
- a MySQL or MariaDB database created
- a user with permission to access that database

The installer will not create the database for you.

EOF
    if ! confirm "Have you already created the database and user?"; then
        die "$EXIT_DB" "Create the database and user first, then rerun the installer."
    fi
}

collect_app_details() {
    local tz_default
    tz_default=$(detect_timezone)
    prompt_value APP_URL "APP_URL" "https://support.example.com"
    prompt_value APP_TIMEZONE "APP_TIMEZONE" "$tz_default"
    prompt_secret JWT_SECRET "JWT_SECRET (leave blank to auto-generate)"
    generate_jwt_secret_if_needed
}

collect_database_details() {
    prompt_value DB_HOST "DB_HOST" "localhost"
    prompt_value DB_PORT "DB_PORT" "3306"
    prompt_value DB_DATABASE "DB_DATABASE"
    prompt_value DB_USERNAME "DB_USERNAME"
    prompt_secret DB_PASSWORD "DB_PASSWORD (paste the literal password)"
    MASKED_DB_PASSWORD=$(mask_value "$DB_PASSWORD")
}

collect_storage_path() {
    local default_storage
    if [[ "$INSTALL_MODE" == "local" ]]; then
        default_storage="${INSTALL_DIR}/storage"
    else
        default_storage="${REMOTE_PATH}/storage"
    fi
    prompt_value STORAGE_PATH "STORAGE_PATH (must be outside public_html)" "$default_storage"
}

collect_admin_details() {
    local confirm_password
    prompt_value ADMIN_NAME "ADMIN_NAME"
    prompt_value ADMIN_EMAIL "ADMIN_EMAIL"
    if ! printf '%s' "$ADMIN_EMAIL" | grep -Eq '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$'; then
        die "$EXIT_ENV" "ADMIN_EMAIL does not look like a valid email address."
    fi
    while true; do
        prompt_secret ADMIN_PASSWORD "ADMIN_PASSWORD"
        prompt_secret confirm_password "Confirm ADMIN_PASSWORD"
        if [[ "$ADMIN_PASSWORD" != "$confirm_password" ]]; then
            log_warn "Admin passwords do not match. Please try again."
            continue
        fi
        if [[ ${#ADMIN_PASSWORD} -lt 8 ]]; then
            log_warn "Admin password must be at least 8 characters long. Please try again."
            continue
        fi
        break
    done
    MASKED_ADMIN_PASSWORD=$(mask_value "$ADMIN_PASSWORD")
}

validate_storage_path() {
    local candidate=$1
    if [[ "$candidate" != /* ]]; then
        die "$EXIT_ENV" "STORAGE_PATH must be an absolute path. Received: ${candidate}"
    fi
    case "$candidate" in
        "${DOCROOT}"* )
            die "$EXIT_ENV" "STORAGE_PATH must not live inside the document root (${DOCROOT})."
            ;;
    esac
}

test_db_connection_local() {
    DB_HOST="$DB_HOST" \
    DB_PORT="$DB_PORT" \
    DB_DATABASE="$DB_DATABASE" \
    DB_USERNAME="$DB_USERNAME" \
    DB_PASSWORD="$DB_PASSWORD" \
    php <<'PHP'
<?php
$host = (string)getenv('DB_HOST');
$port = (string)getenv('DB_PORT');
$db   = (string)getenv('DB_DATABASE');
$user = (string)getenv('DB_USERNAME');
$pass = (string)getenv('DB_PASSWORD');
if ($db === '' || $user === '') {
    fwrite(STDERR, "Database name and username are required.\n");
    exit(2);
}
try {
    new PDO('mysql:host=' . $host . ';port=' . $port . ';dbname=' . $db . ';charset=utf8mb4', $user, $pass, [
        PDO::ATTR_TIMEOUT => 5,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    echo "Database connection successful.\n";
    exit(0);
} catch (PDOException $e) {
    $code = (string)$e->getCode();
    $msg = $e->getMessage();
    if ($code === '1045' || strpos($msg, 'Access denied') !== false) {
        fwrite(STDERR, "Connection failed: access denied.\n");
        exit(3);
    }
    if ($code === '1049' || strpos($msg, 'Unknown database') !== false) {
        fwrite(STDERR, "Connection failed: database does not exist.\n");
        exit(4);
    }
    if ($code === '2002' || $code === '2003' || strpos($msg, 'Connection refused') !== false) {
        fwrite(STDERR, "Connection failed: could not reach database host.\n");
        exit(5);
    }
    fwrite(STDERR, "Connection failed: " . $msg . "\n");
    exit(6);
}
PHP
}

test_db_connection_remote() {
    ssh "$REMOTE_TARGET" \
        "DB_HOST=$(shell_quote_sh "$DB_HOST") DB_PORT=$(shell_quote_sh "$DB_PORT") DB_DATABASE=$(shell_quote_sh "$DB_DATABASE") DB_USERNAME=$(shell_quote_sh "$DB_USERNAME") DB_PASSWORD=$(shell_quote_sh "$DB_PASSWORD") php" <<'PHP'
<?php
$host = (string)getenv('DB_HOST');
$port = (string)getenv('DB_PORT');
$db   = (string)getenv('DB_DATABASE');
$user = (string)getenv('DB_USERNAME');
$pass = (string)getenv('DB_PASSWORD');
if ($db === '' || $user === '') {
    fwrite(STDERR, "Database name and username are required.\n");
    exit(2);
}
try {
    new PDO('mysql:host=' . $host . ';port=' . $port . ';dbname=' . $db . ';charset=utf8mb4', $user, $pass, [
        PDO::ATTR_TIMEOUT => 5,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    echo "Database connection successful.\n";
    exit(0);
} catch (PDOException $e) {
    $code = (string)$e->getCode();
    $msg = $e->getMessage();
    if ($code === '1045' || strpos($msg, 'Access denied') !== false) {
        fwrite(STDERR, "Connection failed: access denied.\n");
        exit(3);
    }
    if ($code === '1049' || strpos($msg, 'Unknown database') !== false) {
        fwrite(STDERR, "Connection failed: database does not exist.\n");
        exit(4);
    }
    if ($code === '2002' || $code === '2003' || strpos($msg, 'Connection refused') !== false) {
        fwrite(STDERR, "Connection failed: could not reach database host.\n");
        exit(5);
    }
    fwrite(STDERR, "Connection failed: " . $msg . "\n");
    exit(6);
}
PHP
}

list_database_tables_local() {
    DB_HOST="$DB_HOST" \
    DB_PORT="$DB_PORT" \
    DB_DATABASE="$DB_DATABASE" \
    DB_USERNAME="$DB_USERNAME" \
    DB_PASSWORD="$DB_PASSWORD" \
    php <<'PHP'
<?php
$host = (string)getenv('DB_HOST');
$port = (string)getenv('DB_PORT');
$db   = (string)getenv('DB_DATABASE');
$user = (string)getenv('DB_USERNAME');
$pass = (string)getenv('DB_PASSWORD');
$pdo = new PDO('mysql:host=' . $host . ';port=' . $port . ';dbname=' . $db . ';charset=utf8mb4', $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: [];
sort($tables);
echo implode("\n", $tables);
PHP
}

list_database_tables_remote() {
    ssh "$REMOTE_TARGET" \
        "DB_HOST=$(shell_quote_sh "$DB_HOST") DB_PORT=$(shell_quote_sh "$DB_PORT") DB_DATABASE=$(shell_quote_sh "$DB_DATABASE") DB_USERNAME=$(shell_quote_sh "$DB_USERNAME") DB_PASSWORD=$(shell_quote_sh "$DB_PASSWORD") php" <<'PHP'
<?php
$host = (string)getenv('DB_HOST');
$port = (string)getenv('DB_PORT');
$db   = (string)getenv('DB_DATABASE');
$user = (string)getenv('DB_USERNAME');
$pass = (string)getenv('DB_PASSWORD');
$pdo = new PDO('mysql:host=' . $host . ';port=' . $port . ';dbname=' . $db . ';charset=utf8mb4', $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: [];
sort($tables);
echo implode("\n", $tables);
PHP
}

looks_like_andrea_database() {
    local matches=0
    local table
    for table in "$@"; do
        case "$table" in
            agents|customers|tickets|replies|ticket_number_sequences|agent_notifications|knowledge_base_articles|imap_accounts)
                matches=$((matches + 1))
                ;;
        esac
    done
    (( matches >= 2 ))
}

backup_database_local() {
    local backup_path=$1
    require_command mysqldump
    local cnf
    cnf=$(mktemp)
    chmod 600 "$cnf"
    cat > "$cnf" <<EOF
[client]
host=${DB_HOST}
port=${DB_PORT}
user=${DB_USERNAME}
password=${DB_PASSWORD}
EOF
    if ! mysqldump --defaults-extra-file="$cnf" --single-transaction --routines --triggers --databases "$DB_DATABASE" > "$backup_path"; then
        rm -f "$cnf"
        die "$EXIT_DB" "Failed to create database backup at ${backup_path}"
    fi
    rm -f "$cnf"
}

backup_database_remote() {
    local backup_path=$1
    require_remote_command mysqldump
    ssh "$REMOTE_TARGET" "tmp=\$(mktemp) && chmod 600 \"\$tmp\" && cat > \"\$tmp\" <<'EOF'
[client]
host=$(printf '%s' "$DB_HOST")
port=$(printf '%s' "$DB_PORT")
user=$(printf '%s' "$DB_USERNAME")
password=$(printf '%s' "$DB_PASSWORD")
EOF
mysqldump --defaults-extra-file=\"\$tmp\" --single-transaction --routines --triggers --databases $(shell_quote_sh "$DB_DATABASE")
status=\$?
rm -f \"\$tmp\"
exit \$status" > "$backup_path" || die "$EXIT_DB" "Failed to create database backup at ${backup_path}"
}

drop_all_tables_local() {
    DB_HOST="$DB_HOST" \
    DB_PORT="$DB_PORT" \
    DB_DATABASE="$DB_DATABASE" \
    DB_USERNAME="$DB_USERNAME" \
    DB_PASSWORD="$DB_PASSWORD" \
    php <<'PHP'
<?php
$host = (string)getenv('DB_HOST');
$port = (string)getenv('DB_PORT');
$db   = (string)getenv('DB_DATABASE');
$user = (string)getenv('DB_USERNAME');
$pass = (string)getenv('DB_PASSWORD');
$pdo = new PDO('mysql:host=' . $host . ';port=' . $port . ';dbname=' . $db . ';charset=utf8mb4', $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: [];
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($tables as $table) {
    $quoted = '`' . str_replace('`', '``', (string)$table) . '`';
    $pdo->exec('DROP TABLE IF EXISTS ' . $quoted);
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
PHP
}

drop_all_tables_remote() {
    ssh "$REMOTE_TARGET" \
        "DB_HOST=$(shell_quote_sh "$DB_HOST") DB_PORT=$(shell_quote_sh "$DB_PORT") DB_DATABASE=$(shell_quote_sh "$DB_DATABASE") DB_USERNAME=$(shell_quote_sh "$DB_USERNAME") DB_PASSWORD=$(shell_quote_sh "$DB_PASSWORD") php" <<'PHP'
<?php
$host = (string)getenv('DB_HOST');
$port = (string)getenv('DB_PORT');
$db   = (string)getenv('DB_DATABASE');
$user = (string)getenv('DB_USERNAME');
$pass = (string)getenv('DB_PASSWORD');
$pdo = new PDO('mysql:host=' . $host . ';port=' . $port . ';dbname=' . $db . ';charset=utf8mb4', $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: [];
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($tables as $table) {
    $quoted = '`' . str_replace('`', '``', (string)$table) . '`';
    $pdo->exec('DROP TABLE IF EXISTS ' . $quoted);
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
PHP
}

handle_existing_database_state() {
    local table_output=""
    local -a tables=()
    local preview=""
    local safe_db_name
    safe_db_name=$(printf '%s' "$DB_DATABASE" | tr -c 'A-Za-z0-9._-' '_')

    if [[ "$INSTALL_MODE" == "local" ]]; then
        table_output=$(list_database_tables_local)
    else
        table_output=$(list_database_tables_remote)
    fi

    if [[ -n "$table_output" ]]; then
        mapfile -t tables <<< "$table_output"
    fi

    if (( ${#tables[@]} == 0 )); then
        log_ok "Database '${DB_DATABASE}' is empty and ready for a fresh install."
        return 0
    fi

    preview=$(printf '%s\n' "${tables[@]:0:12}" | paste -sd ', ' -)

    if looks_like_andrea_database "${tables[@]}"; then
        cat >&"$TTY_FD" <<EOF

This looks like an existing Andrea Helpdesk database.

Database: ${DB_DATABASE}
Detected tables (${#tables[@]}):
  ${preview}

For an existing Andrea Helpdesk database, the normal path is:
  use upgrade / deploy, not a fresh install

If you want to start again, the installer can:
  1. create a backup dump in the directory where you launched the installer
  2. drop every table in this database
  3. continue with a fresh install

EOF
        local choice
        prompt_choice choice "How should the installer handle this database?" \
            "Abort and use upgrade/deploy instead" \
            "Back up and drop all tables, then continue with a fresh install"
        if [[ "$choice" == "Abort and use upgrade/deploy instead" ]]; then
            die "$EXIT_DB" "Existing Andrea Helpdesk database detected. Use upgrade/deploy instead of a fresh install."
        fi
        if ! confirm "Create a backup and permanently drop all tables in ${DB_DATABASE}?"; then
            die "$EXIT_DB" "Installer cancelled. No database changes were made."
        fi
        if ! confirm "Are you absolutely sure you want to drop all tables in ${DB_DATABASE}?"; then
            die "$EXIT_DB" "Installer cancelled. No database changes were made."
        fi
        DB_BACKUP_PATH="${SCRIPT_CWD}/andreahelpdesk-db-backup-${safe_db_name}-$(date +%Y%m%d%H%M%S).sql"
        LAST_STEP="backing up existing database ${DB_DATABASE}"
        log_warn "Creating safety backup at ${DB_BACKUP_PATH}"
        if [[ "$INSTALL_MODE" == "local" ]]; then
            backup_database_local "$DB_BACKUP_PATH"
            drop_all_tables_local
        else
            backup_database_remote "$DB_BACKUP_PATH"
            drop_all_tables_remote
        fi
        log_ok "Database backup created: ${DB_BACKUP_PATH}"
        log_warn "Dropped all existing tables from ${DB_DATABASE}. The installer will now continue with a fresh install."
        return 0
    fi

    cat >&"$TTY_FD" <<EOF

The selected database is not empty, but it does not look like an Andrea Helpdesk database.

Database: ${DB_DATABASE}
Detected tables (${#tables[@]}):
  ${preview}

To avoid mixing Andrea Helpdesk with unrelated application tables, the installer requires
an empty database for a fresh install.

EOF
    die "$EXIT_DB" "Use a new empty database, or clean the existing database manually before rerunning the installer."
}

validate_before_env_write() {
    validate_storage_path "$STORAGE_PATH"
    if [[ "$INSTALL_MODE" == "local" ]]; then
        check_write_parent "$STORAGE_PATH" || die "$EXIT_ENV" "Cannot create or write to STORAGE_PATH parent for ${STORAGE_PATH}"
        local output
        if ! output=$(test_db_connection_local 2>&1); then
            die "$EXIT_DB" "$output"
        fi
        log_ok "$output"
        handle_existing_database_state
    else
        ssh "$REMOTE_TARGET" "mkdir -p $(shell_quote_sh "$STORAGE_PATH")" >> "$INSTALL_LOG" 2>&1 || die "$EXIT_ENV" "Cannot create remote storage path ${STORAGE_PATH}"
        local output
        if ! output=$(test_db_connection_remote 2>&1); then
            die "$EXIT_DB" "$output"
        fi
        log_ok "$output"
        handle_existing_database_state
    fi
}

write_env_contents() {
    cat <<EOF
# ============================================================
# Andrea Helpdesk - Environment Configuration
# Generated by bin/install-cli.sh on $(timestamp)
# ============================================================

# Application
APP_ENV=production
APP_DEBUG=false
APP_URL=${APP_URL}
APP_TIMEZONE=${APP_TIMEZONE}
UPDATE_VERSION_URL=
UPDATE_REPO_ZIP_URL=
UPDATE_REPO_PREFIX=

# Security
JWT_SECRET=${JWT_SECRET}
JWT_ACCESS_TTL=900
JWT_REFRESH_TTL=2592000

# Database
DB_HOST=${DB_HOST}
DB_PORT=${DB_PORT}
DB_DATABASE=${DB_DATABASE}
DB_USERNAME=${DB_USERNAME}
DB_PASSWORD="${DB_PASSWORD//\"/\\\"}"
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci

# Storage
STORAGE_PATH=${STORAGE_PATH}
MAX_ATTACHMENT_SIZE=10485760

# Admin Account (used by: make db-seed)
ADMIN_NAME="${ADMIN_NAME//\"/\\\"}"
ADMIN_EMAIL=${ADMIN_EMAIL}
ADMIN_PASSWORD="${ADMIN_PASSWORD//\"/\\\"}"
EOF
}

write_env_file() {
    local env_path="$WORK_DIR/.env"
    if [[ -f "$env_path" ]]; then
        if ! confirm ".env already exists. Back it up and overwrite it?"; then
            die "$EXIT_ENV" "Refusing to overwrite existing .env"
        fi
        mv "$env_path" "${env_path}.bak.$(date +%Y%m%d%H%M%S)"
        log_warn "Existing .env was backed up."
    fi
    write_env_contents > "$env_path"
    chmod 600 "$env_path" || true
    log_ok "Wrote ${env_path}"
    if [[ "$INSTALL_MODE" == "remote" ]]; then
        copy_file_remote "$env_path" "${REMOTE_PATH}/.env" || die "$EXIT_ENV" "Failed to copy .env to remote host."
        log_ok "Copied .env to ${REMOTE_TARGET}:${REMOTE_PATH}/.env"
    fi
}

run_composer_install() {
    run_step "Installing PHP dependencies with Composer locally" composer install --no-dev --optimize-autoloader
}

run_db_migrate() {
    if [[ "$INSTALL_MODE" == "local" ]]; then
        run_step "Running database migrations locally (schema + numbered migrations)" make db-migrate
    else
        run_step "Deploying the application to the remote server" make deploy
        run_step "Running database migrations on the remote server (schema + numbered migrations)" ssh "$REMOTE_TARGET" "cd $(shell_quote_sh "$REMOTE_PATH") && make db-migrate"
    fi
}

run_db_seed() {
    if [[ "$INSTALL_MODE" == "local" ]]; then
        run_step "Seeding the initial admin agent locally from .env" make db-seed
    else
        run_step "Seeding the initial admin agent on the remote server from .env" ssh "$REMOTE_TARGET" "cd $(shell_quote_sh "$REMOTE_PATH") && make db-seed"
    fi
}

run_fetch_assets() {
    run_step "Fetching frontend assets locally (Bootstrap, jQuery, Quill, DOMPurify)" make fetch-assets
}

run_cron_install() {
    if [[ "$INSTALL_MODE" == "local" ]]; then
        if run_step_capture "Installing the local IMAP/SLA cron entry" make cron-install-local >/dev/null; then
            CRON_STATUS="installed locally"
        else
            CRON_STATUS="manual setup required"
        fi
    else
        if run_step_capture "Installing the remote IMAP/SLA cron entry" make cron-install-production >/dev/null; then
            CRON_STATUS="installed on ${PROD_HOST}"
        else
            CRON_STATUS="manual setup required on ${PROD_HOST}"
        fi
    fi
}

ensure_storage_layout_local() {
    mkdir -p "${STORAGE_PATH}/attachments" "${STORAGE_PATH}/logs"
    touch "${STORAGE_PATH}/logs/app.log" "${STORAGE_PATH}/logs/imap.log"
}

ensure_storage_layout_remote() {
    ssh "$REMOTE_TARGET" "mkdir -p $(shell_quote_sh "${STORAGE_PATH}/attachments") $(shell_quote_sh "${STORAGE_PATH}/logs") && touch $(shell_quote_sh "${STORAGE_PATH}/logs/app.log") $(shell_quote_sh "${STORAGE_PATH}/logs/imap.log")" >> "$INSTALL_LOG" 2>&1
}

try_group_access_local() {
    local group=""
    for candidate in www-data apache nginx; do
        if getent group "$candidate" >/dev/null 2>&1; then
            if id -nG | tr ' ' '\n' | grep -qx "$candidate"; then
                group="$candidate"
                break
            fi
        fi
    done
    if [[ -n "$group" ]]; then
        if chgrp -R "$group" "$STORAGE_PATH" >> "$INSTALL_LOG" 2>&1; then
            find "$STORAGE_PATH" -type d -exec chmod 2775 {} \; >> "$INSTALL_LOG" 2>&1 || true
            find "$STORAGE_PATH" -type f -exec chmod 664 {} \; >> "$INSTALL_LOG" 2>&1 || true
            log_ok "Applied group-write permissions for storage using group '${group}'."
            return 0
        fi
    fi
    return 1
}

try_group_access_remote() {
    local remote_script='
group=""
for candidate in www-data apache nginx; do
  if getent group "$candidate" >/dev/null 2>&1; then
    if id -nG | tr " " "\n" | grep -qx "$candidate"; then
      group="$candidate"
      break
    fi
  fi
done
if [ -n "$group" ]; then
  chgrp -R "$group" '"$(shell_quote_sh "$STORAGE_PATH")"' &&
  find '"$(shell_quote_sh "$STORAGE_PATH")"' -type d -exec chmod 2775 {} \; &&
  find '"$(shell_quote_sh "$STORAGE_PATH")"' -type f -exec chmod 664 {} \;
fi
'
    ssh "$REMOTE_TARGET" "$remote_script" >> "$INSTALL_LOG" 2>&1
}

fix_storage_permissions() {
    LAST_STEP="setting storage permissions"
    log_info "Ensuring storage directories and log files are present."
    if [[ "$INSTALL_MODE" == "local" ]]; then
        ensure_storage_layout_local
        if ! try_group_access_local; then
            chmod 775 "${STORAGE_PATH}" "${STORAGE_PATH}/attachments" "${STORAGE_PATH}/logs" >> "$INSTALL_LOG" 2>&1 || true
            chmod 664 "${STORAGE_PATH}/logs/app.log" "${STORAGE_PATH}/logs/imap.log" >> "$INSTALL_LOG" 2>&1 || true
            log_warn "Applied conservative chmod permissions to storage. If the web server still cannot write there, adjust group ownership manually."
        fi
    else
        ensure_storage_layout_remote
        if ! try_group_access_remote; then
            ssh "$REMOTE_TARGET" "chmod 775 $(shell_quote_sh "$STORAGE_PATH") $(shell_quote_sh "${STORAGE_PATH}/attachments") $(shell_quote_sh "${STORAGE_PATH}/logs") && chmod 664 $(shell_quote_sh "${STORAGE_PATH}/logs/app.log") $(shell_quote_sh "${STORAGE_PATH}/logs/imap.log")" >> "$INSTALL_LOG" 2>&1 || true
            log_warn "Applied conservative chmod permissions to remote storage. If the web server still cannot write there, adjust group ownership manually on ${REMOTE_TARGET}."
        else
            log_ok "Applied remote group-write permissions for storage."
        fi
    fi
}

verify_db_state_local() {
    APP_ROOT="$WORK_DIR" php <<'PHP'
<?php
$root = getenv('APP_ROOT');
$env = $root . '/.env';
if (!file_exists($env)) {
    fwrite(STDERR, ".env not found\n");
    exit(1);
}
$vars = parse_ini_file($env, false, INI_SCANNER_RAW);
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $vars['DB_HOST'], $vars['DB_PORT'], $vars['DB_DATABASE']);
$pdo = new PDO($dsn, $vars['DB_USERNAME'], trim($vars['DB_PASSWORD'], '"'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$schema = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = " . $pdo->quote($vars['DB_DATABASE']) . " AND table_name = 'agents'")->fetchColumn();
$agent = (int)$pdo->query("SELECT COUNT(*) FROM agents WHERE email = " . $pdo->quote($vars['ADMIN_EMAIL']))->fetchColumn();
if ($schema < 1 || $agent < 1) {
    fwrite(STDERR, "Database verification failed.\n");
    exit(2);
}
echo "Database verification passed.\n";
PHP
}

verify_db_state_remote() {
    ssh "$REMOTE_TARGET" "APP_ROOT=$(shell_quote_sh "$REMOTE_PATH") php" <<'PHP'
<?php
$root = getenv('APP_ROOT');
$env = $root . '/.env';
if (!file_exists($env)) {
    fwrite(STDERR, ".env not found\n");
    exit(1);
}
$vars = parse_ini_file($env, false, INI_SCANNER_RAW);
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $vars['DB_HOST'], $vars['DB_PORT'], $vars['DB_DATABASE']);
$pdo = new PDO($dsn, $vars['DB_USERNAME'], trim($vars['DB_PASSWORD'], '"'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$schema = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = " . $pdo->quote($vars['DB_DATABASE']) . " AND table_name = 'agents'")->fetchColumn();
$agent = (int)$pdo->query("SELECT COUNT(*) FROM agents WHERE email = " . $pdo->quote($vars['ADMIN_EMAIL']))->fetchColumn();
if ($schema < 1 || $agent < 1) {
    fwrite(STDERR, "Database verification failed.\n");
    exit(2);
}
echo "Database verification passed.\n";
PHP
}

http_probe() {
    if command -v curl >/dev/null 2>&1; then
        curl -fsSL "$APP_URL"
        return
    fi
    if command -v wget >/dev/null 2>&1; then
        wget -qO - "$APP_URL"
        return
    fi
    php -r '$u=$argv[1]; $c=@file_get_contents($u); if ($c === false) exit(1); echo $c;' "$APP_URL"
}

final_check() {
    LAST_STEP="final verification"
    log_info "Running final verification checks."
    [[ -f "${WORK_DIR}/.env" ]] || die "$EXIT_VERIFY" ".env was not created."
    [[ -f "${WORK_DIR}/vendor/autoload.php" ]] || die "$EXIT_VERIFY" "vendor/autoload.php is missing."
    [[ -f "${WORK_DIR}/public_html/assets/vendor/bootstrap/bootstrap.min.css" ]] || die "$EXIT_VERIFY" "Bootstrap asset is missing."
    [[ -f "${WORK_DIR}/public_html/assets/vendor/jquery/jquery.min.js" ]] || die "$EXIT_VERIFY" "jQuery asset is missing."
    [[ -f "${WORK_DIR}/public_html/assets/vendor/dompurify/purify.min.js" ]] || die "$EXIT_VERIFY" "DOMPurify asset is missing."
    [[ -f "${WORK_DIR}/public_html/assets/vendor/quill/quill.min.js" ]] || die "$EXIT_VERIFY" "Quill asset is missing."

    if [[ "$INSTALL_MODE" == "local" ]]; then
        [[ -d "${STORAGE_PATH}/attachments" ]] || die "$EXIT_VERIFY" "Storage attachments directory is missing."
        [[ -f "${STORAGE_PATH}/logs/app.log" ]] || die "$EXIT_VERIFY" "app.log is missing."
        [[ -f "${STORAGE_PATH}/logs/imap.log" ]] || die "$EXIT_VERIFY" "imap.log is missing."
        local db_output
        db_output=$(verify_db_state_local 2>&1) || die "$EXIT_VERIFY" "$db_output"
        log_ok "$db_output"
    else
        ssh "$REMOTE_TARGET" "test -f $(shell_quote_sh "${REMOTE_PATH}/.env") && test -f $(shell_quote_sh "${REMOTE_PATH}/vendor/autoload.php") && test -f $(shell_quote_sh "${REMOTE_PATH}/public_html/assets/vendor/bootstrap/bootstrap.min.css") && test -f $(shell_quote_sh "${REMOTE_PATH}/public_html/assets/vendor/jquery/jquery.min.js") && test -f $(shell_quote_sh "${REMOTE_PATH}/public_html/assets/vendor/dompurify/purify.min.js") && test -f $(shell_quote_sh "${REMOTE_PATH}/public_html/assets/vendor/quill/quill.min.js") && test -d $(shell_quote_sh "${STORAGE_PATH}/attachments") && test -f $(shell_quote_sh "${STORAGE_PATH}/logs/app.log") && test -f $(shell_quote_sh "${STORAGE_PATH}/logs/imap.log")" || die "$EXIT_VERIFY" "Remote application layout verification failed."
        local db_output
        db_output=$(verify_db_state_remote 2>&1) || die "$EXIT_VERIFY" "$db_output"
        log_ok "$db_output"
    fi

    local http_output=""
    if http_output=$(http_probe 2>/dev/null); then
        if printf '%s' "$http_output" | grep -qi "Andrea Helpdesk"; then
            log_ok "HTTP check to ${APP_URL} succeeded."
        else
            log_warn "HTTP check reached ${APP_URL}, but the response did not contain the expected Andrea Helpdesk marker."
        fi
    else
        log_warn "HTTP check to ${APP_URL} failed. The install may still be valid, but the web server or document root may need attention."
    fi
}

print_success_message() {
    cat <<EOF

Andrea Helpdesk installation completed successfully.

Summary:
- Mode: ${INSTALL_MODE}
- App URL: ${APP_URL}
- Admin email: ${ADMIN_EMAIL}
- Storage path: ${STORAGE_PATH}
- Cron status: ${CRON_STATUS}

Next steps:
1. Visit ${APP_URL}
2. Log in with:
   - Username: ${ADMIN_EMAIL}
   - Password: the ADMIN_PASSWORD you chose during installation
3. Configure branding, SMTP, IMAP, and SLA settings.
4. Test inbound email polling and outbound SMTP.

$(if [[ -n "${DB_BACKUP_PATH}" ]]; then printf 'Database backup created before reinstall:\n- %s\n\n' "${DB_BACKUP_PATH}"; fi)

Installer log:
- ${INSTALL_LOG}

Docs:
- ${DOCS_INSTALL_URL}

EOF
}

print_banner() {
    cat <<EOF
Andrea Helpdesk CLI Installer ${SCRIPT_VERSION}

Source repository: ${REPO_URL}
Default ref: ${REPO_REF}
Install docs: ${DOCS_INSTALL_URL}

This installer will:
- clone the application repository
- write a .env file
- install dependencies
- run migrations and seed the admin account
- fetch frontend assets
- configure cron

EOF
}

preflight_local_requirements() {
    detect_package_manager
    require_command git
    require_command php
    require_command composer
    require_command make
    require_php_version
    require_php_extension pdo_mysql required
    require_php_extension mbstring required
    require_php_extension openssl required
    require_php_extension curl recommended
    require_php_extension imap optional
}

preflight_remote_requirements() {
    detect_package_manager
    require_command git
    require_command php
    require_command composer
    require_command make
    require_command rsync
    require_command ssh
    require_command scp
    require_php_version
    require_php_extension pdo_mysql required
    require_php_extension mbstring required
    require_php_extension openssl required
    require_php_extension curl recommended
    require_php_extension imap optional
    require_remote_command php
    require_remote_command composer
    require_remote_command make
    require_remote_php_version
    require_remote_php_extension pdo_mysql required
    require_remote_php_extension mbstring required
    require_remote_php_extension openssl required
    require_remote_php_extension curl recommended
    require_remote_php_extension imap optional
}

main() {
    : > "$INSTALL_LOG"
    init_tty
    print_banner
    if ! confirm "Continue with the Andrea Helpdesk CLI installer?"; then
        log_info "Installer cancelled."
        exit 0
    fi

    choose_install_mode
    if [[ "$INSTALL_MODE" == "remote" ]]; then
        collect_remote_details
        require_command ssh
        require_command scp
        require_command rsync
        check_ssh_access
        preflight_remote_requirements
    else
        preflight_local_requirements
    fi

    collect_repo_details
    collect_webroot
    validate_docroot_layout
    validate_local_path_permissions
    if [[ "$INSTALL_MODE" == "remote" ]]; then
        validate_remote_path_permissions
        prepare_remote_target_path
    fi
    clone_repo
    if [[ "$INSTALL_MODE" == "remote" ]]; then
        write_makefile_local
    fi
    ask_database_ready
    collect_app_details
    collect_database_details
    collect_storage_path
    collect_admin_details
    validate_before_env_write
    write_env_file

    run_composer_install
    run_fetch_assets
    if [[ "$INSTALL_MODE" == "remote" ]]; then
        run_step "Deploying the configured application to the remote server" make deploy
        run_step "Recopying .env to the remote server after deploy" copy_file_remote "${WORK_DIR}/.env" "${REMOTE_PATH}/.env"
    fi
    run_db_migrate
    run_db_seed
    fix_storage_permissions
    run_cron_install
    final_check
    print_success_message
}

main "$@"
