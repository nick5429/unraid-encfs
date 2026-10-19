#!/bin/bash
# Shared helpers for building Slackware-style .txz packages without makepkg.
#
# The archive layout mirrors what Slackware's makepkg produces:
#   - first entry is "./"
#   - every other entry has no leading "./"
#   - entries are sorted with LC_COLLATE=C
#   - owner/group root:root
# A fixed mtime and sorted entries make the output reproducible for a given tree.

# make_txz <staging_dir> <output_file> [mtime]
make_txz() {
    local staging="$1"
    local output="$2"
    local mtime="${3:-2026-01-01 00:00:00 UTC}"

    if [ ! -f "$staging/install/slack-desc" ]; then
        echo "make_txz: $staging/install/slack-desc is missing" >&2
        return 1
    fi

    local listing
    listing="$(mktemp)"

    # Build the entry list: "./" first, then everything else without "./".
    (
        cd "$staging" || exit 1
        find ./ | LC_COLLATE=C sort | sed '2,$s,^\./,,'
    ) > "$listing"

    tar -C "$staging" \
        --no-recursion \
        --owner=root --group=root --numeric-owner \
        --mtime="$mtime" \
        -T "$listing" \
        -cf - \
        | xz -9 -T1 -c > "$output"

    rm -f "$listing"
}

# write_slack_desc <file> <pkgname> <line1> [line2 ...]
# Writes an 11-line slack-desc, padding with empty "pkgname:" lines.
write_slack_desc() {
    local file="$1"
    local pkg="$2"
    shift 2

    {
        echo "# HOW TO EDIT THIS FILE:"
        echo "# The \"handy ruler\" below makes it easier to edit a package description."
        echo "# Line up the first '|' above the ':' following the base package name, and"
        echo "# the '|' on the right side marks the last column you can put a character in."
        echo ""
        local pad
        pad="$(printf '%*s' "${#pkg}" '')"
        echo "${pad}|-----handy-ruler------------------------------------------------------|"

        local count=0
        local line
        for line in "$@"; do
            if [ -n "$line" ]; then
                echo "$pkg: $line"
            else
                echo "$pkg:"
            fi
            count=$((count + 1))
        done
        while [ "$count" -lt 11 ]; do
            echo "$pkg:"
            count=$((count + 1))
        done
    } > "$file"
}
