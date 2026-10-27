<?php

$autoload = getenv('SEARCH_STRING_TEST_AUTOLOAD') ?: __DIR__.'/../../vendor/autoload.php';
require $autoload;
// Prepend to Composer's class map when testing against another installation's dependencies.
spl_autoload_register(function ($class) {
    $prefix = 'Lorisleiva\\LaravelSearchString\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $base = __DIR__.'/../../src/';
    if (str_starts_with($relative, 'Tests\\')) {
        $relative = substr($relative, strlen('Tests\\'));
        $base = __DIR__.'/../';
    }
    $file = $base.str_replace('\\', '/', $relative).'.php';
    if (is_file($file)) {
        require $file;
    }
}, true, true);

// Component-only CI has no Foundation helpers. Never override a framework helper.
if (! function_exists('config')) {
    function config($key = null, $default = null)
    {
        $repository = \Illuminate\Container\Container::getInstance()->make('config');
        if ($key === null) {
            return $repository;
        }
        if (is_array($key)) {
            return $repository->set($key);
        }
        return $repository->get($key, $default);
    }
}
