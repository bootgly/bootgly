<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

#error_reporting(E_ALL); ini_set('display_errors', 'On');

#phpinfo(); exit;

define('BOOTGLY_WORKING_BASE', __DIR__);
define('BOOTGLY_WORKING_DIR', BOOTGLY_WORKING_BASE . DIRECTORY_SEPARATOR);

(@include __DIR__ . '/autoboot.php') || exit(1);
