<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

// ! Worker configuration for `bootgly queue run`.
//   The worker process does NOT boot the web project, so it cannot autoload the
//   project's handler classes — load them here so `new SendEmail()` resolves.
require_once __DIR__ . '/SendEmail.php';

// : Queue config (must match what the HTTP server enqueues with — file driver, default store)
return [
   'driver'     => 'file',
   'attempts'   => 3,
   'visibility' => 60,
];
