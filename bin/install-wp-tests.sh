#!/usr/bin/env bash
set -e

# Usage: bash install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]
DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4-127.0.0.1}
# Default to the latest *stable* release (resolved below). Pulling trunk/master
# broke Test_Class_Activity (comment-date / featured-scope) against unreleased
# core. Pass an explicit x.y / x.y.z as arg 5 to pin a specific version.
WP_VERSION=${5-latest}

# Pathing - Use /tmp for CI to ensure speed via RAM disk if available
WP_TESTS_DIR=${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR:-/tmp/wordpress}

download_if_missing() {
    if [ ! -d "$2" ]; then
        mkdir -p "$2"
        echo "Downloading $1..."
        curl -sL "$1" | tar -xzp --strip-components=1 -C "$2"
    fi
}

# Resolve "latest" to the current stable release so we never pull dev/trunk.
# The wp.org API always returns the newest stable version (e.g. "7.0", "6.9.4").
if [[ "$WP_VERSION" == "latest" ]]; then
    WP_VERSION=$(curl -s "https://api.wordpress.org/core/version-check/1.7/" | grep -o '"current":"[0-9.]*"' | head -1 | grep -o '[0-9.]*$')
    [[ -z "$WP_VERSION" ]] && WP_VERSION="7.0"
fi

# Tag schemes differ between the two repos:
#   WordPress/WordPress      uses the wp.org form  (7.0, 6.9, 6.9.1)
#   WordPress/wordpress-develop always uses 3-part (7.0.0, 6.9.0, 6.9.4)
# Normalise a 2-part x.y to x.y.0 for the develop test suite.
DEVELOP_VERSION="$WP_VERSION"
[[ "$DEVELOP_VERSION" =~ ^[0-9]+\.[0-9]+$ ]] && DEVELOP_VERSION="${DEVELOP_VERSION}.0"

# 1. Download Core & Test Suite by release tag (stable, never trunk).
download_if_missing "https://github.com/WordPress/WordPress/archive/refs/tags/${WP_VERSION}.tar.gz" "$WP_CORE_DIR"
download_if_missing "https://github.com/WordPress/wordpress-develop/archive/refs/tags/${DEVELOP_VERSION}.tar.gz" "$WP_TESTS_DIR"

# 2. Configure wp-tests-config.php
if [ ! -f "$WP_TESTS_DIR/wp-tests-config.php" ]; then
    cp "$WP_TESTS_DIR/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"
    sed -i "s|dirname( __FILE__ ) . '/src/'|'$WP_CORE_DIR/'|g" "$WP_TESTS_DIR/wp-tests-config.php"
    sed -i "s/youremptytestdbnamehere/$DB_NAME/g" "$WP_TESTS_DIR/wp-tests-config.php"
    sed -i "s/yourusernamehere/$DB_USER/g" "$WP_TESTS_DIR/wp-tests-config.php"
    sed -i "s/yourpasswordhere/$DB_PASS/g" "$WP_TESTS_DIR/wp-tests-config.php"
    sed -i "s|localhost|${DB_HOST}|g" "$WP_TESTS_DIR/wp-tests-config.php"
fi

# 3. Robust Host/Port Parsing
# If DB_HOST contains a colon, split it. Otherwise, use default port 3306.
if [[ "$DB_HOST" == *":"* ]]; then
    HOST=${DB_HOST%:*}
    PORT=${DB_HOST#*:}
else
    HOST=$DB_HOST
    PORT=3306
fi

# Use explicit flags to prevent mysqladmin from misinterpreting the IP
EXTRA_FLAGS="--host=$HOST --port=$PORT --protocol=tcp"

# 4. Refuse to touch a database this script did not create itself.
#
# `mysqladmin drop` below is unconditional: given the wrong name (a real
# project's DB_NAME, say, copied in by habit or by an agent that did not know
# better) it silently destroys that database. A marker table left by a prior
# run is the only thing that makes a same-named database "ours" to reset;
# anything else -- including a database that merely happens to exist under
# this name -- is refused rather than assumed disposable.
MARKER_TABLE="_install_wp_tests_marker"

db_is_ours_or_absent() {
    local table_count marker_count

    # A nonexistent schema and an existing-but-empty one (e.g. just CREATE
    # DATABASE'd by hand, per the grant fallback below) both read back 0 tables
    # here -- no separate existence check needed, and both are safe to adopt.
    table_count=$(mysql -u"$DB_USER" -p"$DB_PASS" $EXTRA_FLAGS -N -e \
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$DB_NAME';" 2>/dev/null) || return 1
    [[ "$table_count" == "0" ]] && return 0

    marker_count=$(mysql -u"$DB_USER" -p"$DB_PASS" $EXTRA_FLAGS -N -e \
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$DB_NAME' AND table_name = '$MARKER_TABLE';" 2>/dev/null) || return 1
    [[ "$marker_count" == "1" ]]
}

if ! db_is_ours_or_absent; then
    echo "Refusing to touch database '$DB_NAME' at $HOST:$PORT: it already exists and carries no $MARKER_TABLE marker, so this script did not create it." >&2
    echo "Pass a dedicated, disposable test-database name -- never a project's real DB_NAME." >&2
    exit 1
fi

echo "Refreshing Database at $HOST:$PORT..."
mysqladmin drop "$DB_NAME" -f -u"$DB_USER" -p"$DB_PASS" $EXTRA_FLAGS 2>/dev/null || true
mysqladmin create "$DB_NAME" -u"$DB_USER" -p"$DB_PASS" $EXTRA_FLAGS
mysql -u"$DB_USER" -p"$DB_PASS" $EXTRA_FLAGS "$DB_NAME" -e "CREATE TABLE $MARKER_TABLE (created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);"

echo "Installation complete."
