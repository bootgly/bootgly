<?php
namespace Bootgly;


@include_once __DIR__ . '/imports/autoload.php';

define('HOME_BASE', rtrim(__DIR__, '/@'));
const HOME_DIR = HOME_BASE . DIRECTORY_SEPARATOR;

// TODO load with autoloader
// @ Bootstrap
@include_once HOME_BASE . '/abstract/..php';
@include_once HOME_BASE . '/base/@loader.php';
@include_once HOME_BASE . '/core/@loader.php';
// @ Resources
@include_once HOME_BASE . '/interfaces/@loader.php';
@include_once HOME_BASE . '/modules/@loader.php';
@include_once HOME_BASE . '/nodes/@loader.php';
@include_once HOME_BASE . '/platforms/@loader.php';

Bootgly::boot();
