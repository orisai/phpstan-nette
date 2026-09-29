<?php declare(strict_types = 1);

$vendorDir = getenv('COMPOSER_VENDOR_DIR');

return require __DIR__ . '/../' . (is_string($vendorDir) && $vendorDir !== '' ? $vendorDir : 'vendor') . '/autoload.php';
