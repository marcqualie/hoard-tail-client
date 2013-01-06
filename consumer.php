#!/usr/bin/env php
<?php

error_reporting(E_ALL & ~E_NOTICE);

// Get Config
$params = array();
for ($i = 1; $i < count($argv); $i++)
{
	list ($key, $val) = explode('=', substr($argv[$i], 2));
	$params[$key] = $val;
}

// Get config file
$config_file = isset($params['config']) ? $params['config'] : '';
if ( ! $config_file || ! file_exists($config_file))
{
	$config_file = __DIR__ . '/config.json';
}
$config = array();
if ( ! file_exists($config_file))
{
	exit('Config file not found [' . $config_file . ']');
}
$file = file_get_contents($config_file);
$config = json_decode($file, true);

// Initialize Instance of Hoardtail
include __DIR__ . '/lib/HoardTail.php';
$client = new HoardTail();
if (isset($params['log_level']))
{
	$client->setLogLevel($params['log_level']);
}
$client->loadConfig($config);
$client->listen();
