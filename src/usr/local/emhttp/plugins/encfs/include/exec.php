<?php
/*
 * EncFS plugin - JSON endpoint for the settings page.
 *
 *   GET  ?action=list            volumes, their state, array state
 *   GET  ?action=info&name=N     encfsctl info for one volume
 *   GET  ?action=log             recent EncFS lines from the syslog
 *   POST action=save             add or edit a volume
 *   POST action=delete           remove a volume from the configuration
 *   POST action=mount            mount with a password (password is never stored)
 *   POST action=unmount          unmount (force=1 for the escalating unmount)
 *   POST action=initialize       create a new encfs volume in an empty directory
 *   POST action=backup           copy the volume's encfs config to the flash drive
 *
 * Every POST needs the webGUI csrf_token. On Unraid, local_prepend.php validates
 * and strips it before this script runs; when that prepend is not active (tests),
 * the token is validated here.
 */

require_once __DIR__ . '/Encfs.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
set_time_limit(300);

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $message, int $status = 200): void
{
    respond(['ok' => false, 'error' => $message], $status);
}

function csrfValid(): bool
{
    $prepend = (string)ini_get('auto_prepend_file');
    if (strpos($prepend, 'local_prepend.php') !== false) {
        return true;   // Unraid already rejected bad tokens before we were called.
    }
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $var = @parse_ini_file(Encfs::varIniFile());
    if (!is_array($var) || empty($var['csrf_token']) || !is_string($token)) {
        return false;
    }
    return hash_equals((string)$var['csrf_token'], $token);
}

function param(string $key): string
{
    $value = $_POST[$key] ?? ($_GET[$key] ?? '');
    return is_string($value) ? $value : '';
}

function volumeRows(): array
{
    $rows = [];
    foreach (Encfs::loadVolumes() as $name => $volume) {
        $rows[] = [
            'name'   => $name,
            'volume' => $volume,
            'status' => Encfs::volumeStatus($name, $volume),
        ];
    }
    return $rows;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = param('action');

$readActions = ['list', 'info', 'log'];
$writeActions = ['save', 'delete', 'mount', 'unmount', 'initialize', 'backup'];

if (in_array($action, $writeActions, true)) {
    if ($method !== 'POST') {
        fail('This action requires POST', 405);
    }
    if (!csrfValid()) {
        fail('Invalid security token; reload the page and try again', 403);
    }
} elseif (!in_array($action, $readActions, true)) {
    fail('Unknown action', 400);
}

try {
    switch ($action) {
        case 'list':
            respond([
                'ok'         => true,
                'arrayState' => Encfs::arrayState(),
                'version'    => Encfs::encfsVersion(),
                'volumes'    => volumeRows(),
            ]);
            break;

        case 'info':
            respond(['ok' => true, 'info' => Encfs::info(param('name'))]);
            break;

        case 'log':
            respond(['ok' => true, 'lines' => Encfs::recentLog()]);
            break;

        case 'save':
            $fields = [];
            foreach (array_keys(Encfs::VOLUME_DEFAULTS) as $key) {
                $fields[$key] = param($key);
            }
            Encfs::saveVolume(param('name'), $fields, param('original'));
            respond(['ok' => true, 'volumes' => volumeRows()]);
            break;

        case 'delete':
            Encfs::deleteVolume(param('name'));
            respond(['ok' => true, 'volumes' => volumeRows()]);
            break;

        case 'mount':
            $password = $_POST['password'] ?? '';
            unset($_POST['password']);
            Encfs::mount(param('name'), is_string($password) ? $password : '', 'webGUI');
            $password = null;
            respond(['ok' => true, 'volumes' => volumeRows()]);
            break;

        case 'initialize':
            $password = $_POST['password'] ?? '';
            unset($_POST['password']);
            Encfs::initialize(param('name'), is_string($password) ? $password : '', param('mode'));
            $password = null;
            respond(['ok' => true, 'volumes' => volumeRows()]);
            break;

        case 'unmount':
            Encfs::unmount(param('name'), param('force') === '1', 'webGUI');
            respond(['ok' => true, 'volumes' => volumeRows()]);
            break;

        case 'backup':
            $name = param('name');
            $target = Encfs::backupConfig($name, Encfs::getVolume($name));
            if ($target === null) {
                fail('No encfs config file found to back up');
            }
            respond(['ok' => true, 'file' => $target, 'volumes' => volumeRows()]);
            break;
    }
} catch (EncfsError $e) {
    fail($e->getMessage());
} catch (Throwable $e) {
    Encfs::log('Unexpected error in exec.php: ' . $e->getMessage(), LOG_ERR);
    fail('Unexpected error: ' . $e->getMessage(), 500);
}
