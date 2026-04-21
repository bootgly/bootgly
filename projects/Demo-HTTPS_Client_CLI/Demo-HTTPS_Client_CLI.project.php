<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace projects\Demo_HTTPS_Client_CLI;


use Bootgly\API\Projects\Project;


return new Project(
   // # Project Metadata
   name: 'Demo HTTPS Client CLI',
   description: 'Demonstration project for Bootgly HTTPS Client CLI',
   version: '1.0.0',
   author: 'Bootgly',

   // # Project Boot Function
   boot: function (array $arguments = [], array $options = []): void
   {
      (require __DIR__ . '/../Demo/HTTPS_Client_CLI/HTTPS_Client_CLI.SAPI.php')($options);
   }
);
