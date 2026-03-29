#!/usr/bin/env bash
set -Eeuo pipefail

# =========================
# Pet Hotel DB Tool
# TrueNAS + Docker + MySQL
# =========================

# ===== 可依需求修改的預設值 =====
MYSQL_CONTAINER="ix-case0001-pet-hotel-site-mysql-1"
DB_NAME="pet_hotel_site"
BACKUP_DIR="/mnt/Main/apps/pet-hotel/backup"
APP_LIFECYCLE_LOG="/var/log/app_lifecycle.log"
DEFAULT_IMPORT_DIR="/mnt/Main/apps/pet-hotel"

# ===== 顏色 =====
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

# ===== 基本輸出 =====
info()    { echo -e "${CYAN}[INFO]${NC} $*"; }
ok()      { echo -e "${GREEN}[OK]${NC} $*"; }
warn()    { echo -e "${YELLOW}[WARN]${NC} $*"; }
error()   { echo -e "${RED}[ERROR]${NC} $*" >&2; }
section() { echo -e "\n${BLUE}============================================================${NC}\n${BLUE}$*${NC}\n${BLUE}============================================================${NC}"; }

pause() {
  echo
  read -r -p "按 Enter 繼續..."
}

confirm() {
  local prompt="${1:-你確定要繼續嗎？}"
  local ans
  read -r -p "$prompt [y/N] " ans
  [[ "${ans:-N}" =~ ^[Yy]$ ]]
}

# ===== 清理處理 =====
_tmp_files=()
cleanup() {
  local f
  for f in "${_tmp_files[@]:-}"; do
    [[ -f "$f" ]] && rm -f "$f" || true
  done
}
trap cleanup EXIT

# ===== 檢查 =====
ensure_backup_dir() {
  mkdir -p "$BACKUP_DIR"
}

container_exists() {
  docker inspect "$MYSQL_CONTAINER" >/dev/null 2>&1
}

container_running() {
  docker inspect -f '{{.State.Running}}' "$MYSQL_CONTAINER" 2>/dev/null | grep -q '^true$'
}

container_health() {
  docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}no-healthcheck{{end}}' "$MYSQL_CONTAINER" 2>/dev/null || true
}

wait_for_mysql_ready() {
  local retries="${1:-20}"
  local i

  info "等待 MySQL 容器可連線..."
  for ((i=1; i<=retries; i++)); do
    if docker exec -i "$MYSQL_CONTAINER" sh -lc 'mysqladmin ping -h 127.0.0.1 -uroot -p"$MYSQL_ROOT_PASSWORD" --silent' >/dev/null 2>&1; then
      ok "MySQL 已可連線。"
      return 0
    fi
    sleep 2
  done

  error "MySQL 等待逾時，仍無法連線。"
  return 1
}

ensure_container_ready() {
  if ! container_exists; then
    error "找不到容器：$MYSQL_CONTAINER"
    return 1
  fi

  if ! container_running; then
    error "容器目前未在執行：$MYSQL_CONTAINER"
    return 1
  fi

  if ! wait_for_mysql_ready 20; then
    return 1
  fi
}

# ===== SQL / DB 操作 =====
mysql_exec() {
  local sql="$1"
  docker exec -i "$MYSQL_CONTAINER" sh -lc "mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\" -e \"$sql\""
}

mysql_exec_db() {
  local sql="$1"
  docker exec -i "$MYSQL_CONTAINER" sh -lc "mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\" \"$DB_NAME\" -e \"$sql\""
}

dump_database() {
  local output_file="$1"
  ensure_backup_dir
  info "開始匯出資料庫：$DB_NAME"
  info "輸出檔案：$output_file"

  docker exec -i "$MYSQL_CONTAINER" sh -lc \
    'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers --default-character-set=utf8mb4 "'"$DB_NAME"'"' \
    > "$output_file"

  if [[ -s "$output_file" ]]; then
    ok "資料庫匯出成功：$output_file"
  else
    error "匯出失敗，檔案為空：$output_file"
    return 1
  fi
}

import_sql_file() {
  local sql_file="$1"

  if [[ ! -f "$sql_file" ]]; then
    error "找不到 SQL 檔：$sql_file"
    return 1
  fi

  info "開始匯入 SQL：$sql_file"

  case "$sql_file" in
    *.sql)
      cat "$sql_file" | docker exec -i "$MYSQL_CONTAINER" sh -lc \
        'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "'"$DB_NAME"'"'
      ;;
    *.sql.gz)
      gzip -dc "$sql_file" | docker exec -i "$MYSQL_CONTAINER" sh -lc \
        'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "'"$DB_NAME"'"'
      ;;
    *)
      error "只支援 .sql 或 .sql.gz：$sql_file"
      return 1
      ;;
  esac

  ok "SQL 匯入完成。"
}

reset_database() {
  info "重建資料庫：$DB_NAME"
  mysql_exec "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  ok "資料庫已重建。"
}

show_tables() {
  mysql_exec "USE \`$DB_NAME\`; SHOW TABLES;"
}

show_databases() {
  mysql_exec "SHOW DATABASES;"
}

count_tables() {
  docker exec -i "$MYSQL_CONTAINER" sh -lc \
    "mysql -N -uroot -p\"\$MYSQL_ROOT_PASSWORD\" -e \"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME';\""
}

# ===== 檔案列表 / 選擇 =====
declare -a SQL_FILE_CANDIDATES=()

list_sql_candidates() {
  SQL_FILE_CANDIDATES=()
  local dir
  local file

  section "可匯入的 SQL 檔案"

  for dir in "$DEFAULT_IMPORT_DIR" "$BACKUP_DIR"; do
    echo "目錄：$dir"
    if [[ -d "$dir" ]]; then
      while IFS= read -r -d '' file; do
        SQL_FILE_CANDIDATES+=("$file")
      done < <(find "$dir" -maxdepth 1 -type f \( -name "*.sql" -o -name "*.sql.gz" \) -print0 | sort -z)

      if find "$dir" -maxdepth 1 -type f \( -name "*.sql" -o -name "*.sql.gz" \) | grep -q .; then
        :
      else
        echo "  （沒有找到 .sql / .sql.gz）"
      fi
    else
      echo "  （目錄不存在）"
    fi
    echo
  done

  if [[ ${#SQL_FILE_CANDIDATES[@]} -eq 0 ]]; then
    warn "目前沒有找到任何可匯入的 SQL 檔。"
    return 0
  fi

  local i=1
  for file in "${SQL_FILE_CANDIDATES[@]}"; do
    printf "  [%d] %s\n" "$i" "$file"
    ((i++))
  done
  echo
}

choose_sql_file() {
  list_sql_candidates

  echo "你可以："
  echo "1. 輸入上面列表的編號"
  echo "2. 直接貼上完整 SQL 檔路徑"
  echo
  read -r -p "請輸入編號或完整路徑: " input

  if [[ -z "${input:-}" ]]; then
    warn "未輸入任何內容。"
    return 1
  fi

  if [[ "$input" =~ ^[0-9]+$ ]]; then
    local idx="$input"
    if (( idx >= 1 && idx <= ${#SQL_FILE_CANDIDATES[@]} )); then
      printf '%s\n' "${SQL_FILE_CANDIDATES[$((idx-1))]}"
      return 0
    else
      error "編號超出範圍。"
      return 1
    fi
  fi

  printf '%s\n' "$input"
}

# ===== Log =====
show_recent_logs() {
  section "最近 100 行 MySQL 容器 log"
  docker logs --tail=100 "$MYSQL_CONTAINER" 2>&1 || warn "無法讀取 MySQL 容器 log。"

  section "最近 100 行 app_lifecycle.log"
  if [[ -f "$APP_LIFECYCLE_LOG" ]]; then
    tail -n 100 "$APP_LIFECYCLE_LOG" 2>&1 || warn "無法讀取 $APP_LIFECYCLE_LOG"
  else
    warn "找不到 $APP_LIFECYCLE_LOG"
  fi
}

# ===== 狀態 =====
show_status() {
  section "目前工具設定"
  echo "MySQL 容器：$MYSQL_CONTAINER"
  echo "資料庫名稱：$DB_NAME"
  echo "備份目錄：$BACKUP_DIR"
  echo "預設匯入資料夾：$DEFAULT_IMPORT_DIR"
  echo "App lifecycle log：$APP_LIFECYCLE_LOG"

  section "容器狀態"
  if container_exists; then
    echo "存在：是"
    echo "執行中：$(docker inspect -f '{{.State.Running}}' "$MYSQL_CONTAINER" 2>/dev/null || true)"
    echo "Health：$(container_health)"
  else
    echo "存在：否"
    return 0
  fi

  if ensure_container_ready >/dev/null 2>&1; then
    section "資料庫清單"
    show_databases || true

    section "資料表清單"
    show_tables || true

    section "資料表數量"
    local table_count
    table_count="$(count_tables 2>/dev/null || echo '?')"
    echo "目前 $DB_NAME 共有表數：$table_count"
  else
    warn "MySQL 目前不可用，無法查詢資料庫內容。"
  fi
}

# ===== 備份 =====
export_db_interactive() {
  section "匯出目前資料庫"

  ensure_container_ready || return 1
  ensure_backup_dir

  local default_file
  default_file="$BACKUP_DIR/${DB_NAME}_$(date +%F_%H%M%S).sql"

  echo "預設備份檔：$default_file"
  read -r -p "輸出檔案路徑（直接 Enter 使用預設）: " output_file
  output_file="${output_file:-$default_file}"

  dump_database "$output_file"
}

# ===== 替換 / 回滾 =====
replace_db_interactive() {
  section "從指定 SQL 檔替換目前資料庫"

  ensure_container_ready || return 1
  ensure_backup_dir

  local sql_file
  if ! sql_file="$(choose_sql_file)"; then
    warn "未選到有效的 SQL 檔，操作取消。"
    return 0
  fi

  if [[ ! -f "$sql_file" ]]; then
    error "找不到檔案：$sql_file"
    return 1
  fi

  local backup_file
  backup_file="$BACKUP_DIR/${DB_NAME}_pre_replace_$(date +%F_%H%M%S).sql"

  echo
  echo "即將執行以下操作："
  echo "1. 備份目前資料庫 -> $backup_file"
  echo "2. 重建資料庫 $DB_NAME"
  echo "3. 匯入 $sql_file"
  echo "4. 若失敗，自動抓 log 並回滾"
  echo

  if ! confirm "確定要開始替換嗎？"; then
    warn "已取消。"
    return 0
  fi

  if ! dump_database "$backup_file"; then
    error "替換前備份失敗，停止操作。"
    return 1
  fi

  if reset_database && import_sql_file "$sql_file"; then
    ok "資料庫替換成功。"
    section "替換後資料表確認"
    show_tables || true
    return 0
  fi

  error "匯入失敗，開始擷取 log 並嘗試回滾。"
  show_recent_logs

  if [[ -f "$backup_file" && -s "$backup_file" ]]; then
    warn "開始回滾到替換前備份：$backup_file"
    if reset_database && import_sql_file "$backup_file"; then
      ok "回滾成功。"
      section "回滾後資料表確認"
      show_tables || true
    else
      error "回滾失敗，請立即檢查 MySQL 容器與備份檔。"
      return 1
    fi
  else
    error "找不到可用的回滾備份檔，無法回滾。"
    return 1
  fi
}

# ===== 直接查詢 =====
run_custom_sql_interactive() {
  section "直接執行 SQL（小心使用）"
  ensure_container_ready || return 1

  echo "範例：SELECT COUNT(*) FROM news_posts;"
  echo "範例：SHOW TABLES;"
  echo
  read -r -p "請輸入 SQL: " sql

  if [[ -z "${sql:-}" ]]; then
    warn "未輸入 SQL，操作取消。"
    return 0
  fi

  docker exec -it "$MYSQL_CONTAINER" sh -lc "mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\" \"$DB_NAME\" -e \"$sql\""
}

# ===== 主選單 =====
print_menu() {
  section "Pet Hotel 資料庫工具"
  echo "1) 匯出目前資料庫"
  echo "2) 從指定 SQL 檔替換目前資料庫（含自動備份 / 回滾）"
  echo "3) 檢查資料庫狀態與資料表"
  echo "4) 顯示最近 100 行 log"
  echo "5) 顯示目前工具設定"
  echo "6) 直接執行 SQL"
  echo "7) 列出可匯入 SQL 檔"
  echo "0) 離開"
  echo
}

main_loop() {
  while true; do
    print_menu
    read -r -p "請選擇功能 [0-7]: " choice
    echo

    case "${choice:-}" in
      1)
        export_db_interactive || true
        pause
        ;;
      2)
        replace_db_interactive || true
        pause
        ;;
      3)
        show_status || true
        pause
        ;;
      4)
        show_recent_logs || true
        pause
        ;;
      5)
        section "目前工具設定"
        echo "MySQL 容器：$MYSQL_CONTAINER"
        echo "資料庫名稱：$DB_NAME"
        echo "備份目錄：$BACKUP_DIR"
        echo "預設匯入資料夾：$DEFAULT_IMPORT_DIR"
        echo "App lifecycle log：$APP_LIFECYCLE_LOG"
        pause
        ;;
      6)
        run_custom_sql_interactive || true
        pause
        ;;
      7)
        list_sql_candidates || true
        pause
        ;;
      0)
        ok "已離開。"
        exit 0
        ;;
      *)
        warn "無效選項，請重新輸入。"
        pause
        ;;
    esac
  done
}

main_loop