<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly;

#error_reporting(E_ALL); ini_set('display_errors', 'On');

const HOME_DIR = __DIR__.DIRECTORY_SEPARATOR;

#phpinfo(); exit;

require_once '@/autoload.php';

require_once 'boot/..php';
require_once 'core/@loader.php';
require_once 'interfaces/@loader.php';

#require_once 'modules/@loader.php';
require_once 'nodes/@loader.php';
require_once 'platforms/@loader.php';

$Bootgly = new Bootgly;
$Web = new Web($Bootgly);