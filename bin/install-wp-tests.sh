#!/usr/bin/env bash
#
# Prepares a disposable WordPress test environment for PHPUnit.
#
#   bin/install-wp-tests.sh [db-name] [db-user] [db-pass] [db-host] [wp-version|core-dir] [skip-db-create]
#
# Defaults: openbb_tests root "" localhost latest false
#
# - Downloads WordPress core into WP_CORE_DIR (default /tmp/wordpress) unless the
#   fifth argument is an existing directory, in which case that installation is used.
# - Creates the database unless skip-db-create is "true".
# - Writes tests/wp-tests-config.php.
#
# The test library itself comes from Composer (vendor/wp-phpunit). Run `composer install` first.
# THE TEST DATABASE IS WIPED ON EVERY TEST RUN.

set -euo pipefail

DB_NAME=${1:-openbb_tests}
DB_USER=${2:-root}
DB_PASS=${3:-}
DB_HOST=${4:-localhost}
WP_VERSION=${5:-latest}
SKIP_DB_CREATE=${6:-false}

PLUGIN_DIR=$(cd "$(dirname "$0")/.." && pwd)
TMPDIR=${TMPDIR:-/tmp}
TMPDIR=${TMPDIR%/}
WP_CORE_DIR=${WP_CORE_DIR:-$TMPDIR/wordpress}

download() {
	if command -v curl >/dev/null; then
		curl -fsSL "$1" -o "$2"
	else
		wget -nv -O "$2" "$1"
	fi
}

install_core() {
	if [ -d "$WP_VERSION" ] && [ -f "$WP_VERSION/wp-settings.php" ]; then
		WP_CORE_DIR=$(cd "$WP_VERSION" && pwd)
		echo "Using existing WordPress at $WP_CORE_DIR"
		return
	fi
	if [ -f "$WP_CORE_DIR/wp-settings.php" ]; then
		echo "WordPress already present at $WP_CORE_DIR"
		return
	fi

	mkdir -p "$WP_CORE_DIR"
	if [ "$WP_VERSION" = "latest" ]; then
		archive="https://wordpress.org/latest.tar.gz"
	else
		archive="https://wordpress.org/wordpress-$WP_VERSION.tar.gz"
	fi
	echo "Downloading WordPress ($WP_VERSION) to $WP_CORE_DIR"
	download "$archive" "$TMPDIR/wordpress.tar.gz"
	tar --strip-components=1 -zxmf "$TMPDIR/wordpress.tar.gz" -C "$WP_CORE_DIR"
	rm -f "$TMPDIR/wordpress.tar.gz"
}

create_db() {
	if [ "$SKIP_DB_CREATE" = "true" ]; then
		return
	fi
	local host=${DB_HOST%%:*}
	local port=${DB_HOST#*:}
	local args=(--user="$DB_USER" --host="$host")
	[ -n "$DB_PASS" ] && args+=(--password="$DB_PASS")
	[ "$port" != "$DB_HOST" ] && args+=(--port="$port" --protocol=tcp)
	echo "Creating database $DB_NAME"
	mysql "${args[@]}" -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`"
}

write_config() {
	local config="$PLUGIN_DIR/tests/wp-tests-config.php"
	sed \
		-e "s#dirname( __DIR__, 4 )#'${WP_CORE_DIR//#/\\#}'#" \
		-e "s#?: 'openbb_tests'#?: '$DB_NAME'#" \
		-e "s#?: 'root'#?: '$DB_USER'#" \
		-e "s#?: '' );#?: '$DB_PASS' );#" \
		-e "s#?: 'localhost'#?: '$DB_HOST'#" \
		"$PLUGIN_DIR/tests/wp-tests-config-sample.php" > "$config"
	echo "Wrote $config"
}

install_core
create_db
write_config

echo
echo "Done. Run the tests with: composer test"
