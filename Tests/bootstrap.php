<?php

$composerAutoloadFile = __DIR__.'/../vendor/autoload.php';
if (false == file_exists($composerAutoloadFile)) {
    die('The composer autoload file "'.$composerAutoloadFile."\" was not found.\n\nDidn't you forget to run \"php composer.phar install\"?\n");
}

$loader = include_once $composerAutoloadFile;

spl_autoload_register(function ($class) {
    if (0 === strpos($class, 'JMS\\Payment\\PaypalBundle\\')) {
        $path = __DIR__.'/../'.implode('/', array_slice(explode('\\', $class), 3)).'.php';
        if (!stream_resolve_include_path($path)) {
            return false;
        }
        require_once $path;

        return true;
    }
});
