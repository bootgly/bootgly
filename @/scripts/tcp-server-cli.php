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


include_once __DIR__ . '/../autoload.php';


use Bootgly\Web\TCP;


$TCPServer = new TCP\Server;
$TCPServer->configure(
   host: '0.0.0.0',
   port: 8080,
   workers: 12
);
// on Data -> projects/sapi.constructor.php
$TCPServer->start();
