#!/bin/bash
# Build the encfs-plugin package and generate encfs.plg.
#
# Usage: build/build-plugin.sh [VERSION]
#   VERSION defaults to today's date (YYYY.MM.DD).
#
# Expects packages/encfs-static-<ver>-x86_64-<build>.txz to exist already
# (build it with build/build-encfs-static.sh). Writes:
#   packages/encfs-plugin-VERSION-noarch-1.txz (committed; served from raw.githubusercontent.com)
#   encfs.plg (repository root)
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="$ROOT_DIR/packages"
SRC_DIR="$ROOT_DIR/src"
VERSION="${1:-$(date +%Y.%m.%d)}"

ENCFS_PKG="encfs-static-1.9.5-x86_64-1.txz"

PLUGIN_PKG="encfs-plugin-$VERSION-noarch-1.txz"
PLUGIN_DIR="usr/local/emhttp/plugins/encfs"

# shellcheck source=build/lib-pkg.sh
source "$ROOT_DIR/build/lib-pkg.sh"

if [ ! -f "$DIST_DIR/$ENCFS_PKG" ]; then
    echo "Missing $DIST_DIR/$ENCFS_PKG - run build/build-encfs-static.sh first" >&2
    exit 1
fi

check_sources() {
    local file
    while IFS= read -r file; do
        php -l "$file" >/dev/null
    done < <(find "$SRC_DIR" -name '*.php' -o -name 'encfs-mgr')
    if command -v node >/dev/null 2>&1; then
        node --check "$SRC_DIR/$PLUGIN_DIR/javascript/encfs.js"
    fi
}

stage() {
    local stage_dir="$1"
    cp -a "$SRC_DIR/." "$stage_dir/"

    # Strip CR line endings in case the tree was edited on Windows.
    find "$stage_dir" -type f \( -name '*.php' -o -name '*.page' -o -name '*.js' -o -name '*.css' \
        -o -name '*.md' -o -path '*/event/*' -o -path '*/scripts/*' -o -path '*/sbin/*' \) \
        -exec sed -i 's/\r$//' {} +

    find "$stage_dir" -type d -exec chmod 0755 {} +
    find "$stage_dir" -type f -exec chmod 0644 {} +
    chmod 0755 "$stage_dir/$PLUGIN_DIR"/event/* \
               "$stage_dir/$PLUGIN_DIR"/scripts/* \
               "$stage_dir"/usr/local/sbin/*

    mkdir -p "$stage_dir/install"
    write_slack_desc "$stage_dir/install/slack-desc" "encfs-plugin" \
        "encfs-plugin (EncFS volume manager for Unraid)" \
        "" \
        "webGUI page (Settings > User Utilities > EncFS), array start/stop" \
        "event handlers and the encfs-mgr command line tool for mounting" \
        "EncFS-encrypted directories. Requires the encfs-static package." \
        "" \
        "Homepage: https://github.com/nick5429/unraid-encfs"
}

changes_markdown() {
    # Escape the characters XML cares about.
    sed -e 's/&/\&amp;/g' -e 's/</\&lt;/g' -e 's/>/\&gt;/g' "$ROOT_DIR/plugin/CHANGES.md"
}

generate_plg() {
    local plugin_sha encfs_sha changes
    plugin_sha="$(sha256sum "$DIST_DIR/$PLUGIN_PKG" | cut -d' ' -f1)"
    encfs_sha="$(sha256sum "$DIST_DIR/$ENCFS_PKG" | cut -d' ' -f1)"
    changes="$(changes_markdown)"

    local template="$ROOT_DIR/plugin/encfs.plg.in"
    local output="$ROOT_DIR/encfs.plg"

    # shellcheck disable=SC2016  # the PHP code is meant to be literal
    VERSION="$VERSION" PLUGIN_PKG="$PLUGIN_PKG" PLUGIN_SHA="$plugin_sha" \
    ENCFS_PKG="$ENCFS_PKG" ENCFS_SHA="$encfs_sha" CHANGES="$changes" \
    php -r '
        $t = file_get_contents($argv[1]);
        $map = [
            "@VERSION@"       => getenv("VERSION"),
            "@PLUGIN_PKG@"    => getenv("PLUGIN_PKG"),
            "@PLUGIN_SHA256@" => getenv("PLUGIN_SHA"),
            "@ENCFS_PKG@"     => getenv("ENCFS_PKG"),
            "@ENCFS_SHA256@"  => getenv("ENCFS_SHA"),
            "@CHANGES@"       => getenv("CHANGES"),
        ];
        file_put_contents($argv[2], strtr($t, $map));
    ' "$template" "$output"

    if command -v xmllint >/dev/null 2>&1; then
        xmllint --noout --noent "$output"
    fi
    echo "Generated $output"
}

main() {
    check_sources

    WORK_DIR="$(mktemp -d)"
    trap 'rm -rf "$WORK_DIR"' EXIT

    stage "$WORK_DIR"
    mkdir -p "$DIST_DIR"
    rm -f "$DIST_DIR"/encfs-plugin-*.txz
    make_txz "$WORK_DIR" "$DIST_DIR/$PLUGIN_PKG"
    echo "Built $DIST_DIR/$PLUGIN_PKG"

    generate_plg
    (cd "$DIST_DIR" && sha256sum ./*.txz)
}

main "$@"
