#!/usr/bin/env bash
set -e

CURRENT_USER="$(whoami)"
BASE_DIR="/tmp/mysql_btl18"

# Nếu thư mục /tmp/mysql_btl18 đã được tạo bởi root (do chạy sudo trước đó) và user hiện tại không có quyền ghi,
# tự động chuyển sang thư mục riêng của user hiện tại để tránh lỗi Permission denied.
if [ -e "$BASE_DIR" ] && [ ! -w "$BASE_DIR" ]; then
    BASE_DIR="/tmp/mysql_btl18_${CURRENT_USER}"
fi

DATA_DIR="$BASE_DIR/data"
RUN_DIR="$BASE_DIR/run"
SOCK_FILE="$RUN_DIR/mysqld.sock"
PID_FILE="$RUN_DIR/mysqld.pid"
LOG_FILE="$BASE_DIR/mysqld.log"
PORT=3307

mkdir -p "$DATA_DIR" "$RUN_DIR"

if [ ! -d "$DATA_DIR/mysql" ]; then
    echo "[MySQL] Khởi tạo thư mục dữ liệu MySQL cục bộ tại $DATA_DIR ..."
    mysqld --no-defaults --initialize-insecure --datadir="$DATA_DIR" --user="$CURRENT_USER" > "$LOG_FILE" 2>&1
fi

# Kiểm tra xem cổng 3307 đã có tiến trình mysqld nào đang lắng nghe chưa
if (echo > /dev/tcp/127.0.0.1/$PORT) >/dev/null 2>&1; then
    echo "[MySQL] Máy chủ MySQL cục bộ đang chạy trên cổng $PORT."
    exit 0
fi

if [ -f "$PID_FILE" ] && kill -0 "$(cat "$PID_FILE")" 2>/dev/null; then
    echo "[MySQL] Máy chủ MySQL cục bộ đang chạy (PID: $(cat "$PID_FILE"), Cổng: $PORT)."
else
    rm -f "$SOCK_FILE" "$PID_FILE"
    echo "[MySQL] Đang khởi động máy chủ MySQL cục bộ trên cổng $PORT ..."
    mysqld --no-defaults \
        --user="$CURRENT_USER" \
        --datadir="$DATA_DIR" \
        --socket="$SOCK_FILE" \
        --port="$PORT" \
        --pid-file="$PID_FILE" \
        --mysqlx=0 >> "$LOG_FILE" 2>&1 &

    for i in {1..20}; do
        if [ -S "$SOCK_FILE" ] && (echo > /dev/tcp/127.0.0.1/$PORT) >/dev/null 2>&1; then
            break
        fi
        sleep 1
    done
    echo "[MySQL] Đã khởi động thành công (127.0.0.1:$PORT | Socket: $SOCK_FILE)."
fi
