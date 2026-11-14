free -m | sed -n 1,2p; df -h / | tail -1
docker pull -q ghcr.io/euro-office/documentserver:v9.3.7 2>&1 | tail -1
docker run --rm --entrypoint sh ghcr.io/euro-office/documentserver:v9.3.7 -c '
ls /var/www; D=$(ls -d /var/www/*/documentserver | head -1); echo DS=$D
ls $D; ls $D/web-apps/apps/common/main/resources/img/header 2>/dev/null | head -30
ls $D/web-apps/apps/api/documents/ | head
echo "--- onlyoffice mentions in api.js:"; grep -o -i "onlyoffice[a-z.]*" $D/web-apps/apps/api/documents/api.js | sort | uniq -c | head
echo "--- branding gate:"; grep -o "canBranding[A-Za-z]*[^;]\{0,80\}" $D/web-apps/apps/documenteditor/main/app.js | head -8
echo "--- loader/logo files:"; find $D/web-apps/apps -maxdepth 6 \( -iname "*logo*" -o -iname "*loader*" \) | head -40
echo "--- product name strings:"; grep -o "Euro-Office[A-Za-z ]\{0,20\}" $D/web-apps/apps/documenteditor/main/app.js | sort | uniq -c | sort -rn | head -8
grep -o -i "ONLYOFFICE[A-Za-z ]\{0,20\}" $D/web-apps/apps/documenteditor/main/app.js | sort | uniq -c | sort -rn | head -8
'
