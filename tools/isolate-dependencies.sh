#!/usr/bin/env bash
#
# Prefix the third-party dependencies of a built Dalfred package, then prove the
# result survives other modules' copies of them. Run by `make build-release`
# right after Composer installed both trees; see tools/scoper.inc.php for why.
#
#   tools/isolate-dependencies.sh <built module dir> <php-scoper.phar>
#
# <built module dir> holds composer.json, src/, vendor/ and dolibarr-mcp-server/
# (with its own composer.json, src/ and vendor/), all installed --no-dev.
#
# Trees and prefixes:
#   module   vendor/ + src/                 -> DalfredVendor\
#   runtime  dolibarr-mcp-server/           -> DalfredMcpVendor\
#
# Each scoped tree gets an authoritative classmap (our autoloader no longer
# answers for anybody else's classes, and nobody else's for ours), Composer
# "files" ids of its own, and a marker naming its prefix that the PHP code reads
# (lib/dalfred_runtime.php).

set -euo pipefail
shopt -s dotglob

MODULE_DIR=$(cd "$1" && pwd)
PHP_SCOPER=$2
TOOLS_DIR=$(cd "$(dirname "$0")" && pwd)
SOURCE_DIR=$(dirname "$TOOLS_DIR")
WORK=$(mktemp -d "${TMPDIR:-/tmp}/dalfred-scope.XXXXXX")
trap 'rm -rf "$WORK"' EXIT

MODULE_PREFIX=DalfredVendor
RUNTIME_PREFIX=DalfredMcpVendor

# scope <dir holding the tree> <prefix>: prefix in place, regenerate the autoloader
scope() {
	local tree=$1 prefix=$2 name
	name=$(basename "$tree")

	# Composer installs in parallel; sort installed.json so the generated
	# autoloaders do not depend on which download finished first.
	php "$TOOLS_DIR/normalize-vendor.php" "$tree"
	(cd "$tree" && composer dump-autoload --no-dev --optimize --no-interaction --quiet)

	mkdir -p "$WORK/in-$name"
	mv "$tree"/* "$WORK/in-$name/"
	(cd "$WORK/in-$name" && DALFRED_SCOPE_SRC="$WORK/in-$name" DALFRED_SCOPE_PREFIX="$prefix" \
		php "$PHP_SCOPER" add-prefix --config "$TOOLS_DIR/scoper.inc.php" \
		--output-dir "$WORK/out-$name" --no-interaction --quiet)
	rm -f "$WORK/out-$name/vendor/scoper-autoload.php"
	mv "$WORK/out-$name"/* "$tree"/

	# Every namespace declared under vendor/ must now carry the prefix, or be one
	# shared on purpose. Catches a too-broad exclusion (php-scoper once spared
	# NeuronAI\MCP\ because 'Mcp' was excluded), which would otherwise only show
	# as classes silently missing from the authoritative classmap.
	local leaked
	leaked=$(grep -rhoE --include='*.php' '^namespace [A-Za-z_][A-Za-z0-9_]*(\\[A-Za-z_][A-Za-z0-9_]*)*\s*[;{]' "$tree/vendor" \
		| sed -E 's/^namespace //; s/\s*[;{]$//' | sort -u \
		| grep -vE "^($prefix|Mcp|DolibarrMcp|Symfony\\\\Polyfill|Composer)(\\\\|$)" || true)
	if [ -n "$leaked" ]; then
		echo "ERROR: namespaces left unprefixed in $name/vendor:" >&2
		echo "$leaked" >&2
		exit 1
	fi

	(cd "$tree" && composer dump-autoload --no-dev --optimize --classmap-authoritative --no-interaction --quiet)
	php "$TOOLS_DIR/rehash-autoload-files.php" "$tree" "$prefix"
	printf '%s\n' "$prefix" > "$tree/vendor/dalfred-scope-prefix.txt"
}

# The unscoped copy the test reproduces the original fatal errors on.
echo "  keeping an unscoped copy for the before/after test..."
mkdir -p "$WORK/unscoped/dolibarr-mcp-server"
cp -r "$MODULE_DIR/src" "$MODULE_DIR/vendor" "$WORK/unscoped/"
cp -r "$MODULE_DIR/dolibarr-mcp-server/src" "$MODULE_DIR/dolibarr-mcp-server/vendor" "$WORK/unscoped/dolibarr-mcp-server/"

# The module tree: composer.json, src/ and vendor/ only. The PHP pages stay
# out: they live in the global namespace, which scoping would rewrite.
echo "  module tree -> $MODULE_PREFIX\\"
mkdir -p "$WORK/module"
mv "$MODULE_DIR/composer.json" "$MODULE_DIR/src" "$MODULE_DIR/vendor" "$WORK/module/"
[ -f "$MODULE_DIR/composer.lock" ] && mv "$MODULE_DIR/composer.lock" "$WORK/module/"
scope "$WORK/module" "$MODULE_PREFIX"
mv "$WORK/module"/* "$MODULE_DIR/"

echo "  runtime tree -> $RUNTIME_PREFIX\\"
scope "$MODULE_DIR/dolibarr-mcp-server" "$RUNTIME_PREFIX"
# emMCP reads this name when it falls back to Dalfred's runtime.
cp "$MODULE_DIR/dolibarr-mcp-server/vendor/dalfred-scope-prefix.txt" "$MODULE_DIR/dolibarr-mcp-server/vendor/emmcp-scope-prefix.txt"

php "$SOURCE_DIR/tests/runtime_isolation_test.php" "$MODULE_DIR" "$WORK/unscoped"
