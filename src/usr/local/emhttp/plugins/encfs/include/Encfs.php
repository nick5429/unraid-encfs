<?php
/*
 * EncFS plugin for Unraid - core library.
 *
 * Shared by the web endpoint (include/exec.php), the CLI (/usr/local/sbin/encfs-mgr)
 * and the array event handlers. Everything that touches mounts, configuration or
 * passwords lives here so there is exactly one implementation of each rule.
 *
 * Password handling rules:
 *   - A password is only ever written to the stdin pipe of the encfs process
 *     (encfs --stdinpass). It never appears on a command line, in the environment,
 *     in a file written by this plugin, or in a log message.
 *   - encfs is started through scripts/exec-detached, which closes every inherited
 *     file descriptor above stderr. Without that, the daemonized encfs process keeps
 *     php-fpm's FastCGI socket open and the web request never completes.
 */

class EncfsError extends RuntimeException
{
}

class Encfs
{
    const PLUGIN = 'encfs';
    const SYSLOG_TAG = 'encfs-plugin';

    // Fields stored for each volume, with their defaults.
    const VOLUME_DEFAULTS = [
        'ENCDIR'          => '',
        'MOUNTPOINT'      => '',
        'CONFIG'          => '',
        'UID'             => '99',
        'GID'             => '100',
        'READONLY'        => 'no',
        'IDLE'            => '0',
        'LOCK_MOUNTPOINT' => 'yes',
        'AUTOMOUNT'       => 'no',
        'KEYFILE'         => '',
    ];

    // Legacy encfs config files, newest first (encfs 1.9 can read all of them).
    const CONFIG_NAMES = ['.encfs6.xml', '.encfs5', '.encfs4'];

    const MOUNT_TIMEOUT_SECONDS = 90;
    const ALLOWED_ROOT = '/mnt';

    /* ------------------------------------------------------------------ */
    /* Paths (overridable through the environment for the test suite)     */
    /* ------------------------------------------------------------------ */

    public static function cfgDir(): string
    {
        $dir = getenv('ENCFS_PLUGIN_CFG_DIR');
        return $dir ? $dir : '/boot/config/plugins/' . self::PLUGIN;
    }

    public static function volumesFile(): string
    {
        return self::cfgDir() . '/volumes.cfg';
    }

    public static function backupDir(): string
    {
        return self::cfgDir() . '/backup';
    }

    public static function legacyCfgFile(): string
    {
        return self::cfgDir() . '/encfs.cfg';
    }

    public static function pluginRoot(): string
    {
        $dir = getenv('ENCFS_PLUGIN_ROOT');
        return $dir ? $dir : '/usr/local/emhttp/plugins/' . self::PLUGIN;
    }

    public static function encfsBin(): string
    {
        $bin = getenv('ENCFS_BIN');
        return $bin ? $bin : '/usr/local/bin/encfs';
    }

    public static function encfsctlBin(): string
    {
        $bin = getenv('ENCFSCTL_BIN');
        return $bin ? $bin : '/usr/local/bin/encfsctl';
    }

    public static function varIniFile(): string
    {
        $file = getenv('ENCFS_PLUGIN_VAR_INI');
        return $file ? $file : '/var/local/emhttp/var.ini';
    }

    public static function lockFile(): string
    {
        $file = getenv('ENCFS_PLUGIN_LOCK');
        return $file ? $file : '/var/run/encfs-plugin.lock';
    }

    /* ------------------------------------------------------------------ */
    /* Logging and notifications                                          */
    /* ------------------------------------------------------------------ */

    public static function log(string $message, int $priority = LOG_INFO): void
    {
        openlog(self::SYSLOG_TAG, LOG_PID, LOG_USER);
        syslog($priority, $message);
        closelog();
    }

    public static function notify(string $subject, string $description, string $importance = 'warning'): void
    {
        $notify = '/usr/local/emhttp/webGui/scripts/notify';
        if (!is_executable($notify)) {
            return;
        }
        $argv = [$notify, '-e', 'EncFS', '-s', $subject, '-d', $description, '-i', $importance,
                 '-l', '/Settings/EncFS'];
        self::run($argv, null, 15);
    }

    /* ------------------------------------------------------------------ */
    /* Process execution                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * Run a command without a shell. Returns [exit_code, stdout, stderr].
     * $stdin, when given, is written to the process and the pipe is closed.
     */
    public static function run(array $argv, ?string $stdin = null, int $timeoutSeconds = 30,
                               array $extraEnv = [], bool $detachFds = false): array
    {
        $command = [];
        if ($detachFds) {
            $command[] = self::pluginRoot() . '/scripts/exec-detached';
        }
        $command[] = '/usr/bin/timeout';
        $command[] = '--kill-after=5';
        $command[] = (string)$timeoutSeconds;
        foreach ($argv as $arg) {
            $command[] = (string)$arg;
        }

        $env = [
            'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'HOME' => '/root',
            'LANG' => 'C',
        ];
        foreach ($extraEnv as $key => $value) {
            $env[$key] = $value;
        }

        $spec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $proc = proc_open($command, $spec, $pipes, '/', $env);
        if (!is_resource($proc)) {
            throw new EncfsError('Could not start ' . basename((string)$argv[0]));
        }

        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return [$code, (string)$stdout, (string)$stderr];
    }

    /* ------------------------------------------------------------------ */
    /* Locking                                                            */
    /* ------------------------------------------------------------------ */

    /** Run $fn while holding the plugin-wide lock. */
    public static function withLock(callable $fn, int $waitSeconds = 120)
    {
        $handle = fopen(self::lockFile(), 'c');
        if ($handle === false) {
            throw new EncfsError('Could not open lock file ' . self::lockFile());
        }

        $deadline = time() + $waitSeconds;
        while (!flock($handle, LOCK_EX | LOCK_NB)) {
            if (time() >= $deadline) {
                fclose($handle);
                throw new EncfsError('Another EncFS operation is still running. Try again shortly.');
            }
            usleep(200000);
        }

        try {
            return $fn();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Volume configuration                                               */
    /* ------------------------------------------------------------------ */

    /** @return array<string, array<string,string>> name => fields */
    public static function loadVolumes(): array
    {
        $file = self::volumesFile();
        if (!is_file($file)) {
            return [];
        }

        $parsed = parse_ini_file($file, true, INI_SCANNER_RAW);
        if ($parsed === false) {
            throw new EncfsError("Could not parse $file");
        }

        $volumes = [];
        foreach ($parsed as $name => $fields) {
            if (!is_array($fields) || !self::isValidName((string)$name)) {
                continue;
            }
            $volume = self::VOLUME_DEFAULTS;
            foreach (self::VOLUME_DEFAULTS as $key => $default) {
                if (isset($fields[$key])) {
                    $volume[$key] = (string)$fields[$key];
                }
            }
            $volumes[(string)$name] = $volume;
        }
        ksort($volumes, SORT_NATURAL | SORT_FLAG_CASE);
        return $volumes;
    }

    public static function saveVolumes(array $volumes): void
    {
        $lines = ["# EncFS plugin volumes - managed by the plugin, edit with care."];
        foreach ($volumes as $name => $volume) {
            $lines[] = '';
            $lines[] = '[' . $name . ']';
            foreach (self::VOLUME_DEFAULTS as $key => $default) {
                $value = $volume[$key] ?? $default;
                $lines[] = $key . '="' . $value . '"';
            }
        }
        $content = implode("\n", $lines) . "\n";

        $dir = self::cfgDir();
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
            throw new EncfsError("Could not create $dir");
        }

        $tmp = self::volumesFile() . '.tmp';
        if (file_put_contents($tmp, $content) === false || !rename($tmp, self::volumesFile())) {
            @unlink($tmp);
            throw new EncfsError('Could not write ' . self::volumesFile());
        }
    }

    public static function getVolume(string $name): array
    {
        $volumes = self::loadVolumes();
        if (!isset($volumes[$name])) {
            throw new EncfsError("Unknown volume '$name'");
        }
        return $volumes[$name];
    }

    public static function isValidName(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,31}$/', $name) === 1;
    }

    /**
     * Normalize an absolute path and reject anything ambiguous.
     * Returns '' for an empty optional value.
     */
    public static function normalizePath(string $path, string $label, bool $optional = false): string
    {
        $path = trim($path);
        if ($path === '') {
            if ($optional) {
                return '';
            }
            throw new EncfsError("$label is required");
        }
        if ($path[0] !== '/') {
            throw new EncfsError("$label must be an absolute path");
        }
        if (preg_match('/[\x00-\x1f\x7f"\\\\]/', $path)) {
            throw new EncfsError("$label contains characters that are not allowed (quotes, backslashes or control characters)");
        }

        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '') {
                continue;
            }
            if ($segment === '.' || $segment === '..') {
                throw new EncfsError("$label must not contain '.' or '..' segments");
            }
            $parts[] = $segment;
        }
        if (count($parts) === 0) {
            throw new EncfsError("$label must not be /");
        }
        return '/' . implode('/', $parts);
    }

    public static function isSameOrUnder(string $path, string $parent): bool
    {
        return $path === $parent || strpos($path, rtrim($parent, '/') . '/') === 0;
    }

    private static function requireUnderMnt(string $path, string $label): void
    {
        $segments = explode('/', trim($path, '/'));
        if ($segments[0] !== trim(self::ALLOWED_ROOT, '/') || count($segments) < 3) {
            throw new EncfsError("$label must be inside a share or disk, e.g. /mnt/user/<share>/<folder>");
        }
    }

    /**
     * Validate submitted fields and return a clean volume array.
     * $originalName is the name being edited ('' when adding).
     */
    public static function validateVolume(string $name, array $input, array $existing, string $originalName = ''): array
    {
        if (!self::isValidName($name)) {
            throw new EncfsError('Name must be 1-32 characters: letters, digits, dot, dash or underscore, starting with a letter or digit');
        }
        if ($name !== $originalName && isset($existing[$name])) {
            throw new EncfsError("A volume named '$name' already exists");
        }

        $volume = self::VOLUME_DEFAULTS;

        $volume['ENCDIR'] = self::normalizePath((string)($input['ENCDIR'] ?? ''), 'Encrypted directory');
        $volume['MOUNTPOINT'] = self::normalizePath((string)($input['MOUNTPOINT'] ?? ''), 'Mount point');
        $volume['CONFIG'] = self::normalizePath((string)($input['CONFIG'] ?? ''), 'Config file', true);
        $volume['KEYFILE'] = self::normalizePath((string)($input['KEYFILE'] ?? ''), 'Password file', true);

        self::requireUnderMnt($volume['ENCDIR'], 'Encrypted directory');
        self::requireUnderMnt($volume['MOUNTPOINT'], 'Mount point');

        if (self::isSameOrUnder($volume['MOUNTPOINT'], $volume['ENCDIR'])
            || self::isSameOrUnder($volume['ENCDIR'], $volume['MOUNTPOINT'])) {
            throw new EncfsError('The encrypted directory and the mount point must not contain each other');
        }
        if ($volume['CONFIG'] !== '' && self::isSameOrUnder($volume['CONFIG'], $volume['MOUNTPOINT'])) {
            throw new EncfsError('The config file cannot live inside the mount point');
        }
        if ($volume['KEYFILE'] !== '' && self::isSameOrUnder($volume['KEYFILE'], $volume['MOUNTPOINT'])) {
            throw new EncfsError('The password file cannot live inside the mount point');
        }

        foreach ($existing as $otherName => $other) {
            if ($otherName === $originalName) {
                continue;
            }
            if (self::isSameOrUnder($volume['MOUNTPOINT'], $other['MOUNTPOINT'])
                || self::isSameOrUnder($other['MOUNTPOINT'], $volume['MOUNTPOINT'])) {
                throw new EncfsError("Mount point overlaps with volume '$otherName'");
            }
        }

        $volume['UID'] = self::validateInt($input['UID'] ?? '99', 'Owner UID', 0, 65535);
        $volume['GID'] = self::validateInt($input['GID'] ?? '100', 'Owner GID', 0, 65535);
        $volume['IDLE'] = self::validateInt($input['IDLE'] ?? '0', 'Idle unmount minutes', 0, 10080);

        $volume['READONLY'] = self::validateChoice($input['READONLY'] ?? 'no', 'Read-only', ['yes', 'no']);
        $volume['LOCK_MOUNTPOINT'] = self::validateChoice($input['LOCK_MOUNTPOINT'] ?? 'yes', 'Lock mount point', ['yes', 'no']);
        $volume['AUTOMOUNT'] = self::validateChoice($input['AUTOMOUNT'] ?? 'no', 'Auto-mount', ['no', 'keyfile']);

        if ($volume['AUTOMOUNT'] === 'keyfile' && $volume['KEYFILE'] === '') {
            throw new EncfsError('Auto-mount with a password file needs the password file path');
        }

        return $volume;
    }

    private static function validateInt($value, string $label, int $min, int $max): string
    {
        $value = trim((string)$value);
        if (!preg_match('/^\d+$/', $value) || (int)$value < $min || (int)$value > $max) {
            throw new EncfsError("$label must be a whole number between $min and $max");
        }
        return (string)(int)$value;
    }

    private static function validateChoice($value, string $label, array $choices): string
    {
        $value = (string)$value;
        if (!in_array($value, $choices, true)) {
            throw new EncfsError("$label must be one of: " . implode(', ', $choices));
        }
        return $value;
    }

    public static function saveVolume(string $name, array $input, string $originalName = ''): array
    {
        return self::withLock(function () use ($name, $input, $originalName) {
            $volumes = self::loadVolumes();

            if ($originalName !== '' && !isset($volumes[$originalName])) {
                throw new EncfsError("Unknown volume '$originalName'");
            }
            if ($originalName !== '' && self::mountState($volumes[$originalName]['MOUNTPOINT'])['mounted']) {
                throw new EncfsError("Unmount '$originalName' before changing its settings");
            }

            $volume = self::validateVolume($name, $input, $volumes, $originalName);

            if ($originalName !== '' && $originalName !== $name) {
                unset($volumes[$originalName]);
            }
            $volumes[$name] = $volume;
            self::saveVolumes($volumes);

            self::log("Saved volume '$name' ({$volume['ENCDIR']} -> {$volume['MOUNTPOINT']})");

            // Housekeeping that is safe to do while unmounted.
            self::applyMountpointLock($volume);
            self::backupConfig($name, $volume);

            return $volume;
        });
    }

    public static function deleteVolume(string $name): void
    {
        self::withLock(function () use ($name) {
            $volumes = self::loadVolumes();
            if (!isset($volumes[$name])) {
                throw new EncfsError("Unknown volume '$name'");
            }
            if (self::mountState($volumes[$name]['MOUNTPOINT'])['mounted']) {
                throw new EncfsError("Unmount '$name' before removing it");
            }
            unset($volumes[$name]);
            self::saveVolumes($volumes);
            self::log("Removed volume '$name' from the plugin configuration (no data was touched)");
        });
    }

    /* ------------------------------------------------------------------ */
    /* Mount table inspection                                             */
    /* ------------------------------------------------------------------ */

    /** Decode the octal escapes (\040 etc.) used in /proc/self/mountinfo. */
    private static function decodeMountField(string $field): string
    {
        return preg_replace_callback('/\\\\([0-7]{3})/', function ($m) {
            return chr(octdec($m[1]));
        }, $field);
    }

    /** @return array<int, array{mountpoint:string, fstype:string, source:string, options:string, device:string}> */
    public static function readMounts(): array
    {
        $file = getenv('ENCFS_PLUGIN_MOUNTINFO') ?: '/proc/self/mountinfo';
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        $mounts = [];
        foreach ($lines as $line) {
            // Format: id parent major:minor root mountpoint options [optional...] - fstype source superopts
            $halves = explode(' - ', $line, 2);
            if (count($halves) !== 2) {
                continue;
            }
            $left = explode(' ', $halves[0]);
            $right = explode(' ', $halves[1]);
            if (count($left) < 6 || count($right) < 2) {
                continue;
            }
            $mounts[] = [
                'device'     => $left[2],
                'mountpoint' => self::decodeMountField($left[4]),
                'options'    => $left[5],
                'fstype'     => $right[0],
                'source'     => self::decodeMountField($right[1]),
            ];
        }
        return $mounts;
    }

    public static function isMountpoint(string $path): bool
    {
        $canonical = self::canonicalMountpoint($path);
        foreach (self::readMounts() as $mount) {
            if ($mount['mountpoint'] === $path || $mount['mountpoint'] === $canonical) {
                return true;
            }
        }
        return false;
    }

    /** Find the encfs daemon serving $mountpoint, or 0. */
    /**
     * The path the kernel records for a mount on $path.
     * Symlinks in the parent directories are resolved (an Unraid exclusive share
     * makes /mnt/user/<share> a symlink to /mnt/<pool>/<share>, and the mount table
     * then lists the pool path). The last component is not resolved, so this never
     * stats the mount point itself.
     */
    public static function canonicalMountpoint(string $path): string
    {
        $path = rtrim($path, '/');
        if ($path === '' || $path[0] !== '/') {
            return $path;
        }
        $parent = realpath(dirname($path));
        if ($parent === false) {
            return $path;
        }
        return rtrim($parent, '/') . '/' . basename($path);
    }

    /** Find the encfs daemon serving $mountpoint, or 0. */
    public static function findEncfsPid(string $mountpoint): int
    {
        $target = self::canonicalMountpoint($mountpoint);
        foreach (glob('/proc/[0-9]*/cmdline') as $cmdlineFile) {
            $raw = @file_get_contents($cmdlineFile);
            if ($raw === false || $raw === '') {
                continue;
            }
            $args = explode("\0", rtrim($raw, "\0"));
            if (basename($args[0]) !== 'encfs') {
                continue;
            }
            foreach (array_slice($args, 1) as $arg) {
                if ($arg === '' || $arg[0] !== '/' || $arg === '/') {
                    continue;
                }
                // encfs keeps the path as typed; compare both as typed and resolved.
                $arg = rtrim($arg, '/');
                if ($arg === $mountpoint || $arg === $target || self::canonicalMountpoint($arg) === $target) {
                    return (int)basename(dirname($cmdlineFile));
                }
            }
        }
        return 0;
    }

    /**
     * Describe what is mounted at $mountpoint.
     * Never stats the mount itself, so a hung FUSE daemon cannot block this.
     */
    public static function mountState(string $mountpoint): array
    {
        $state = [
            'mounted'  => false,
            'encfs'    => false,
            'fstype'   => '',
            'readonly' => false,
            'pid'      => 0,
            'stale'    => false,
            'device'   => '',
        ];

        $canonical = self::canonicalMountpoint($mountpoint);
        foreach (self::readMounts() as $mount) {
            if ($mount['mountpoint'] !== $mountpoint && $mount['mountpoint'] !== $canonical) {
                continue;
            }
            // The last entry for a path is the one on top.
            $state['mounted'] = true;
            $state['fstype'] = $mount['fstype'];
            $state['encfs'] = ($mount['fstype'] === 'fuse.encfs');
            $state['readonly'] = in_array('ro', explode(',', $mount['options']), true);
            $state['device'] = $mount['device'];
        }

        if ($state['encfs']) {
            $state['pid'] = self::findEncfsPid($mountpoint);
            $state['stale'] = ($state['pid'] === 0);
        }
        return $state;
    }

    /* ------------------------------------------------------------------ */
    /* Volume inspection                                                  */
    /* ------------------------------------------------------------------ */

    /** Path of the encfs config for a volume, or '' if none exists. */
    public static function findConfigFile(array $volume): string
    {
        if ($volume['CONFIG'] !== '') {
            return is_file($volume['CONFIG']) ? $volume['CONFIG'] : '';
        }
        foreach (self::CONFIG_NAMES as $configName) {
            $candidate = $volume['ENCDIR'] . '/' . $configName;
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        return '';
    }

    /** True if $dir exists and has no entries at all (dotfiles included). */
    public static function isEmptyDir(string $dir): bool
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            return false;
        }
        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                return false;
            }
        }
        return true;
    }

    public static function volumeStatus(string $name, array $volume): array
    {
        $mount = self::mountState($volume['MOUNTPOINT']);
        $encdirExists = is_dir($volume['ENCDIR']);
        $configFile = $encdirExists || $volume['CONFIG'] !== '' ? self::findConfigFile($volume) : '';

        $state = 'unmounted';
        if ($mount['mounted'] && $mount['encfs'] && $mount['stale']) {
            $state = 'stale';
        } elseif ($mount['mounted'] && $mount['encfs']) {
            $state = 'mounted';
        } elseif ($mount['mounted']) {
            $state = 'foreign';          // something other than encfs is mounted there
        } elseif (!$encdirExists) {
            $state = 'missing';
        } elseif ($configFile === '' && self::isEmptyDir($volume['ENCDIR'])) {
            $state = 'uninitialized';
        } elseif ($configFile === '') {
            $state = 'noconfig';
        }

        $mountpointExists = $mount['mounted'] ? true : is_dir($volume['MOUNTPOINT']);
        $mountpointEmpty = !$mount['mounted'] && $mountpointExists ? self::isEmptyDir($volume['MOUNTPOINT']) : null;
        $locked = null;
        if (!$mount['mounted'] && $mountpointExists) {
            $locked = ((fileperms($volume['MOUNTPOINT']) & 0777) === 0);
        }

        $keyfileOk = null;
        if ($volume['AUTOMOUNT'] === 'keyfile') {
            $keyfileOk = is_file($volume['KEYFILE']) && is_readable($volume['KEYFILE']);
        }

        return [
            'state'            => $state,
            'pid'              => $mount['pid'],
            'readonly'         => $mount['readonly'],
            'fstype'           => $mount['fstype'],
            'configFile'       => $configFile,
            'mountpointExists' => $mountpointExists,
            'mountpointEmpty'  => $mountpointEmpty,
            'locked'           => $locked,
            'keyfileOk'        => $keyfileOk,
            'backup'           => self::backupInfo($name),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Config backup to flash                                             */
    /* ------------------------------------------------------------------ */

    public static function backupFile(string $name): string
    {
        return self::backupDir() . '/' . $name . '.encfs6.xml';
    }

    public static function backupInfo(string $name): ?array
    {
        $file = self::backupFile($name);
        if (!is_file($file)) {
            return null;
        }
        return ['file' => $file, 'mtime' => filemtime($file)];
    }

    /**
     * Copy the volume's encfs config to the flash drive. The config holds the
     * password-wrapped volume key; without it the encrypted data is unreadable,
     * and it is already stored unencrypted next to the data, so a second copy
     * does not weaken anything. Older differing copies are kept with a timestamp.
     */
    public static function backupConfig(string $name, array $volume): ?string
    {
        $source = self::findConfigFile($volume);
        if ($source === '') {
            return null;
        }
        $content = @file_get_contents($source);
        if ($content === false || $content === '') {
            return null;
        }

        $dir = self::backupDir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            self::log("Could not create backup directory $dir", LOG_WARNING);
            return null;
        }

        $target = self::backupFile($name);
        if (is_file($target)) {
            $existing = @file_get_contents($target);
            if ($existing === $content) {
                return $target;
            }
            $archived = $target . '.' . date('Ymd-His', (int)filemtime($target));
            @rename($target, $archived);
        }

        if (@file_put_contents($target, $content) === false) {
            self::log("Could not back up encfs config for '$name' to $target", LOG_WARNING);
            return null;
        }
        self::log("Backed up encfs config for '$name' to $target");
        return $target;
    }

    /** Back up every volume's config; returns [name => backup path|null]. */
    public static function backupAll(): array
    {
        $results = [];
        foreach (self::loadVolumes() as $name => $volume) {
            $results[$name] = self::backupConfig($name, $volume);
        }
        return $results;
    }

    /* ------------------------------------------------------------------ */
    /* Mount point preparation                                            */
    /* ------------------------------------------------------------------ */

    /**
     * When enabled, the (empty) mount point directory is set to mode 000 while
     * nothing is mounted on it. SMB/NFS clients then cannot write plaintext
     * files into the share by accident when the volume is not mounted. The
     * mode of the directory underneath does not affect the mounted view.
     */
    public static function applyMountpointLock(array $volume): void
    {
        if ($volume['LOCK_MOUNTPOINT'] !== 'yes') {
            return;
        }
        $mountpoint = $volume['MOUNTPOINT'];
        // Check the mount table first: stat() on a mounted path goes to encfs.
        if (self::mountState($mountpoint)['mounted'] || !is_dir($mountpoint)) {
            return;
        }
        if ((fileperms($mountpoint) & 0777) !== 0) {
            @chmod($mountpoint, 0000);
        }
    }

    private static function prepareMountpoint(array $volume): void
    {
        $mountpoint = $volume['MOUNTPOINT'];
        $state = self::mountState($mountpoint);

        if ($state['mounted'] && $state['encfs'] && $state['stale']) {
            throw new EncfsError("$mountpoint has a stale EncFS mount (the encfs process is gone). Use Force unmount first.");
        }
        if ($state['mounted'] && $state['encfs']) {
            throw new EncfsError("$mountpoint is already mounted");
        }
        if ($state['mounted']) {
            throw new EncfsError("$mountpoint already has a {$state['fstype']} filesystem mounted on it");
        }

        if (!is_dir($mountpoint)) {
            if (file_exists($mountpoint)) {
                throw new EncfsError("$mountpoint exists and is not a directory");
            }
            $mode = $volume['LOCK_MOUNTPOINT'] === 'yes' ? 0000 : 0770;
            if (!@mkdir($mountpoint, 0770, true)) {
                throw new EncfsError("Could not create mount point $mountpoint");
            }
            @chmod($mountpoint, $mode);
            @chown($mountpoint, (int)$volume['UID']);
            @chgrp($mountpoint, (int)$volume['GID']);
        }

        if (!self::isEmptyDir($mountpoint)) {
            throw new EncfsError("$mountpoint is not empty. Files in it would be hidden by the mount (and are stored unencrypted); move them away first.");
        }

        self::applyMountpointLock($volume);
    }

    /* ------------------------------------------------------------------ */
    /* Mount / unmount                                                    */
    /* ------------------------------------------------------------------ */

    private static function buildEncfsArgs(string $name, array $volume, string $createMode = ''): array
    {
        $args = [self::encfsBin(), '--stdinpass', '--public', '--syslogtag=encfs-' . $name];
        if ($createMode === 'standard') {
            $args[] = '--standard';
        } elseif ($createMode === 'paranoia') {
            $args[] = '--paranoia';
        }
        if ((int)$volume['IDLE'] > 0) {
            $args[] = '--idle=' . (int)$volume['IDLE'];
        }
        if ($volume['CONFIG'] !== '') {
            $args[] = '--config=' . $volume['CONFIG'];
        }
        $args[] = $volume['ENCDIR'];
        $args[] = $volume['MOUNTPOINT'];
        $args[] = '--';
        // An explicit fsname makes libfuse drop the default "fuse.encfs" type,
        // so the subtype is set explicitly; mount detection relies on it.
        $args[] = '-o';
        $args[] = 'fsname=encfs-' . $name;
        $args[] = '-o';
        $args[] = 'subtype=encfs';
        $args[] = '-o';
        $args[] = 'uid=' . (int)$volume['UID'];
        $args[] = '-o';
        $args[] = 'gid=' . (int)$volume['GID'];
        if ($volume['READONLY'] === 'yes') {
            $args[] = '-o';
            $args[] = 'ro';
        }
        return $args;
    }

    /** Turn encfs output into a short, user-facing message. */
    private static function summarizeEncfsOutput(string $stdout, string $stderr, int $code): string
    {
        $text = trim($stderr . "\n" . $stdout);
        if (stripos($text, 'password incorrect') !== false) {
            return 'Incorrect password';
        }
        if ($code === 124 || $code === 137) {
            return 'encfs did not finish in time and was stopped';
        }
        $lines = [];
        foreach (preg_split('/\r?\n/', $text) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        if (count($lines) === 0) {
            return "encfs exited with status $code";
        }
        return implode(' | ', array_slice($lines, -3));
    }

    private static function waitForMount(string $mountpoint, int $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        do {
            $state = self::mountState($mountpoint);
            if ($state['mounted'] && $state['encfs']) {
                return true;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);
        return false;
    }

    private static function checkEncfsBinary(): void
    {
        if (!is_executable(self::encfsBin())) {
            throw new EncfsError('encfs binary not found at ' . self::encfsBin() . ' - reinstall the plugin');
        }
    }

    /** Mount an existing volume. $password is used once and never stored. */
    public static function mount(string $name, string $password, string $origin = 'webGUI'): array
    {
        if ($password === '') {
            throw new EncfsError('A password is required');
        }
        if (strpbrk($password, "\r\n") !== false) {
            throw new EncfsError('The password must be a single line');
        }

        return self::withLock(function () use ($name, $password, $origin) {
            self::checkEncfsBinary();
            $volume = self::getVolume($name);

            if (!is_dir($volume['ENCDIR'])) {
                throw new EncfsError("Encrypted directory {$volume['ENCDIR']} does not exist (is the array started?)");
            }
            // encfs silently creates a NEW volume when no config is found, so never
            // start it unless the existing config is present.
            if (self::findConfigFile($volume) === '') {
                $where = $volume['CONFIG'] !== '' ? $volume['CONFIG'] : $volume['ENCDIR'] . '/.encfs6.xml';
                throw new EncfsError("No encfs config found ($where). Refusing to mount, because encfs would create a new empty volume.");
            }

            self::prepareMountpoint($volume);

            $args = self::buildEncfsArgs($name, $volume);
            [$code, $stdout, $stderr] = self::run($args, $password . "\n", self::MOUNT_TIMEOUT_SECONDS, [], true);

            if ($code !== 0) {
                $reason = self::summarizeEncfsOutput($stdout, $stderr, $code);
                self::log("Mount of '$name' failed ($origin): $reason", LOG_WARNING);
                throw new EncfsError("Mount failed: $reason");
            }
            if (!self::waitForMount($volume['MOUNTPOINT'], 3)) {
                $where = self::canonicalMountpoint($volume['MOUNTPOINT']);
                self::log("encfs reported success for '$name' but no mount appeared at $where ($origin)", LOG_WARNING);
                throw new EncfsError("encfs reported success, but no EncFS mount was found at $where. Check the Recent EncFS log.");
            }

            self::log("Mounted '$name' at {$volume['MOUNTPOINT']} ($origin)");
            self::backupConfig($name, $volume);
            return self::volumeStatus($name, $volume);
        });
    }

    /** Create a brand-new encfs volume in an empty directory, then leave it mounted. */
    public static function initialize(string $name, string $password, string $mode): array
    {
        if (!in_array($mode, ['standard', 'paranoia'], true)) {
            throw new EncfsError('Mode must be standard or paranoia');
        }
        if (strlen($password) < 8) {
            throw new EncfsError('Use a password of at least 8 characters');
        }
        if (strpbrk($password, "\r\n") !== false) {
            throw new EncfsError('The password must be a single line');
        }

        return self::withLock(function () use ($name, $password, $mode) {
            self::checkEncfsBinary();
            $volume = self::getVolume($name);

            if (self::findConfigFile($volume) !== '') {
                throw new EncfsError('This volume already has an encfs config; it is already initialized');
            }
            if ($volume['CONFIG'] !== '' && file_exists($volume['CONFIG'])) {
                throw new EncfsError("{$volume['CONFIG']} already exists");
            }

            if (!is_dir($volume['ENCDIR'])) {
                if (!@mkdir($volume['ENCDIR'], 0770, true)) {
                    throw new EncfsError("Could not create {$volume['ENCDIR']}");
                }
                @chmod($volume['ENCDIR'], 0770);
                @chown($volume['ENCDIR'], (int)$volume['UID']);
                @chgrp($volume['ENCDIR'], (int)$volume['GID']);
            }
            if (!self::isEmptyDir($volume['ENCDIR'])) {
                throw new EncfsError("{$volume['ENCDIR']} is not empty; a new volume can only be created in an empty directory");
            }

            self::prepareMountpoint($volume);

            $args = self::buildEncfsArgs($name, $volume, $mode);
            [$code, $stdout, $stderr] = self::run($args, $password . "\n", self::MOUNT_TIMEOUT_SECONDS, [], true);

            if ($code !== 0) {
                $reason = self::summarizeEncfsOutput($stdout, $stderr, $code);
                self::log("Initializing '$name' failed: $reason", LOG_WARNING);
                throw new EncfsError("Initialization failed: $reason");
            }
            if (!self::waitForMount($volume['MOUNTPOINT'], 3)) {
                $where = self::canonicalMountpoint($volume['MOUNTPOINT']);
                self::log("encfs created '$name' but no mount appeared at $where", LOG_WARNING);
                throw new EncfsError("The volume was created, but no EncFS mount was found at $where. Check the Recent EncFS log.");
            }

            self::log("Created new $mode encfs volume '$name' in {$volume['ENCDIR']} and mounted it at {$volume['MOUNTPOINT']}");
            self::backupConfig($name, $volume);
            return self::volumeStatus($name, $volume);
        });
    }

    /** True if $pid is running (zombies count as gone). */
    private static function processAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        $stat = @file_get_contents("/proc/$pid/stat");
        if ($stat === false) {
            return false;
        }
        // Format: pid (comm) state ... ; comm may contain spaces, so split after ')'.
        $afterComm = substr($stat, (int)strrpos($stat, ')') + 2);
        return $afterComm !== '' && $afterComm[0] !== 'Z';
    }

    /** List processes with files open under $mountpoint (best effort, short timeout). */
    public static function busyProcesses(string $mountpoint): string
    {
        if (!is_executable('/usr/bin/fuser') && !is_executable('/bin/fuser')) {
            return '';
        }
        $fuser = is_executable('/usr/bin/fuser') ? '/usr/bin/fuser' : '/bin/fuser';
        // fuser prints the verbose table on stderr and bare PIDs on stdout.
        [, , $stderr] = self::run([$fuser, '-vm', $mountpoint], null, 5);
        return trim($stderr);
    }

    /**
     * Unmount whatever encfs mount sits at $mountpoint.
     * Without $force, a busy mount is left alone and an error is returned.
     * With $force: retry, then lazy-unmount, then stop the encfs daemon so the
     * backing directory is released (needed for the array to stop).
     */
    public static function unmountPath(string $mountpoint, bool $force, string $origin): void
    {
        $state = self::mountState($mountpoint);
        if (!$state['mounted']) {
            return;
        }
        if (!$state['encfs']) {
            throw new EncfsError("$mountpoint is a {$state['fstype']} mount, not EncFS; not touching it");
        }

        $pid = $state['pid'];
        $device = $state['device'];
        $attempts = $force ? 3 : 1;
        $lastError = '';

        for ($i = 0; $i < $attempts; $i++) {
            [$code, $stdout, $stderr] = self::run(['/bin/umount', $mountpoint], null, 20);
            if ($code === 0 && !self::mountState($mountpoint)['mounted']) {
                self::log("Unmounted $mountpoint ($origin)");
                return;
            }
            $lastError = trim($stderr . ' ' . $stdout);
            if ($i + 1 < $attempts) {
                sleep(1);
            }
        }

        if (!$force) {
            $busy = self::busyProcesses($mountpoint);
            $message = "Could not unmount $mountpoint: " . ($lastError !== '' ? $lastError : 'unknown error');
            if ($busy !== '') {
                $message .= "\nProcesses using it:\n" . $busy;
            }
            self::log("Unmount of $mountpoint refused ($origin): $lastError", LOG_WARNING);
            throw new EncfsError($message);
        }

        // Forced path: detach the mount, then make sure the daemon exits.
        self::log("Forcing unmount of $mountpoint ($origin): $lastError", LOG_WARNING);
        self::run(['/bin/umount', '-l', $mountpoint], null, 20);

        // A lazily unmounted encfs keeps running until every open file on it is
        // closed. Aborting the FUSE connection ends that immediately (when the
        // fusectl filesystem is mounted); signals are the fallback.
        self::abortFuseConnection($device);
        for ($i = 0; $i < 20 && self::processAlive($pid); $i++) {
            usleep(100000);
        }
        if (self::processAlive($pid)) {
            posix_kill_safe($pid, 15);
            for ($i = 0; $i < 30 && self::processAlive($pid); $i++) {
                usleep(100000);
            }
            if (self::processAlive($pid)) {
                posix_kill_safe($pid, 9);
                usleep(300000);
            }
        }

        if (self::mountState($mountpoint)['mounted']) {
            throw new EncfsError("$mountpoint is still mounted after a forced unmount");
        }
        self::log("Force-unmounted $mountpoint ($origin)", LOG_WARNING);
    }

    /** Abort a FUSE connection by device ("0:42"), if /sys/fs/fuse/connections is available. */
    private static function abortFuseConnection(string $device): void
    {
        $parts = explode(':', $device);
        if (count($parts) !== 2 || !ctype_digit($parts[1])) {
            return;
        }
        $connections = '/sys/fs/fuse/connections';
        $abort = $connections . '/' . $parts[1] . '/abort';
        if (!is_file($abort) && is_dir($connections) && !self::isMountpoint($connections)) {
            self::run(['/bin/mount', '-t', 'fusectl', 'fusectl', $connections], null, 10);
        }
        if (is_file($abort)) {
            @file_put_contents($abort, '1');
        }
    }

    public static function unmount(string $name, bool $force, string $origin = 'webGUI'): array
    {
        return self::withLock(function () use ($name, $force, $origin) {
            $volume = self::getVolume($name);
            self::unmountPath($volume['MOUNTPOINT'], $force, $origin);
            self::applyMountpointLock($volume);
            return self::volumeStatus($name, $volume);
        });
    }

    /**
     * Unmount every EncFS mount on the system whose mount point or encfs
     * arguments live under /mnt. Used when the array stops: any of these
     * would hold the array's file systems busy.
     * Returns [mountpoint => error|null].
     */
    public static function unmountAll(bool $force, string $origin): array
    {
        return self::withLock(function () use ($force, $origin) {
            $targets = [];
            foreach (self::readMounts() as $mount) {
                if ($mount['fstype'] !== 'fuse.encfs') {
                    continue;
                }
                $mountpoint = $mount['mountpoint'];
                $relevant = self::isSameOrUnder($mountpoint, self::ALLOWED_ROOT);
                if (!$relevant) {
                    $pid = self::findEncfsPid($mountpoint);
                    $relevant = ($pid > 0) && self::encfsArgsTouchMnt($pid);
                }
                if ($relevant) {
                    $targets[$mountpoint] = true;
                }
            }

            // Deepest mount points first, in case of nesting.
            $paths = array_keys($targets);
            usort($paths, function ($a, $b) {
                return substr_count($b, '/') <=> substr_count($a, '/');
            });

            $results = [];
            foreach ($paths as $mountpoint) {
                try {
                    self::unmountPath($mountpoint, $force, $origin);
                    $results[$mountpoint] = null;
                } catch (Throwable $e) {
                    $results[$mountpoint] = $e->getMessage();
                }
            }

            foreach (self::loadVolumes() as $volume) {
                self::applyMountpointLock($volume);
            }
            return $results;
        }, 300);
    }

    private static function encfsArgsTouchMnt(int $pid): bool
    {
        $raw = @file_get_contents("/proc/$pid/cmdline");
        if ($raw === false) {
            return false;
        }
        foreach (explode("\0", $raw) as $arg) {
            if (self::isSameOrUnder($arg, self::ALLOWED_ROOT)) {
                return true;
            }
        }
        return false;
    }

    /* ------------------------------------------------------------------ */
    /* Auto-mount at array start                                          */
    /* ------------------------------------------------------------------ */

    /** Read a password file: first line, without the line ending. */
    public static function readPasswordFile(string $path): string
    {
        if (!is_file($path)) {
            throw new EncfsError("Password file $path not found");
        }
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            throw new EncfsError("Password file $path is not readable");
        }
        $line = fgets($handle);
        fclose($handle);
        $password = rtrim((string)$line, "\r\n");
        if ($password === '') {
            throw new EncfsError("Password file $path is empty");
        }
        if ((fileperms($path) & 0077) !== 0) {
            self::log("Password file $path is readable by group/others; consider chmod 600", LOG_WARNING);
        }
        return $password;
    }

    /** Mount every volume configured for auto-mount. Returns [name => error|null]. */
    public static function automount(): array
    {
        $results = [];
        foreach (self::loadVolumes() as $name => $volume) {
            if ($volume['AUTOMOUNT'] !== 'keyfile') {
                continue;
            }
            if (self::mountState($volume['MOUNTPOINT'])['mounted']) {
                $results[$name] = null;
                continue;
            }
            try {
                $password = self::readPasswordFile($volume['KEYFILE']);
                self::mount($name, $password, 'auto-mount');
                $password = '';
                $results[$name] = null;
            } catch (Throwable $e) {
                $results[$name] = $e->getMessage();
                self::log("Auto-mount of '$name' failed: " . $e->getMessage(), LOG_ERR);
                self::notify("Auto-mount of '$name' failed", $e->getMessage(), 'alert');
            }
        }
        return $results;
    }

    /* ------------------------------------------------------------------ */
    /* Diagnostics                                                        */
    /* ------------------------------------------------------------------ */

    public static function encfsVersion(): string
    {
        if (!is_executable(self::encfsBin())) {
            return '';
        }
        [$code, $stdout, $stderr] = self::run([self::encfsBin(), '--version'], null, 5);
        $text = trim($stdout . ' ' . $stderr);
        return preg_match('/(\d+\.\d+(\.\d+)?)/', $text, $m) ? $m[1] : '';
    }

    /** Output of `encfsctl info` (no password needed). */
    public static function info(string $name): array
    {
        $volume = self::getVolume($name);
        $configFile = self::findConfigFile($volume);
        $details = '';

        if ($configFile !== '' && is_executable(self::encfsctlBin())) {
            $env = [];
            if ($volume['CONFIG'] !== '') {
                $env['ENCFS6_CONFIG'] = $volume['CONFIG'];
            }
            [$code, $stdout, $stderr] = self::run([self::encfsctlBin(), 'info', $volume['ENCDIR']], null, 15, $env);
            $details = trim($stdout . "\n" . $stderr);
        } elseif ($configFile === '') {
            $details = 'No encfs config file found.';
        }

        return [
            'name'       => $name,
            'volume'     => $volume,
            'status'     => self::volumeStatus($name, $volume),
            'configFile' => $configFile,
            'details'    => $details,
        ];
    }

    /** Recent plugin and encfs syslog lines (no secrets are ever logged). */
    public static function recentLog(int $maxLines = 60): array
    {
        $file = getenv('ENCFS_PLUGIN_SYSLOG') ?: '/var/log/syslog';
        $handle = @fopen($file, 'r');
        if ($handle === false) {
            return [];
        }
        // Read only the tail of the file.
        $size = filesize($file);
        $chunk = 512 * 1024;
        if ($size > $chunk) {
            fseek($handle, $size - $chunk);
            fgets($handle);
        }
        $lines = [];
        while (($line = fgets($handle)) !== false) {
            if (preg_match('/\b(encfs-plugin|encfs-[A-Za-z0-9._-]+|encfs)\[/', $line)) {
                $lines[] = rtrim($line, "\r\n");
            }
        }
        fclose($handle);
        return array_slice($lines, -$maxLines);
    }

    public static function arrayState(): string
    {
        $var = @parse_ini_file(self::varIniFile());
        if (!is_array($var)) {
            return 'Unknown';
        }
        return (string)($var['fsState'] ?? 'Unknown');
    }

    /* ------------------------------------------------------------------ */
    /* Migration from the 2016 plugin                                     */
    /* ------------------------------------------------------------------ */

    /**
     * The old plugin stored a single volume as ENCDIR0/DECDIR0 in encfs.cfg.
     * Import it once, then rename the old file so it is not imported again.
     */
    public static function migrateLegacy(): ?string
    {
        $legacy = self::legacyCfgFile();
        if (!is_file($legacy) || is_file(self::volumesFile())) {
            return null;
        }
        $old = @parse_ini_file($legacy, false, INI_SCANNER_RAW);
        $encdir = is_array($old) ? trim((string)($old['ENCDIR0'] ?? '')) : '';
        $decdir = is_array($old) ? trim((string)($old['DECDIR0'] ?? '')) : '';

        $imported = null;
        $isPlaceholder = ($encdir === '/mnt/user/.encStore/test' && !is_dir($encdir));
        if ($encdir !== '' && $decdir !== '' && !$isPlaceholder) {
            $name = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($decdir));
            $name = ltrim((string)$name, '._-');
            if (!self::isValidName($name)) {
                $name = 'volume0';
            }
            try {
                $volume = self::validateVolume($name, ['ENCDIR' => $encdir, 'MOUNTPOINT' => $decdir], []);
                self::saveVolumes([$name => $volume]);
                self::log("Imported volume '$name' from the legacy plugin configuration");
                self::backupConfig($name, $volume);
                $imported = $name;
            } catch (Throwable $e) {
                self::log('Legacy configuration not imported: ' . $e->getMessage(), LOG_WARNING);
            }
        }

        @rename($legacy, $legacy . '.legacy');
        return $imported;
    }
}

/** posix_kill when available, otherwise /bin/kill. */
function posix_kill_safe(int $pid, int $signal): void
{
    if ($pid <= 0) {
        return;
    }
    if (function_exists('posix_kill')) {
        @posix_kill($pid, $signal);
        return;
    }
    Encfs::run(['/bin/kill', '-' . $signal, (string)$pid], null, 5);
}
