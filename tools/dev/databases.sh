#!/usr/bin/env bash
#
# Two database engines on one machine, for tools/dev/check.sh.
#
# CI runs the integration suite against MySQL 8.0 and MariaDB 10.6 because
# they disagree — see check.sh's header for the three differences that have
# really bitten. check.sh refuses to pass on one engine, and this is how you
# give it two on a Debian or Ubuntu box.
#
# The awkward part: the mysql-server and mariadb-server packages conflict,
# so apt will not have both. The way round it is to install one properly and
# unpack the other's .deb without installing it. Neither reads /etc/mysql,
# because each would choke on the other's settings there, so both are started
# with --no-defaults and everything they need on the command line.
#
#   tools/dev/databases.sh setup    once: install, unpack, create the data
#   tools/dev/databases.sh start    after a reboot, or a container restart
#   tools/dev/databases.sh status   what is answering
#   tools/dev/databases.sh dsns     the two lines to export for check.sh
#
# Everything is overridable, which is how this is tested without disturbing a
# machine that already has one of these running, and how a machine that set
# them up by hand can adopt it without losing its data:
#
#   MT_DB_STATE  MT_MYSQL_DATA  MT_MYSQL_PORT
#   MT_MARIADB_ROOT  MT_MARIADB_DATA  MT_MARIADB_PORT  MT_MARIADB_SOCK
#
# It needs root, and it is for a throwaway development machine: the test
# user's password is "mt" and the server listens only on localhost. Do not
# point it at anything you care about.

set -eo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
STATE="${MT_DB_STATE:-/var/lib/mt-dev-db}"
MARIA_ROOT="${MT_MARIADB_ROOT:-$STATE/mariadb}"   # the unpacked .deb
MYSQL_DATA="${MT_MYSQL_DATA:-$STATE/mysql8-data}"
MARIA_DATA="${MT_MARIADB_DATA:-$STATE/mariadb-data}"
RUN=/var/run/mysqld
MYSQL_PORT="${MT_MYSQL_PORT:-3307}"
MARIA_PORT="${MT_MARIADB_PORT:-3306}"
MARIA_SOCK="${MT_MARIADB_SOCK:-$RUN/mariadb.sock}"

say() { printf '%s\n' "$*"; }

mysql_up()  { mysqladmin --protocol=TCP -h127.0.0.1 -P"$MYSQL_PORT" -umt -pmt ping >/dev/null 2>&1; }
maria_up()  { mysqladmin --protocol=socket -S "$MARIA_SOCK" -umt -pmt ping >/dev/null 2>&1; }

wait_for() {
  local what="$1" i
  for i in $(seq 1 40); do
    "$what" && return 0
    sleep 2
  done
  return 1
}

grant() {   # grant <connection args...>
  "$@" -e "CREATE DATABASE IF NOT EXISTS marine_test;
           CREATE DATABASE IF NOT EXISTS mt_test;
           CREATE USER IF NOT EXISTS 'mt'@'%' IDENTIFIED BY 'mt';
           CREATE USER IF NOT EXISTS 'mt'@'localhost' IDENTIFIED BY 'mt';
           GRANT ALL ON *.* TO 'mt'@'%'; GRANT ALL ON *.* TO 'mt'@'localhost';
           FLUSH PRIVILEGES;" >/dev/null
}

setup() {
  [ "$(id -u)" = 0 ] || { say "setup needs root."; exit 1; }
  mkdir -p "$STATE" "$RUN" /var/lib/mysql-files
  chown mysql:mysql "$RUN" /var/lib/mysql-files 2>/dev/null || true

  say "== MySQL 8 from apt"
  apt-get update -qq
  DEBIAN_FRONTEND=noninteractive apt-get install -y -qq mysql-server >/dev/null
  if [ ! -d "$MYSQL_DATA/mysql" ]; then
    mkdir -p "$MYSQL_DATA" && chown mysql:mysql "$MYSQL_DATA"
    # --no-defaults: /etc/mysql here belongs to whichever package landed last.
    /usr/sbin/mysqld --no-defaults --initialize-insecure --user=mysql \
      --datadir="$MYSQL_DATA" --secure-file-priv=/var/lib/mysql-files
  fi

  say "== MariaDB, unpacked rather than installed (the packages conflict)"
  if [ ! -x "$MARIA_ROOT/usr/sbin/mariadbd" ]; then
    local tmp
    tmp="$(mktemp -d)"
    ( cd "$tmp" && apt-get download -qq mariadb-server-core mariadb-common mariadb-client-core libmariadb3 >/dev/null )
    mkdir -p "$MARIA_ROOT"
    for deb in "$tmp"/*.deb; do dpkg-deb -x "$deb" "$MARIA_ROOT"; done
    rm -rf "$tmp"
    chmod -R a+rX "$MARIA_ROOT"
  fi
  if [ ! -d "$MARIA_DATA/mysql" ]; then
    mkdir -p "$MARIA_DATA" && chown mysql:mysql "$MARIA_DATA"
    "$MARIA_ROOT/usr/scripts/mariadb-install-db" --no-defaults --user=mysql \
      --datadir="$MARIA_DATA" --basedir="$MARIA_ROOT/usr" >/dev/null 2>&1 \
      || "$MARIA_ROOT/usr/bin/mariadb-install-db" --no-defaults --user=mysql \
         --datadir="$MARIA_DATA" --basedir="$MARIA_ROOT/usr" >/dev/null
  fi

  start
  say "== creating the test user on both"
  grant mysql --protocol=TCP -h127.0.0.1 -P"$MYSQL_PORT" -uroot
  grant mysql --protocol=socket -S "$MARIA_SOCK" -uroot
  say
  status
  say
  dsns
}

start() {
  # $STATE holds the logs these write, and start can be the first thing run
  # on a machine whose data directories were made by hand.
  mkdir -p "$STATE" "$RUN" && chown mysql:mysql "$RUN" 2>/dev/null || true
  if ! mysql_up; then
    setsid nohup /usr/sbin/mysqld --no-defaults --user=mysql --datadir="$MYSQL_DATA" \
      --port="$MYSQL_PORT" --socket="$RUN/mysqld8.sock" --mysqlx=0 \
      --secure-file-priv=/var/lib/mysql-files >"$STATE/mysql8.log" 2>&1 </dev/null &
    disown
  fi
  if ! maria_up; then
    setsid nohup "$MARIA_ROOT/usr/sbin/mariadbd" --no-defaults --user=mysql \
      --datadir="$MARIA_DATA" --port="$MARIA_PORT" --socket="$MARIA_SOCK" \
      --basedir="$MARIA_ROOT/usr" --lc-messages-dir="$MARIA_ROOT/usr/share/mariadb" \
      >"$STATE/mariadb.log" 2>&1 </dev/null &
    disown
  fi
  wait_for mysql_up || say "MySQL did not come up; see $STATE/mysql8.log"
  wait_for maria_up || say "MariaDB did not come up; see $STATE/mariadb.log"
}

status() {
  mysql_up && say "MySQL   8.0  up on 127.0.0.1:$MYSQL_PORT" || say "MySQL   8.0  DOWN"
  maria_up && say "MariaDB 10.x up on $MARIA_SOCK"           || say "MariaDB 10.x DOWN"
}

dsns() {
  say "export MT_MYSQL_DSN=\"host=127.0.0.1;port=$MYSQL_PORT;name=marine_test;user=mt;pass=mt\""
  say "export MT_MARIADB_DSN=\"socket=$MARIA_SOCK;name=mt_test;user=mt;pass=mt\""
}

case "${1:-}" in
  setup)  setup ;;
  start)  start; status ;;
  status) status ;;
  dsns)   dsns ;;
  *) sed -n '2,25p' "$HERE/databases.sh" | sed 's/^# \{0,1\}//'; exit 1 ;;
esac
