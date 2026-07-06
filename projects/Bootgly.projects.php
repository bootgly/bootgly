<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
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
   'Benchmark/WS_Server_CLI'     => ['interfaces' => ['WPI']],
   'Demo/CLI'                    => ['interfaces' => ['CLI']],
   'Demo/HTTP2-HTTP_Server_CLI'  => ['interfaces' => ['WPI']],
   'Demo/HTTP_Client_CLI'        => ['interfaces' => ['WPI']],
   'Demo/HTTP_Server_CLI'        => ['interfaces' => ['WPI'], 'default' => true],
   'Demo/HTTPS_Client_CLI'       => ['interfaces' => ['WPI']],
   'Demo/HTTPS_Server_CLI'       => ['interfaces' => ['WPI']],
   'Demo/Queue-HTTP_Server_CLI'  => ['interfaces' => ['WPI']],
   'Demo/TCP_Client_CLI'         => ['interfaces' => ['WPI']],
   'Demo/TCP_Server_CLI'         => ['interfaces' => ['WPI']],
   'Demo/UDP_Client_CLI'         => ['interfaces' => ['WPI']],
   'Demo/UDP_Server_CLI'         => ['interfaces' => ['WPI']],
   'Demo/WS_Client_CLI'          => ['interfaces' => ['WPI']],
   'Demo/WS_Server_CLI'          => ['interfaces' => ['WPI']],
];
