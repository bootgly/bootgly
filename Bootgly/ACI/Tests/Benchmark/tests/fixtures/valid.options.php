<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

return [
   'server-workers' => [
      'type' => 'int',
      'default' => null,   // auto
      'vary' => true,
      'description' => 'Number of server workers (default: auto)',
   ],
   'profile' => [
      'type' => 'bool',
      'description' => 'Enable the profiler',
   ],
   'label' => [
      'type' => 'string',
      'default' => 'run',
      'description' => 'Run label',
   ],
];
