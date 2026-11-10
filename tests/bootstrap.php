<?php
/**
 * Unit tests of the module: no WHMCS, a fake host where one is needed.
 */
define('WHMCS', true);
define('ROOTDIR', __DIR__);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/modules/gateways/coinspay/loader.php';
require __DIR__ . '/FakeHost.php';
