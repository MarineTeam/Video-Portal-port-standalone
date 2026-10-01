#!/usr/bin/env bash
#
# Everything CI runs, before a push, in the order CI runs it.
#
# The one thing that matters here and is easy to get wrong: CI runs the
# integration suite against BOTH MySQL 8.0 and MariaDB 10.6, and the two
# disagree about things the suite can see. information_schema names its own
# columns in upper case on MySQL and lower case on MariaDB. MySQL has a real
# JSON type; MariaDB keeps a LONGTEXT with a json_valid() check beside it.
# MySQL does not round-trip its own SHOW CREATE TABLE and MariaDB does.
#
# Running one engine and assuming the other agrees is how six CI runs went
# red unnoticed. So this refuses to report success unless it has really run
# the integration suite on both, and says plainly which one it could not
# reach rather than quietly testing half of what it claims.
#
#   tools/dev/check.sh
#
# Each engine is found through environment variables, so point them at
# whatever you have:
#
#   MT_MYSQL_DSN="host=127.0.0.1;port=3307;name=marine_test;user=mt;pass=mt"
#   MT_MARIADB_DSN="socket=/var/run/mysqld/mysqld.sock;name=mt_test;user=mt;pass=mt"
#
# tools/dev/databases.sh puts both on one machine and prints those two lines:
#
#   sudo tools/dev/databases.sh setup     once
#   sudo tools/dev/databases.sh start     after a restart
#   eval "$(tools/dev/databases.sh dsns)"
#
# Set MT_ONE_ENGINE=1 to accept a single engine — for a quick loop, never
# before a push.

set -eo pipefail
cd "$(dirname "$0")/../.." || exit 1

phpunit() {
  if [ -x vendor/bin/phpunit ]; then vendor/bin/phpunit "$@";
  elif [ -f /tmp/phpunit.phar ]; then php /tmp/phpunit.phar "$@";
  else echo "no phpunit: composer install, or put phpunit.phar in /tmp" >&2; exit 1; fi
}

phpstan() {
  if [ -x vendor/bin/phpstan ]; then php -d memory_limit=1G vendor/bin/phpstan "$@";
  elif [ -f /tmp/phpstan.phar ]; then php -d memory_limit=1G /tmp/phpstan.phar "$@";
  else echo "no phpstan: composer install, or put phpstan.phar in /tmp" >&2; exit 1; fi
}

echo "== lint"
find app install bin public plugins themes tests tools -name '*.php' -not -path 'tests/fixtures/*' -print0 \
  | xargs -0 -n1 -P4 php -l > /dev/null
php tools/ci/check-banned.php > /dev/null
php tools/ci/check-templates.php > /dev/null
php tools/ci/check-docs.php > /dev/null
echo "   ok"

echo "== unit tests"
phpunit --testsuite unit | tail -1

echo "== static analysis"
phpstan analyse --no-progress | grep -E "OK|ERROR"

echo "== browser modules"
node --test tests/js/*.test.mjs 2>&1 | grep -E "^# (pass|fail)"

# -- the integration suite, once per engine ----------------------------------

ran=0

# A DSN here is "key=value;key=value" over host, port, socket, name, user, pass.
integration() {
  local label="$1" dsn="$2"
  [ -z "$dsn" ] && { echo "== integration on $label: no DSN set, skipped"; return 0; }

  local host='' port='' socket='' name='' user='' pass=''
  local pair key value
  while IFS= read -r pair; do
    [ -z "$pair" ] && continue
    key=${pair%%=*}
    value=${pair#*=}
    case "$key" in
      host) host=$value ;; port) port=$value ;; socket) socket=$value ;;
      name) name=$value ;; user) user=$value ;; pass) pass=$value ;;
    esac
  done <<< "${dsn//;/$'\n'}"

  local ping=(mysqladmin -u"$user" -p"$pass" ping)
  if [ -n "$socket" ]; then ping+=(--protocol=socket -S "$socket");
  else ping+=(--protocol=TCP -h "${host:-127.0.0.1}" -P "${port:-3306}"); fi
  if ! "${ping[@]}" > /dev/null 2>&1; then
    echo "== integration on $label: NOT RUNNING, skipped"
    return 0
  fi

  echo "== integration on $label"
  MT_TEST_DB_HOST="${host:-127.0.0.1}" MT_TEST_DB_PORT="${port:-3306}" \
  MT_TEST_DB_NAME="$name" MT_TEST_DB_USER="$user" MT_TEST_DB_PASS="$pass" \
    phpunit --testsuite integration | tail -1
  ran=$((ran + 1))
}

integration "MySQL"   "${MT_MYSQL_DSN:-}"
integration "MariaDB" "${MT_MARIADB_DSN:-}"

if [ "$ran" -lt 2 ] && [ -z "${MT_ONE_ENGINE:-}" ]; then
  echo
  echo "REFUSING TO PASS: the integration suite ran on $ran of 2 engines."
  echo "CI runs MySQL 8.0 and MariaDB 10.6 and they differ. Set MT_MYSQL_DSN"
  echo "and MT_MARIADB_DSN, or MT_ONE_ENGINE=1 if you know what you are losing."
  exit 1
fi

echo "== release zip"
php tools/release/build.php --out /tmp/mt-build.zip > /dev/null && echo "   ok"

echo
echo "ALL CHECKS PASSED (integration on $ran engine(s))"
