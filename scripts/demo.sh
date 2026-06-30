#!/usr/bin/env bash
#
# Startet den kompletten Stack zum Ausprobieren:
#   MariaDB (Docker) -> Schema/Seed -> REST-API (php -S) -> Angular-Demo (ng serve)
#
# Aufruf (aus dem Repo-Root oder beliebig):
#   bash scripts/demo.sh
#
# Beenden mit Strg-C: API und MariaDB werden automatisch gestoppt.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

DB_HOST=127.0.0.1
DB_PORT="${DB_PORT:-3307}"
DB_NAME="${DB_NAME:-workflow}"
DB_USER=root
DB_PASS=root
API_PORT="${API_PORT:-8080}"

API_PID=""
cleanup() {
  echo ""
  echo "-> Stoppe API & MariaDB ..."
  [ -n "$API_PID" ] && kill "$API_PID" 2>/dev/null || true
  (cd "$ROOT/docker" && docker compose down) || true
}
trap cleanup EXIT INT TERM

export DB_HOST DB_PORT DB_NAME DB_USER DB_PASS

echo "1/5  MariaDB starten ..."
(cd "$ROOT/docker" && docker compose up -d)
for _ in $(seq 1 30); do
  status="$(docker inspect -f '{{.State.Health.Status}}' wrkflw-mariadb 2>/dev/null || echo '')"
  [ "$status" = "healthy" ] && break
  sleep 2
done

echo "2/5  Backend-Abhaengigkeiten sicherstellen ..."
[ -d "$ROOT/backend/vendor" ] || (cd "$ROOT/backend" && composer install --no-interaction)

echo "3/5  Onboarding-Definition seeden ..."
(cd "$ROOT/backend" && php bin/seed-definition.php examples/onboarding.json)

echo "4/5  REST-API starten (http://127.0.0.1:${API_PORT}) ..."
(cd "$ROOT/backend" && php -S "127.0.0.1:${API_PORT}" -t api api/index.php) &
API_PID=$!

echo "5/5  Frontend vorbereiten und starten ..."
cd "$ROOT/frontend"
[ -d node_modules ] || npm ci
npx ng build workflow-client
echo ""
echo "Demo läuft: http://localhost:4200  (API: http://127.0.0.1:${API_PORT})"
echo "Tab 'Runner' spielt das Onboarding, Tab 'Editor' verwaltet Definitionen."
npx ng serve demo --proxy-config projects/demo/proxy.conf.json
