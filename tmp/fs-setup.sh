#!/bin/bash
# FreeScout auf pst.engelgroup.us (Konto engelgroup) vorbereiten
set -e
U=engelgroup
D=pst.engelgroup.us
DIR=/home/$U/$D
PHP=/opt/cpanel/ea-php83/root/usr/bin/php

echo "== Subdomain"
if ! grep -q "^$D:" /etc/userdomains; then
  uapi --user=$U SubDomain addsubdomain domain=pst rootdomain=engelgroup.us dir=$D/public | grep -E "status|errors" | head -3
fi

echo "== PHP 8.3 fuer $D"
whmapi1 php_set_vhost_versions version=ea-php83 vhost-0=$D | grep -E "result|reason" | head -2

echo "== FreeScout laden"
if [ ! -f "$DIR/artisan" ]; then
  T=$(mktemp -d)
  curl -fsSL -o $T/fs.zip https://github.com/freescout-help-desk/freescout/archive/refs/heads/dist.zip
  unzip -q $T/fs.zip -d $T
  cp -a $T/freescout-dist/. $DIR/
  rm -rf $T
fi
chown -R $U:$U $DIR
find $DIR -type d -exec chmod 755 {} \;
ls $DIR | head -20

echo "== Datenbank"
DB=${U}_fs
if [ ! -f /root/.fs_dbpass ]; then
  DBP=$(openssl rand -base64 48 | tr -dc A-Za-z0-9 | head -c 28)
  umask 077; echo "$DBP" > /root/.fs_dbpass
  uapi --user=$U Mysql create_database name=$DB | grep -E "status"
  uapi --user=$U Mysql create_user name=$DB password="$DBP" | grep -E "status"
  uapi --user=$U Mysql set_privileges_on_database user=$DB database=$DB privileges="ALL PRIVILEGES" | grep -E "status"
fi

echo "== Anforderungen"
cd $DIR && sudo -u $U $PHP artisan freescout:check-requirements 2>&1 | tail -25
