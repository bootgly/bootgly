<?php
namespace Bootgly;


@include_once __DIR__ . '/imports/autoload.php';

define('HOME_BASE', rtrim(__DIR__, '/@'));
const HOME_DIR = HOME_BASE . DIRECTORY_SEPARATOR;

// TODO load with autoloader
// @ Bootstrap
require_once HOME_BASE . '/abstract/..php';
require_once HOME_BASE . '/base/@loader.php';
require_once HOME_BASE . '/core/@loader.php';
// @ Resources
require_once HOME_BASE . '/interfaces/@loader.php';
require_once HOME_BASE . '/modules/@loader.php';
require_once HOME_BASE . '/nodes/@loader.php';
require_once HOME_BASE . '/platforms/@loader.php';

Bootgly::boot();
