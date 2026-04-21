<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace projects\Demo_HTTP_Client_CLI;


use Bootgly\API\Projects\Project;


return new Project(
   // # Project Metadata
   name: 'Demo HTTP Client CLI',
   description: 'Demonstration project for Bootgly HTTP Client CLI',
   version: '1.0.0',
   author: 'Bootgly',

   // # Project Boot Function
   boot: function (array $arguments = [], array $options = []): void
   {
      (require __DIR__ . '/../Demo/HTTP_Client_CLI/HTTP_Client_CLI.SAPI.php')($options);
   }
);
