#!/usr/bin/env bash
set -e

DATA_DIR="/tmp/mysql_btl18/data"
RUN_DIR="/tmp/mysql_btl18/run"
SOCK_FILE="$RUN_DIR/mysqld.sock"
PID_FILE="$RUN_DIR/mysqld.pid"
LOG_FILE="/tmp/mysql_btl18/mysqld.log"
PORT=3307

mkdir -p "$DATA_DIR" "$RUN_DIR"

if [ ! -d "$DATA_DIR/mysql" ]; then
    echo "[MySQL] Khởi tạo thư mục dữ liệu MySQL cục bộ tại $DATA_DIR ..."
    mysqld --no-defaults --initialize-insecure --datadir="$DATA_DIR" --user="$(whoami)" > "$LOG_FILE" 2>&1
fi

if [ -f "$PID_FILE" ] && kill -0 "$(cat "$PID_FILE")" 2>/dev/null; then
    echo "[MySQL] Máy chủ MySQL cục bộ đang chạy (PID: $(cat "$PID_FILE"), Cổng: $PORT)."
else
    echo "[MySQL] Đang khởi động máy chủ MySQL cục bộ trên cổng $PORT ..."
    mysqld --no-defaults \
        --datadir="$DATA_DIR" \
        --socket="$SOCK_FILE" \
        --port="$PORT" \
        --pid-file="$PID_FILE" \
        --mysqlx=0 >> "$LOG_FILE" 2>&1 &

    for i in {1..15}; do
        if [ -S "$SOCK_FILE" ]; then
            break
        fi
        sleep 1
    done
    echo "[MySQL] Đã khởi động thành công (127.0.0.1:$PORT | Socket: $SOCK_FILE)."
fi
