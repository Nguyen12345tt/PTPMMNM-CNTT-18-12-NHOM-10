#!/usr/bin/env bash
set -e

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT="${1:-8080}"

bash "$ROOT_DIR/scripts/start_local_mysql.sh"
php -d extension=pdo_mysql -d extension=mysqli "$ROOT_DIR/scripts/init_db.php"

echo ""
echo "================================================================="
echo " Hệ thống Xếp Thời Khóa Biểu (Đề tài 18 - Nhóm 11) đang chạy tại:"
echo " 👉 http://localhost:$PORT"
echo "================================================================="
exec php -d extension=pdo_mysql -d extension=mysqli -S "0.0.0.0:$PORT" -t "$ROOT_DIR/public"
