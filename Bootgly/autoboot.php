<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly;


use Bootgly;


// ?
if ( ($this ?? null) && $this instanceof Bootgly === false )
   return;

// ! Resources ([a-z])
require(__DIR__ . '/ABI/autoboot.php');
require(__DIR__ . '/ACI/autoboot.php');
require(__DIR__ . '/ADI/autoboot.php');
require(__DIR__ . '/API/autoboot.php');
require(__DIR__ . '/CLI/autoboot.php');
require(__DIR__ . '/WPI/autoboot.php');

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
