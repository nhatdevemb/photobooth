#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${REPO_ROOT}"

echo "=== [1/7] Checking upstream baseline pin (v4.5.1) ==="
php -r '
$u = json_decode(file_get_contents("custom/upstream.json"), true);
$tag = $u["upstream"]["baseline_tag"] ?? "";
$sha = $u["upstream"]["commit_sha"] ?? "";
if ($tag !== "v4.5.1" || $sha !== "a8841409ed8d0db485191269d6c302505b67f64f") {
    fwrite(STDERR, "Invalid upstream.json baseline pin\n");
    exit(1);
}
echo "Baseline OK: " . $tag . " (" . $sha . ")\n";
'

echo "=== [2/7] Checking PHP syntax across custom/ ==="
find custom/src custom/bin custom/public custom/tests -name "*.php" -print0 | xargs -0 -n1 php -l

echo "=== [3/7] Checking JavaScript syntax in custom/public/assets/js/ ==="
node --check custom/public/assets/js/operator.js
node --check custom/public/assets/js/browser-camera.js
node --test custom/tests/node/lkmagic-bridge.test.cjs

echo "=== [4/7] Running Python image pipeline tests (pytest) ==="
(cd custom && python3 -m pytest -v tests/python/)

echo "=== [5/7] Running PHPUnit Backend & Recovery test suite (TC-01..19, IT-01..18) ==="
(cd custom && vendor/bin/phpunit --colors=never)

echo "=== [6/7] Running Live HTTP + Worker Daemon E2E suite (IMP-031) ==="
php custom/tests/e2e/run-e2e.php

echo "=== [7/7] Verifying no runtime secrets or customer data tracked in Git ==="
TRACKED_SECRETS="$(git ls-files 'custom/.env' '*.sqlite' '*.sqlite-wal' '*.sqlite-shm' 'private/data/*' || true)"
if [[ -n "${TRACKED_SECRETS}" ]]; then
    echo "ERROR: Runtime data or secrets tracked in Git: ${TRACKED_SECRETS}" >&2
    exit 1
fi

echo "=== ALL CI CHECKS PASSED ==="
