<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly;


use Bootgly;


// ?
if ($this instanceof Bootgly === false)
   return;

// ! Resources ([a-z])
require(__DIR__ . '/ABI/autoload.php');
require(__DIR__ . '/ACI/autoload.php');
require(__DIR__ . '/ADI/autoload.php');
require(__DIR__ . '/API/autoload.php');
require(__DIR__ . '/CLI/autoload.php');
require(__DIR__ . '/WPI/autoload.php');

// @
/**
 * @var CLI CLI
 */
const CLI = new CLI;
/**
 * @var WPI WPI
 */
const WPI = new WPI;

return [
   CLI,
   WPI
];
