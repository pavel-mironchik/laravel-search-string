<?php

// CI dependencies only: the checkout is loaded by bootstrap.php, never installed
// with invented package metadata or a fabricated version.
[$script, $target, $line, $platform] = $argv;
if (! in_array([$line, $platform], [['10', '8.1.0'], ['11', '8.4.0']], true)) {
    throw new InvalidArgumentException('Unsupported native CI target');
}
$require = ['php' => '^'.$platform];
foreach (['support', 'database', 'events', 'config', 'console'] as $component) {
    $require['illuminate/'.$component] = '^'.$line.'.0';
}
$require['phpunit/phpunit'] = $line === '10' ? '^10.5.63' : '^11.5.50';
$manifest = [
    'name' => 'flashasia/native-ci-consumer',
    'description' => 'Temporary component-only native compiler test consumer',
    'license' => 'MIT',
    'require' => $require,
    'config' => ['allow-plugins' => false, 'platform' => ['php' => $platform]],
];
if (! is_dir($target) && ! mkdir($target, 0700, true)) {
    throw new RuntimeException('Cannot create consumer directory');
}
file_put_contents($target.'/composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
