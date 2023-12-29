<?php

$config = new Amp\CodeStyle\Config;
$config->getFinder()
    ->in(__DIR__ . '/examples')
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/test')
    ->in(__DIR__ . '/test-autobahn');

$config->setCacheFile(__DIR__ . '/.php_cs.cache');

return $config;
