#!/bin/sh
# Builds and (re)starts the survey site on the VPS, and adds it to the shared Caddy once.
# Run from the folder holding app/ and deploy/:  sh deploy/deploy.sh
set -eu
cd "$(dirname "$0")/.."
DOMAIN="${SURVEY_DOMAIN:-survey.azoclinic.duckdns.org}"
CADDYFILE="${CADDYFILE:-/home/ubuntu/headscale/Caddyfile}"
CONTAINER="${CADDY_CONTAINER:-caddy}"

# A copy of the data before every update (last 20 kept).
if sudo docker ps --format '{{.Names}}' | grep -qx survey-web; then
    mkdir -p "$HOME/survey-backups"
    sudo docker exec survey-web python3 -c "import sqlite3;s=sqlite3.connect('/data/survey.sqlite');d=sqlite3.connect('/tmp/copy.sqlite');s.backup(d);d.close()" \
        && sudo docker cp survey-web:/tmp/copy.sqlite "$HOME/survey-backups/survey_$(date +%Y%m%d_%H%M%S).sqlite"
    ls -1t "$HOME"/survey-backups/survey_*.sqlite 2>/dev/null | tail -n +21 | xargs -r rm -f
fi

sudo docker compose -f deploy/docker-compose.yml up -d --build --remove-orphans
for i in $(seq 30); do sudo docker exec survey-web python3 -c "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8080/health')" 2>/dev/null && break; sleep 2; done

# The shared Caddyfile is only appended to, after a copy; the copy goes back if Caddy rejects it.
if ! grep -q "^$DOMAIN" "$CADDYFILE"; then
    inner=""
    for m in $(sudo docker inspect -f '{{range .Mounts}}{{.Source}}={{.Destination}} {{end}}' "$CONTAINER"); do
        src="${m%%=*}"; dst="${m#*=}"
        if [ "$src" = "$CADDYFILE" ]; then inner="$dst"; break; fi
        case "$CADDYFILE" in "$src"/*) inner="$dst${CADDYFILE#"$src"}";; esac
    done
    [ -n "$inner" ] || inner=/etc/caddy/Caddyfile
    backup="$CADDYFILE.bak-survey-$(date +%Y%m%d_%H%M%S)"
    sudo cp -p "$CADDYFILE" "$backup"
    printf '\n# Health-centre survey (branch survey of hakamiraqi/File-manager-)\n%s {\n\tencode gzip\n\treverse_proxy survey-web:8080\n}\n' "$DOMAIN" | sudo tee -a "$CADDYFILE" >/dev/null
    if sudo docker exec "$CONTAINER" caddy validate --config "$inner" --adapter caddyfile >/dev/null 2>&1 \
        && sudo docker exec "$CONTAINER" caddy reload --config "$inner" --adapter caddyfile; then
        echo "Caddy now serves https://$DOMAIN"
    else
        echo "Caddy rejected the new site; putting the old Caddyfile back." >&2
        sudo sh -c "cat '$backup' > '$CADDYFILE'"
        sudo docker exec "$CONTAINER" caddy reload --config "$inner" --adapter caddyfile || true
        exit 1
    fi
fi
sudo docker ps --filter name=survey-web --format '{{.Names}}  {{.Status}}'
