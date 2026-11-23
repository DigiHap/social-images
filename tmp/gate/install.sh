#!/bin/bash
set -e
U=engelgroup
G=/home/$U/pstgate
PUB=/home/$U/pst.engelgroup.us/public
SRC=https://raw.githubusercontent.com/DigiHap/social-images/6a8a7e4/tmp/gate
mkdir -p $G/data $G/lib
curl -fsSL $SRC/gate.php -o $G/gate.php
curl -fsSL $SRC/page.php -o $G/page.php
if [ ! -d $G/lib/src ]; then
  T=$(mktemp -d)
  curl -fsSL -o $T/w.zip https://github.com/lbuchs/WebAuthn/archive/refs/tags/v2.2.0.zip
  unzip -q $T/w.zip -d $T
  cp -a $T/WebAuthn-2.2.0/src $G/lib/
  rm -rf $T
fi
[ -f $G/data/secret ] || { umask 077; openssl rand -hex 32 > $G/data/secret; }
chown -R $U:$U $G
chmod 711 $G; chmod 700 $G/data; chmod 600 $G/data/secret
/opt/cpanel/ea-php83/root/usr/bin/php -l $G/gate.php
/opt/cpanel/ea-php83/root/usr/bin/php -l $G/page.php
ls $G/lib/src | head
touch $PUB/.user.ini
grep -q "pstgate/gate.php" $PUB/.user.ini || echo "auto_prepend_file = $G/gate.php" >> $PUB/.user.ini
chown $U:$U $PUB/.user.ini
cat $PUB/.user.ini
/scripts/restartsrv_apache_php_fpm >/dev/null 2>&1 && echo "php-fpm neu gestartet"
