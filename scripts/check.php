<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
foreach (['src', 'tests', 'scripts', 'examples'] as $directory) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $process = proc_open([PHP_BINARY, '-l', $file->getPathname()], [STDIN, STDOUT, STDERR], $pipes);
        if (!is_resource($process) || proc_close($process) !== 0) {
            exit(1);
        }
    }
}
if (!str_contains(file_get_contents($root . '/README.md'), 'packagist/v/bicho-fyi/php')) {
    throw new RuntimeException('README Packagist version badge is missing');
}
echo "PASS PHP syntax and README badge verified\n";
