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


require_once '@/autoload.php';


use Bootgly\Web\TCP;


$TCPClient = new TCP\Client;
$TCPClient->configure(
   host: '127.0.0.1',
   port: 8080,
   workers: 1
);
// TODO onWorkerStart

// TODO onConnection

// TODO onPackageRead
// TODO onPackageWrite
$TCPClient->start();