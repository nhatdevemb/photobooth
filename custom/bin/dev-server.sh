#!/usr/bin/env bash
set -euo pipefail

export PATH="$HOME/.local/bin:$PATH"

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${REPO_ROOT}"

ENV_FILE="${REPO_ROOT}/custom/.env"
if [[ ! -f "${ENV_FILE}" ]]; then
    cp "${REPO_ROOT}/custom/.env.example" "${ENV_FILE}"
fi

# Replace placeholder SHARE_SIGNING_KEY automatically if needed
if grep -q "CHANGE_ME_GENERATE_WITH_OPENSSL_RAND_HEX_32" "${ENV_FILE}"; then
    RAND_KEY="$(php -r 'echo bin2hex(random_bytes(32));')"
    sed -i "s/CHANGE_ME_GENERATE_WITH_OPENSSL_RAND_HEX_32/${RAND_KEY}/g" "${ENV_FILE}"
fi

# Detect primary LAN IP so QR Share URLs work across the local network
LAN_IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
if [[ -z "${LAN_IP}" ]]; then
    LAN_IP="192.168.1.50"
fi
sed -i "s|^OPERATOR_BIND=.*|OPERATOR_BIND=0.0.0.0:8080|g" "${ENV_FILE}"
sed -i "s|^SHARE_BASE_URL=.*|SHARE_BASE_URL=http://${LAN_IP}:8081|g" "${ENV_FILE}"
sed -i "s|^TRUSTED_HOSTS=.*|TRUSTED_HOSTS=127.0.0.1,localhost,${LAN_IP}|g" "${ENV_FILE}"

echo "=== [1/3] Running database migrations (001 & 002) ==="
php custom/bin/migrate.php

echo "=== [2/3] Ensuring default test accounts (operator1 & admin) ==="
php custom/bin/manage-user.php create --username=operator1 --password="OperatorPass123!" --role=operator >/dev/null
php custom/bin/manage-user.php create --username=admin --password="AdminPass123!" --role=admin >/dev/null

WORKER_PID=""
GUEST_PID=""
cleanup() {
    if [[ -n "${WORKER_PID}" ]] && kill -0 "${WORKER_PID}" 2>/dev/null; then
        kill "${WORKER_PID}" 2>/dev/null || true
    fi
    if [[ -n "${GUEST_PID}" ]] && kill -0 "${GUEST_PID}" 2>/dev/null; then
        kill "${GUEST_PID}" 2>/dev/null || true
    fi
}
trap cleanup EXIT INT TERM

echo "=== [3/3] Starting Worker, Guest LAN (0.0.0.0:8081), and Operator UI (0.0.0.0:8080) ==="
php custom/bin/worker.php --sleep-ms=100 &
WORKER_PID=$!

PHOTOBOOTH_LISTENER_MODE=guest php -S 0.0.0.0:8081 custom/public/guest.php >/dev/null 2>&1 &
GUEST_PID=$!

echo ""
echo "=================================================================="
echo "  Photobooth Operator Edition — Dev Server (All Interfaces 0.0.0.0)"
echo "  - Trên máy này (Local) : http://127.0.0.1:8080"
echo "  - Qua mạng LAN         : http://${LAN_IP}:8080"
echo "  - Cổng QR Khách (LAN)  : http://${LAN_IP}:8081"
echo "  - Tài khoản 1          : operator1 / OperatorPass123!"
echo "  - Tài khoản 2          : admin     / AdminPass123!"
echo "  (Nhấn Ctrl+C để dừng toàn bộ dịch vụ)"
echo "=================================================================="
echo ""

PHOTOBOOTH_LISTENER_MODE=operator exec php -S 0.0.0.0:8080 custom/public/index.php
