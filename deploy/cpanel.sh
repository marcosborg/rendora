#!/bin/bash
set -euo pipefail
# Executed by cPanel Deploy HEAD Commit, not by an interactive terminal.
cd "$(dirname "$0")/.."
for candidate in /opt/cpanel/ea-php85/root/usr/bin/php /opt/cpanel/ea-php84/root/usr/bin/php /opt/cpanel/ea-php83/root/usr/bin/php /opt/cpanel/ea-php82/root/usr/bin/php /usr/local/bin/php /usr/bin/php; do
  if [ -x "$candidate" ] && "$candidate" -r 'exit(PHP_VERSION_ID >= 80200 && extension_loaded("pdo_sqlite") && extension_loaded("mbstring") ? 0 : 1);'; then
    exec "$candidate" deploy/cpanel.php "$@"
  fi
done
echo 'Rendora: PHP 8.2+ com PDO SQLite e mbstring não encontrado. Ativa estas extensões no cPanel ou contacta o alojamento.' >&2
exit 1
