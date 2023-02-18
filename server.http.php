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


$Web = new Web;


use Bootgly\Web\HTTP;


$HTTPServer = new HTTP\Server($Web);
$HTTPServer->configure(
   host: '0.0.0.0',
   port: 8080,
   workers: round( ((int) shell_exec('nproc')) * 0.50 ), // Without JIT: * 0.6
   /*
   ssl: [
      // SSL Certificate
      'local_cert'  => __DIR__ . '/@/certificates/localhost.cert.pem', 
      // SSL Keyfile
      'local_pk'    => __DIR__ . '/@/certificates/localhost.key.pem',

      'disable_compression' => true, // TLS compression attack vulnerability

      'ssltransport' => 'tlsv1.3',

      'ciphers' => 'AES256-SHA256',

      'verify_peer' => false,
      #'verify_peer_name' => true,
      #'capture_peer_cert' => true,
   ]
   */
);
// on Data -> projects/sapi.http.constructor.php
$HTTPServer->start();

// Benchmark test suggestion with 512 connections:

// eg. if your CPU have 24 CPU cores:

// # Without JIT enabled: ~40% of CPU
// wrk -t10 -c514 -d10s http://localhost:8080

// # With JIT enabled: ~50% of CPU
// wrk -t12 -c514 -d10s http://localhost:8080