<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

// Unified project registry — the security allow-list.
//
// Only the project paths declared here may be started (`bootgly project <path> start`)
// or autobooted by the Web platform. Each key is the project's canonical path,
// relative to this `projects/` directory, at any depth (subprojects). Each value
// binds the project to one or more interfaces:
//   - CLI → Console platform
//   - WPI → Web platform (the first WPI entry is the web SAPI default)

// Kept in alphabetical order by project path. Order is for readability only —
// the Web (WPI) autoboot default is the entry flagged `'default' => true`,
// not the first entry.
return [
   'Benchmark/HTTP_Server_CLI'   => ['interfaces' => ['WPI']],
   'Benchmark/TCP_Server_CLI'    => ['interfaces' => ['WPI']],
   'Benchmark/UDP_Server_CLI'    => ['interfaces' => ['WPI']],
   'Demo/CLI'                    => ['interfaces' => ['CLI']],
   'Demo/HTTP_Client_CLI'        => ['interfaces' => ['WPI']],
   'Demo/HTTP_Server_CLI'        => ['interfaces' => ['WPI'], 'default' => true],
   'Demo/HTTPS_Client_CLI'       => ['interfaces' => ['WPI']],
   'Demo/HTTPS_Server_CLI'       => ['interfaces' => ['WPI']],
   'Demo/Queue-HTTP_Server_CLI'  => ['interfaces' => ['WPI']],
   'Demo/TCP_Client_CLI'         => ['interfaces' => ['WPI']],
   'Demo/TCP_Server_CLI'         => ['interfaces' => ['WPI']],
   'Demo/UDP_Client_CLI'         => ['interfaces' => ['WPI']],
   'Demo/UDP_Server_CLI'         => ['interfaces' => ['WPI']],
];
