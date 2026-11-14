#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${REPO_ROOT}"
SMOKE_ID="pb-smoke-$(date +%s)-$$"
SMOKE_IMAGE="${SMOKE_ID}:test"
SMOKE_VOLUME="${SMOKE_ID}-data"
SMOKE_WEB="${SMOKE_ID}-web"
SMOKE_WORKER="${SMOKE_ID}-worker"
SMOKE_ENV="$(mktemp)"
cleanup() {
    local result=$?
    if [[ ${result} -ne 0 ]]; then
        docker logs "${SMOKE_WEB}" 2>/dev/null || true
        docker logs "${SMOKE_WORKER}" 2>/dev/null || true
    fi
    docker rm -f "${SMOKE_WORKER}" "${SMOKE_WEB}" >/dev/null 2>&1 || true
    docker volume rm "${SMOKE_VOLUME}" >/dev/null 2>&1 || true
    docker image rm "${SMOKE_IMAGE}" >/dev/null 2>&1 || true
    rm -f "${SMOKE_ENV}"
}
trap cleanup EXIT
cat > "${SMOKE_ENV}" <<'ENV'
DATA_DIR=/var/lib/photobooth-operator
DATABASE_PATH=/var/lib/photobooth-operator/db/photobooth.sqlite
SHARE_BASE_URL=http://192.168.4.1:8081
SHARE_SIGNING_KEY=smoke-test-only-0123456789abcdef0123456789
TRUSTED_HOSTS=127.0.0.1,localhost,192.168.4.1
CAMERA_ADAPTER=mock
PRINTER_ADAPTER=mock
MOCK_COUNTDOWN_DELAY_MS=0
MOCK_CAPTURE_DELAY_MS=0
DISK_LOW_BYTES=1048576
ENV
docker build -f custom/deploy/Dockerfile -t "${SMOKE_IMAGE}" .
docker volume create "${SMOKE_VOLUME}" >/dev/null
docker run -d --name "${SMOKE_WEB}" --env-file "${SMOKE_ENV}" \
    -v "${SMOKE_VOLUME}:/var/lib/photobooth-operator" \
    -p 127.0.0.1::8080 -p 127.0.0.1::8081 "${SMOKE_IMAGE}" >/dev/null
PB_OP_PORT="$(docker inspect --format '{{(index (index .NetworkSettings.Ports "8080/tcp") 0).HostPort}}' "${SMOKE_WEB}")"
PB_GUEST_PORT="$(docker inspect --format '{{(index (index .NetworkSettings.Ports "8081/tcp") 0).HostPort}}' "${SMOKE_WEB}")"
export PB_OP_URL="http://127.0.0.1:${PB_OP_PORT}"
export PB_GUEST_URL="http://127.0.0.1:${PB_GUEST_PORT}"
for attempt in $(seq 1 60); do
    if curl --fail --silent "${PB_OP_URL}/" >/dev/null; then break; fi
    sleep 1
done
curl --fail --silent "${PB_OP_URL}/" >/dev/null
docker exec -u www-data "${SMOKE_WEB}" php custom/bin/manage-user.php create \
    --username=smokeadmin --password=SmokeOnlyPass123! --role=admin >/dev/null
docker run -d --name "${SMOKE_WORKER}" --env-file "${SMOKE_ENV}" \
    -v "${SMOKE_VOLUME}:/var/lib/photobooth-operator" \
    "${SMOKE_IMAGE}" php /var/www/photobooth/custom/bin/worker.php >/dev/null
python3 custom/tests/docker/smoke.py
