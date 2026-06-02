#!/usr/bin/env bash
# Installs the WordPress test suite + a test database.
# Usage: bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]
set -euo pipefail

DB_NAME=${1-wordpress_test}
DB_USER=${2-root}
DB_PASS=${3-}
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}

WP_TESTS_DIR=${WP_TESTS_DIR-/tmp/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-/tmp/wordpress}

download() {
	if command -v curl >/dev/null 2>&1; then
		curl -s "$1" >"$2"
	else
		wget -nv -O "$2" "$1"
	fi
}

if [[ "$WP_VERSION" == "latest" ]]; then
	WP_TESTS_TAG="trunk"
	WP_VERSION=$(download https://api.wordpress.org/core/version-check/1.7/ - 2>/dev/null \
		| grep -o '"version":"[^"]*"' | head -1 | sed 's/.*":"//;s/"//' || echo "trunk")
fi
WP_TESTS_TAG=${WP_TESTS_TAG-"tags/${WP_VERSION}"}

install_wp() {
	mkdir -p "$WP_CORE_DIR"
	download "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" /tmp/wordpress.tar.gz || \
		download "https://wordpress.org/nightly-builds/wordpress-latest.zip" /tmp/wordpress.tar.gz
	tar --strip-components=1 -zxmf /tmp/wordpress.tar.gz -C "$WP_CORE_DIR"
}

install_test_suite() {
	mkdir -p "$WP_TESTS_DIR"
	svn export --quiet --force "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/includes/" "$WP_TESTS_DIR/includes" || true
	svn export --quiet --force "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/data/" "$WP_TESTS_DIR/data" || true

	if [[ ! -f "$WP_TESTS_DIR/wp-tests-config.php" ]]; then
		download "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"
		sed -i "s:dirname( __FILE__ ) . '/src/':'${WP_CORE_DIR}/':" "$WP_TESTS_DIR/wp-tests-config.php"
		sed -i "s/youremptytestdbnamehere/${DB_NAME}/" "$WP_TESTS_DIR/wp-tests-config.php"
		sed -i "s/yourusernamehere/${DB_USER}/" "$WP_TESTS_DIR/wp-tests-config.php"
		sed -i "s/yourpasswordhere/${DB_PASS}/" "$WP_TESTS_DIR/wp-tests-config.php"
		sed -i "s|localhost|${DB_HOST}|" "$WP_TESTS_DIR/wp-tests-config.php"
	fi
}

create_db() {
	mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" --protocol=tcp 2>/dev/null || true
}

install_wp
install_test_suite
create_db
echo "WP test suite ready in ${WP_TESTS_DIR} (core: ${WP_CORE_DIR})."
