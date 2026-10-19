#!/bin/bash
# Integration tests for the plugin core and CLI, using real FUSE mounts.
#
# Requirements: root, /dev/fuse, php-cli, the encfs binaries (ENCFS_BIN /
# ENCFSCTL_BIN, default /usr/local/bin). Runs entirely under /mnt/encfs-plugin-test
# and a temporary config directory; nothing of an Unraid install is touched.
#
# Usage: sudo tests/integration.sh
set -u

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TEST_ROOT=/mnt/encfs-plugin-test
WORK="$(mktemp -d)"

export ENCFS_PLUGIN_ROOT="$REPO/src/usr/local/emhttp/plugins/encfs"
export ENCFS_PLUGIN_CFG_DIR="$WORK/cfg"
export ENCFS_PLUGIN_LOCK="$WORK/lock"
MGR=("php" "$REPO/src/usr/local/sbin/encfs-mgr")
PHP_LIB="$ENCFS_PLUGIN_ROOT/include/Encfs.php"

PASS=0
FAIL=0

ok()   { PASS=$((PASS + 1)); echo "  ok   $1"; }
bad()  { FAIL=$((FAIL + 1)); echo "  FAIL $1"; }

check() {
    # check "description" command...
    local desc="$1"
    shift
    if "$@" >/dev/null 2>&1; then ok "$desc"; else bad "$desc"; fi
}

check_fails() {
    local desc="$1"
    shift
    if "$@" >/dev/null 2>&1; then bad "$desc"; else ok "$desc"; fi
}

is_mounted() {
    grep -q " $1 .* - fuse.encfs " /proc/self/mountinfo
}

php_call() {
    php -r "require '$PHP_LIB'; $1"
}

cleanup() {
    local mp
    awk '$5 ~ "^/mnt/encfs-plugin-test" {print $5}' /proc/self/mountinfo | while read -r mp; do
        umount -l "$(printf '%b' "$mp")" 2>/dev/null
    done
    pkill -f "^/usr/local/bin/encfs .*encfs-plugin-test" 2>/dev/null
    rm -rf "$TEST_ROOT" "$WORK"
}
trap cleanup EXIT

if [ "$(id -u)" -ne 0 ] || [ ! -e /dev/fuse ]; then
    echo "Needs root and /dev/fuse" >&2
    exit 2
fi

cleanup
mkdir -p "$TEST_ROOT/enc" "$TEST_ROOT/dec" "$WORK/cfg"
ENC="$TEST_ROOT/enc/vol"
DEC="$TEST_ROOT/dec/vol"
PW="correct horse battery"

echo "Configuration"
check "add volume" php_call "Encfs::saveVolume('vol', ['ENCDIR'=>'$ENC','MOUNTPOINT'=>'$DEC']);"
check_fails "reject path outside /mnt" php_call "Encfs::saveVolume('x', ['ENCDIR'=>'/tmp/a/b','MOUNTPOINT'=>'$TEST_ROOT/dec/x']);"
check_fails "reject '..' in path" php_call "Encfs::saveVolume('x', ['ENCDIR'=>'$TEST_ROOT/enc/../x','MOUNTPOINT'=>'$TEST_ROOT/dec/x']);"
check_fails "reject nested encdir/mountpoint" php_call "Encfs::saveVolume('x', ['ENCDIR'=>'$TEST_ROOT/dec/x','MOUNTPOINT'=>'$TEST_ROOT/dec/x/y']);"
check_fails "reject overlapping mount point" php_call "Encfs::saveVolume('x', ['ENCDIR'=>'$TEST_ROOT/enc/x','MOUNTPOINT'=>'$TEST_ROOT/dec']);"
check_fails "reject bad name" php_call "Encfs::saveVolume('-bad', ['ENCDIR'=>'$TEST_ROOT/enc/x','MOUNTPOINT'=>'$TEST_ROOT/dec/x']);"

echo "Refuse to mount without a config (encfs would create a new volume)"
mkdir -p "$ENC"
echo "$PW" | "${MGR[@]}" mount vol --stdin >/dev/null 2>&1
if [ ! -e "$ENC/.encfs6.xml" ] && ! is_mounted "$DEC"; then ok "no config created"; else bad "no config created"; fi

echo "Initialize"
check "initialize standard volume" php_call "Encfs::initialize('vol', '$PW', 'standard');"
check "mounted after initialize" is_mounted "$DEC"
check "config backed up to flash dir" test -f "$WORK/cfg/backup/vol.encfs6.xml"
echo "plaintext" > "$DEC/file.txt"
check "write through mount" test -f "$DEC/file.txt"
check "ciphertext differs" bash -c "! grep -rq plaintext '$ENC'"
check "unmount" "${MGR[@]}" unmount vol
check "mount point locked (mode 000)" test "$(stat -c %a "$DEC")" = "0"

echo "Mount"
check_fails "wrong password rejected" bash -c "echo wrong | ${MGR[*]} mount vol --stdin"
check_fails "not mounted after wrong password" is_mounted "$DEC"
check "right password mounts" bash -c "echo '$PW' | ${MGR[*]} mount vol --stdin"
check "file readable" grep -q plaintext "$DEC/file.txt"
check_fails "double mount refused" bash -c "echo '$PW' | ${MGR[*]} mount vol --stdin"
PID="$(pgrep -f "^/usr/local/bin/encfs .* $DEC" | head -n1)"
check "password not on encfs command line" bash -c "! tr '\0' ' ' < /proc/$PID/cmdline | grep -q '$PW'"
check "encfs holds only std fds and /dev/fuse" bash -c "[ \$(ls /proc/$PID/fd | wc -l) -le 4 ]"

echo "Busy and forced unmount"
(cd "$DEC" && sleep 60) &
HOLDER=$!
sleep 0.5
check_fails "busy unmount refused" "${MGR[@]}" unmount vol
check "still mounted" is_mounted "$DEC"
check "force unmount" "${MGR[@]}" unmount vol --force
check_fails "gone after force" is_mounted "$DEC"
kill "$HOLDER" 2>/dev/null

echo "Stale mount"
echo "$PW" | "${MGR[@]}" mount vol --stdin >/dev/null 2>&1
pkill -9 -f "^/usr/local/bin/encfs .* $DEC"
sleep 0.3
check "stale detected" bash -c "${MGR[*]} list | grep -q STALE"
check_fails "mount refused on stale" bash -c "echo '$PW' | ${MGR[*]} mount vol --stdin"
check "force cleans stale" "${MGR[@]}" unmount vol --force

echo "Read-only, spaces, password file auto-mount"
mkdir -p "$TEST_ROOT/dec/with space"
printf '%s\n' "$PW" > "$WORK/key"
chmod 600 "$WORK/key"
check "edit volume" php_call "\$v = Encfs::getVolume('vol'); \$v['MOUNTPOINT']='$TEST_ROOT/dec/with space/vol'; \$v['READONLY']='yes'; \$v['AUTOMOUNT']='keyfile'; \$v['KEYFILE']='$WORK/key'; Encfs::saveVolume('vol', \$v, 'vol');"
check "automount" "${MGR[@]}" automount
check "mounted read-only at path with space" bash -c "${MGR[*]} list | grep -q 'mounted (read-only)'"
check_fails "write refused on read-only" touch "$TEST_ROOT/dec/with space/vol/new"

echo "Mount point behind a symlinked share (Unraid exclusive shares)"
mkdir -p "$TEST_ROOT/pool/exclusive"
ln -s "$TEST_ROOT/pool/exclusive" "$TEST_ROOT/dec/exclusive"
check "point volume at symlinked share" php_call "\$v = Encfs::getVolume('vol'); \$v['MOUNTPOINT']='$TEST_ROOT/dec/exclusive/vol'; \$v['READONLY']='no'; \$v['AUTOMOUNT']='no'; Encfs::unmount('vol', false, 't'); Encfs::saveVolume('vol', \$v, 'vol');"
check "mount via symlinked path" bash -c "echo '$PW' | ${MGR[*]} mount vol --stdin"
check "kernel lists the resolved path" is_mounted "$TEST_ROOT/pool/exclusive/vol"
check "reported as mounted with pid" bash -c "${MGR[*]} list | grep -q 'mounted, pid'"
check "unmount via symlinked path" "${MGR[@]}" unmount vol
check_fails "gone" is_mounted "$TEST_ROOT/pool/exclusive/vol"
echo "$PW" | "${MGR[@]}" mount vol --stdin >/dev/null 2>&1

echo "Array stop"
mkdir -p "$TEST_ROOT/dec/manual"
echo "$PW" | /usr/local/bin/encfs -S --public "$ENC" "$TEST_ROOT/dec/manual" >/dev/null 2>&1
check "manual mount present" is_mounted "$TEST_ROOT/dec/manual"
check "unmount-all" "${MGR[@]}" unmount-all --force --reason test
check_fails "no encfs mounts left under test root" grep -q "encfs-plugin-test.* - fuse.encfs" /proc/self/mountinfo

echo "Legacy migration"
rm -f "$WORK/cfg/volumes.cfg"
printf 'SERVICE="enable"\nENCDIR0="%s"\nDECDIR0="%s/dec/legacy"\n' "$ENC" "$TEST_ROOT" > "$WORK/cfg/encfs.cfg"
check "migrate" bash -c "${MGR[*]} migrate-legacy | grep -q \"as 'legacy'\""
check "legacy config renamed" test -f "$WORK/cfg/encfs.cfg.legacy"

echo ""
echo "passed: $PASS  failed: $FAIL"
[ "$FAIL" -eq 0 ]
