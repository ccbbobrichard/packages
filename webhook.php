<?php

require_once __DIR__ . '/vendor/autoload.php';

global $argc;

use ShopenGroup\SatisHook\ApplicationFactory;

$application = ApplicationFactory::createApplication(__DIR__ . '/config.yaml', __DIR__ . '/temp', __DIR__ . '/logs', $argc);
$application->run();