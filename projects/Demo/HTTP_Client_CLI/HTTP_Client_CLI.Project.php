<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Demo\HTTP_Client_CLI;


use function getenv;

use const Bootgly\CLI;
use Bootgly\API\Projects\Project;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Configs;


return new Project(
   // # Project Metadata
   name: 'Demo HTTP Client CLI',
   description: 'Demonstration project for Bootgly HTTP Client CLI',
   version: '1.0.0',
   author: 'Bootgly',
   exportable: true,

   // # Project Boot Function
   boot: function (array $arguments = [], array $options = []): void
   {
      $Output = CLI->Terminal->Output;

      $host = getenv('HTTP_HOST') ?: '127.0.0.1';
      $port = getenv('HTTP_PORT') ? (int) getenv('HTTP_PORT') : 8082;

      $HTTP_Client_CLI = new HTTP_Client_CLI;
      $HTTP_Client_CLI->configure(
         new Configs(
            host: $host,
            port: $port,
         )
      );

      $Output->render('@.;@#cyan:→ Sending GET / to ' . $host . ':' . $port . '@;@.;');

      $Response = $HTTP_Client_CLI->request(
         method: 'GET',
         URI: '/',
      );

      $Output->render('@#green:✓ Response received@;@.;');
      $Output->render('  Status: @#cyan:' . $Response->code . ' ' . $Response->status . '@;@.;');
      $Output->render('  Body:   @#yellow:' . $Response->Body->raw . '@;@..;');
   }
);
