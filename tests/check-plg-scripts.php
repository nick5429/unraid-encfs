<?php
// Extract every INLINE script from a .plg the way Unraid's plugin manager reads it
// (SimpleXML, entities expanded) and write them out for shellcheck.
// Usage: php tests/check-plg-scripts.php encfs.plg OUTDIR
$xml = simplexml_load_file($argv[1], null, LIBXML_NOCDATA);
if ($xml === false) {
    fwrite(STDERR, "XML parse error\n");
    exit(1);
}
$i = 0;
foreach ($xml->FILE as $file) {
    $i++;
    if ($file->INLINE && (string)$file->attributes()->Run === '/bin/bash') {
        $method = (string)($file->attributes()->Method ?: 'install');
        file_put_contents("{$argv[2]}/inline$i-$method.sh", "#!/bin/bash\n" . $file->INLINE);
        echo "{$argv[2]}/inline$i-$method.sh\n";
    }
}
