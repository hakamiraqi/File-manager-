docker ps -a --format '{{.Names}} {{.Image}} {{.Status}}' | grep -v filenest | head -5
docker ps -a -q --filter ancestor=ghcr.io/euro-office/documentserver:v9.3.7 | xargs -r docker rm -f >/dev/null
I=ghcr.io/euro-office/documentserver:v9.3.7
docker inspect $I --format 'ENTRYPOINT={{.Config.Entrypoint}} CMD={{.Config.Cmd}}'
run() { timeout 120 docker run --rm --entrypoint /bin/sh $I -c "$1" </dev/null 2>&1 | cut -c1-220; }
run 'ls /var/www; ls -d /var/www/*/documentserver; ls /var/www/*/documentserver'
run 'D=$(ls -d /var/www/*/documentserver); ls $D/web-apps/apps/api/documents; ls $D/web-apps/apps/common/main/resources/img/header | head -30'
run 'D=$(ls -d /var/www/*/documentserver); find $D/web-apps/apps -maxdepth 7 \( -iname "*logo*" -o -iname "*loader*" \) | head -40'
run 'D=$(ls -d /var/www/*/documentserver); f=$D/web-apps/apps/documenteditor/main/app.js; ls -la $f; grep -c "" $f; grep -o "canBranding[A-Za-z]*" $f | sort | uniq -c | head; grep -o -i "onlyoffice" $f | sort | uniq -c; grep -o "Euro-Office" $f | sort | uniq -c; grep -o "DocsAPI" $D/web-apps/apps/api/documents/api.js | sort | uniq -c'
