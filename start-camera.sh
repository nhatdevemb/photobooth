#!/usr/bin/env bash
set -euo pipefail

PHOTOBOOTH_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
export PATH="${HOME}/.local/bin:${PATH}"

for camera_command in python3 php; do
    if ! command -v "${camera_command}" >/dev/null 2>&1; then
        printf 'Thiếu %s. Hãy cài dependency theo README.md trước khi chạy.\n' "${camera_command}" >&2
        exit 1
    fi
done

if [[ ! -f "${PHOTOBOOTH_ROOT}/custom/vendor/autoload.php" ]]; then
    printf 'Thiếu thư viện PHP. Chạy composer install trong thư mục custom/ trước.\n' >&2
    exit 1
fi

exec python3 "${PHOTOBOOTH_ROOT}/custom/bin/camera-test.py" \
    --open-browser --port "${PB_CAMERA_PORT:-8090}" "$@"
