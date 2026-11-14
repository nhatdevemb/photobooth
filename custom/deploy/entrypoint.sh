#!/usr/bin/env bash
set -euo pipefail

# A new named volume is initialized as root; runtime writers all use www-data.
if [[ "${1:-}" == "apache2ctl" ]]; then
    install -d -o www-data -g www-data -m 0750 "${DATA_DIR}" "$(dirname "${DATABASE_PATH}")"
    runuser -u www-data -- php /var/www/photobooth/custom/bin/migrate.php
    exec "$@"
fi

exec runuser -u www-data -- "$@"
