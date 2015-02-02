<?php

require __DIR__ . '/../vendor/autoload.php';

$configurator = new Nette\Configurator;

//$configurator->setDebugMode(TRUE);  // debug mode MUST NOT be enabled on production server

$configurator->setDebugMode(true);
$configurator->enableDebugger(__DIR__ . '/../log');

$configurator->setTempDirectory(__DIR__ . '/../temp');

$configurator->createRobotLoader()
	->addDirectory(__DIR__)
	->addDirectory(__DIR__ . '/../vendor/others')
	->register();

$configurator->addConfig(__DIR__ . '/config/config.neon');
$configurator->addConfig(__DIR__ . '/config/config.local.neon');

$configurator->addConfig(__DIR__ . '/config/izi-ui.config.neon');

$container = $configurator->createContainer();

//error_reporting(E_ALL ^ E_DEPRECATED);//deprecated rozhraní kvůli MySQL v rámci ARC2_Store

return $container;
