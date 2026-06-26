#!/bin/bash
# Wipe MariaDB data and re-run daloRADIUS DB/schema initialization using current .env values.
# WARNING: All RADIUS users, accounting records, and operator accounts will be deleted.

set -euo pipefail

cd "$(dirname "$0")"

if [[ "${1:-}" != "-y" && "${1:-}" != "--yes" ]]; then
    echo "This will permanently delete:"
    echo "  - data/mysql/          (MariaDB data)"
    echo "  - data/daloradius/.db_init_done"
    echo "  - data/daloradius/.init_done"
    echo ""
    echo "Then containers will be recreated with passwords from .env"
    echo ""
    read -r -p "Continue? [y/N] " answer
    if [[ ! "$answer" =~ ^[Yy]$ ]]; then
        echo "Aborted."
        exit 1
    fi
fi

docker compose down

rm -rf data/mysql
rm -f data/daloradius/.db_init_done data/daloradius/.init_done
mkdir -p data/mysql data/daloradius

docker compose up -d --build radius-web

echo ""
echo "Waiting for services to start..."
sleep 5
docker compose ps
echo ""
echo "Done. Web UI: http://localhost:$(grep -E '^WEB_PORT=' .env 2>/dev/null | cut -d= -f2 || echo 801)"
echo "Default operator login is in contrib/db/mariadb-daloradius.sql (usually admin / radius)."
