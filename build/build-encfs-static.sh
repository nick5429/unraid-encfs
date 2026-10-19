#!/bin/bash
# Build a fully static encfs/encfsctl and package it as a Slackware .txz.
#
# Intended build host: Ubuntu 24.04 x86_64 (GitHub "ubuntu-24.04" runner works).
# Statically links libfuse 2.9.x, OpenSSL 3.0.x, tinyxml2 (bundled) and glibc,
# so the binaries only need the kernel's /dev/fuse on the Unraid host.
#
# Usage: build/build-encfs-static.sh [output_dir]
set -euo pipefail

ENCFS_VERSION="1.9.5"
ENCFS_COMMIT="7fc3730f67888106ade57a66d3da10a9f825d199"   # tag v1.9.5
PKG_NAME="encfs-static"
PKG_BUILD="1"
PKG_ARCH="x86_64"

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_DIR="${1:-$ROOT_DIR/packages}"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

# shellcheck source=build/lib-pkg.sh
source "$ROOT_DIR/build/lib-pkg.sh"

install_build_deps() {
    if command -v apt-get >/dev/null 2>&1; then
        export DEBIAN_FRONTEND=noninteractive
        apt-get update -qq || true
        apt-get install -y -qq git cmake build-essential libfuse-dev libssl-dev xz-utils >/dev/null
    fi
}

fetch_source() {
    git clone -q https://github.com/vgough/encfs.git "$WORK_DIR/encfs"
    git -C "$WORK_DIR/encfs" checkout -q "$ENCFS_COMMIT"
}

compile() {
    local src="$WORK_DIR/encfs"
    local bld="$src/build"
    mkdir -p "$bld"

    (
        cd "$bld"
        cmake .. \
            -DCMAKE_BUILD_TYPE=Release \
            -DENABLE_NLS=OFF \
            -DBUILD_UNIT_TESTS=OFF \
            -DUSE_INTERNAL_TINYXML=ON \
            -DCMAKE_CXX_FLAGS="-include cstdint" \
            >/dev/null

        # Builds libencfs.a plus the bundled static tinyxml2/easyloggingpp libraries.
        make -j"$(nproc)" encfs >/dev/null

        # Compile the two executables' objects. CMake's own link step is dynamic
        # and is replaced by the static link below, so its failure is expected.
        make encfs-bin encfsctl >/dev/null 2>&1 || true

        local libs=(
            libencfs.a
            -lfuse -lssl -lcrypto
            vendor/github.com/leethomason/tinyxml2/libtinyxml2.a
            vendor/github.com/muflihun/easyloggingpp/libeasyloggingpp.a
            -latomic -ldl -pthread
        )

        # Warnings about dlopen/getaddrinfo in static binaries come from code
        # paths encfs never uses (fuse modules, OpenSSL BIO networking).
        c++ -static -O2 CMakeFiles/encfs-bin.dir/encfs/main.cpp.o     -o encfs    "${libs[@]}" 2>/dev/null
        c++ -static -O2 CMakeFiles/encfsctl.dir/encfs/encfsctl.cpp.o -o encfsctl "${libs[@]}" 2>/dev/null
        strip encfs encfsctl
    )
}

stage_package() {
    local src="$WORK_DIR/encfs"
    local stage="$WORK_DIR/stage"
    local docdir="$stage/usr/doc/$PKG_NAME-$ENCFS_VERSION"

    mkdir -p "$stage/usr/local/bin" "$stage/install" "$docdir"

    install -m 0755 "$src/build/encfs"    "$stage/usr/local/bin/encfs"
    install -m 0755 "$src/build/encfsctl" "$stage/usr/local/bin/encfsctl"

    # License texts for everything linked into the binaries.
    install -m 0644 "$src/COPYING"      "$docdir/COPYING.encfs"
    install -m 0644 "$src/COPYING.LGPL" "$docdir/COPYING.LGPL"
    install -m 0644 "$src/COPYING.GPL"  "$docdir/COPYING.GPL"
    install -m 0644 "$src/vendor/github.com/leethomason/tinyxml2/readme.md" "$docdir/README-LICENSE.tinyxml2"
    install -m 0644 "$src/vendor/github.com/muflihun/easyloggingpp/LICENCE"   "$docdir/LICENSE.easyloggingpp"
    install -m 0644 /usr/share/doc/libfuse-dev/copyright "$docdir/COPYRIGHT.libfuse" 2>/dev/null \
        || install -m 0644 /usr/share/doc/libfuse2t64/copyright "$docdir/COPYRIGHT.libfuse"
    install -m 0644 /usr/share/doc/libssl-dev/copyright "$docdir/COPYRIGHT.openssl"

    {
        echo "encfs $ENCFS_VERSION static build"
        echo "source:   https://github.com/vgough/encfs (commit $ENCFS_COMMIT)"
        echo "builder:  https://github.com/nick5429/unraid-encfs/blob/master/build/build-encfs-static.sh"
        echo "host:     $(. /etc/os-release && echo "$PRETTY_NAME")"
        echo "compiler: $(c++ --version | head -n1)"
        echo "glibc:    $(ldd --version | head -n1)"
        echo "libfuse:  $(dpkg-query -W -f='${Version}' libfuse-dev 2>/dev/null || echo unknown)"
        echo "openssl:  $(dpkg-query -W -f='${Version}' libssl-dev 2>/dev/null || echo unknown)"
        echo ""
        echo "LGPL relinking: object files can be regenerated from the pinned source"
        echo "with the build script above; all linked libraries are unmodified upstream."
    } > "$docdir/BUILD-INFO"

    write_slack_desc "$stage/install/slack-desc" "$PKG_NAME" \
        "$PKG_NAME (statically linked EncFS $ENCFS_VERSION)" \
        "" \
        "EncFS is a FUSE-based cryptographic filesystem that stores each file" \
        "encrypted in a backing directory. This build is fully static" \
        "(libfuse 2, OpenSSL, tinyxml2) so it runs on Unraid 7 without any" \
        "extra libraries. Installs encfs and encfsctl to /usr/local/bin." \
        "" \
        "Homepage: https://github.com/vgough/encfs"
}

main() {
    install_build_deps
    fetch_source
    compile

    "$WORK_DIR/encfs/build/encfs" --version
    file "$WORK_DIR/encfs/build/encfs" | grep -q "statically linked"

    stage_package
    mkdir -p "$OUT_DIR"
    local out="$OUT_DIR/$PKG_NAME-$ENCFS_VERSION-$PKG_ARCH-$PKG_BUILD.txz"
    make_txz "$WORK_DIR/stage" "$out"
    echo "Built $out"
    sha256sum "$out"
}

main "$@"
