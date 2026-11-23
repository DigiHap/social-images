#!/bin/bash
# .env fuer FreeScout schreiben, Zugriff waehrend der Einrichtung auf die IP des Nutzers begrenzen
set -e
U=engelgroup
DIR=/home/$U/pst.engelgroup.us
PHP=/opt/cpanel/ea-php83/root/usr/bin/php
cd $DIR

# IP der aktuellen WHM-Sitzung (Nutzer)
IP=$(grep "cpsess9004261672" /usr/local/cpanel/logs/access_log | tail -1 | awk '{print $1}')
[ -n "$IP" ] || { echo "IP nicht gefunden"; exit 1; }
echo "Nutzer-IP: $IP"

if [ ! -f .env ]; then
  cp .env.example .env
  DBP=$(cat /root/.fs_dbpass)
  sed -i \
    -e "s|^APP_URL=.*|APP_URL=https://pst.engelgroup.us|" \
    -e "s|^DB_DATABASE=.*|DB_DATABASE=${U}_fs|" \
    -e "s|^DB_USERNAME=.*|DB_USERNAME=${U}_fs|" \
    -e "s|^DB_PASSWORD=.*|DB_PASSWORD=${DBP}|" .env
  grep -q '^APP_FORCE_HTTPS=' .env || echo 'APP_FORCE_HTTPS=true' >> .env
  chown $U:$U .env && chmod 600 .env
  sudo -u $U $PHP artisan key:generate --force | tail -1
fi

# Sperre: waehrend der Einrichtung nur die IP des Nutzers
cat > public/.htaccess.gate <<EOF
# EINRICHTUNGSSPERRE (wird nach Passkey-Schutz entfernt)
<RequireAny>
  Require ip $IP
</RequireAny>
EOF
if ! grep -q "EINRICHTUNGSSPERRE" public/.htaccess; then
  cat public/.htaccess.gate public/.htaccess > public/.htaccess.new && mv public/.htaccess.new public/.htaccess
fi
chown $U:$U public/.htaccess public/.htaccess.gate
head -5 public/.htaccess
grep -E "^(APP_URL|DB_DATABASE|DB_USERNAME|APP_FORCE_HTTPS)" .env
grep -c "^APP_KEY=base64" .env
