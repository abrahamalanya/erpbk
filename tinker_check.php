<?php
// Sanity check de las clases Greenter disponibles.
echo 'xmldsig hasers:'.PHP_EOL;
echo '  '.current(array_filter(get_declared_classes(), fn ($c) => str_contains($c, 'XMLSec'))).PHP_EOL;

$tests = [
    'Greenter\\Ws\\Services\\BillSender',
    'Greenter\\Ws\\Services\\SummarySender',
    'Greenter\\Ws\\Services\\StatusSender',
    'Greenter\\Xml\\Builder\\DefaultBuilder',
    'Greenter\\Xml\\Model\\Sale\\Sale',
    'Greenter\\Model\\Sale\\Sale',
    'Greenter\\Model\\Document\\DefaultDocument',
    'Greenter\\Model\\Summary\\Summary',
    'Greenter\\Builder\\Builder',
    'Greenter\\Ws\\Builder\\ConfigBuilder',
];

foreach ($tests as $cls) {
    printf("  %-45s %s\n", $cls, class_exists($cls) ? 'OK' : 'NO');
}

echo PHP_EOL.'Xml builders anywhere:'.PHP_EOL;
$dir = __DIR__.'/vendor/greenter/xml/src';
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $f) {
    if ($f->isFile() && str_contains($f->getFilename(), 'Builder')) {
        echo '  '.str_replace($dir.'\\', '', $f->getPathname()).PHP_EOL;
    }
}