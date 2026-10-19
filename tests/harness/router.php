<?php
/*
 * Development harness: serves the plugin with PHP's built-in web server and
 * renders EncFS.page roughly the way the Unraid webGUI does, using the real
 * Unraid stylesheets from a checkout of github.com/unraid/webgui.
 *
 *   ENCFS_HARNESS_WEBGUI=/path/to/webgui/emhttp/plugins/dynamix \
 *   php -S 127.0.0.1:8080 -t <docroot> tests/harness/router.php
 *
 * <docroot> must contain plugins/encfs -> src/usr/local/emhttp/plugins/encfs.
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($uri === '/plugins/encfs/include/exec.php') {
    require $_SERVER['DOCUMENT_ROOT'] . '/plugins/encfs/include/exec.php';
    return true;
}

if (strpos($uri, '/Settings/EncFS') !== 0) {
    return false;   // static file from the docroot
}

$theme = $_GET['theme'] ?? 'black';
$var = @parse_ini_file(getenv('ENCFS_PLUGIN_VAR_INI')) ?: [];

function autov(string $path): void
{
    echo $path . '?v=' . time();
}

$page = file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/plugins/encfs/EncFS.page');
[$header, $body] = explode("\n---\n", $page, 2);

ob_start();
eval('?>' . $body);
$content = ob_get_clean();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>EncFS harness</title>
<link rel="stylesheet" href="/webGui/styles/default-fonts.css">
<link rel="stylesheet" href="/webGui/styles/default-color-palette.css">
<link rel="stylesheet" href="/webGui/styles/default-base.css">
<link rel="stylesheet" href="/webGui/styles/default-dynamix.css">
<link rel="stylesheet" href="/webGui/styles/font-awesome.css">
<link rel="stylesheet" href="/webGui/styles/themes/<?=htmlspecialchars($theme)?>.css">
<script>
var csrf_token = <?=json_encode($var['csrf_token'] ?? '')?>;
function done() { console.log('done() called'); }
</script>
</head>
<body>
<div id="displaybox" style="padding: 20px;">
<div class="title"><span class="left"><i class="fa fa-lock title"></i>EncFS</span></div>
<?=$content?>
</div>
</body>
</html>
