<?php

$vendorDir = dirname(__DIR__, 4) . '/vendor';
$autoload  = $vendorDir . '/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "未找到 {$autoload}，请先在项目根目录执行 composer install\n");
    exit(1);
}

/** @var \Composer\Autoload\ClassLoader $loader */
$loader = require $autoload;

$packagesRoot = dirname(__DIR__, 2);
foreach (glob($packagesRoot . '/*/composer.json') ?: [] as $composerFile) {
    $manifest = json_decode((string) file_get_contents($composerFile), true);
    if (!is_array($manifest)) {
        continue;
    }

    foreach (($manifest['autoload']['psr-4'] ?? []) as $namespace => $path) {
        $relative = is_array($path) ? (string) reset($path) : (string) $path;
        $dir      = dirname($composerFile) . '/' . rtrim($relative, '/');
        if (is_dir($dir)) {
            $loader->addPsr4($namespace, $dir);
        }
    }
}

$loader->addPsr4('wise\\crontab\\test\\', __DIR__ . '/');
