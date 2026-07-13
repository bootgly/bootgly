<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API;


use const BOOTGLY_ENVIRONMENT;

use Bootgly\API\Environments;
use Bootgly\API\Workables\Server;


// @ Workables\Server
// Deployment environment (BOOTGLY_ENVIRONMENT constant) -> typed enum on the Server SAPI.
Server::$Environment = Environments::fetch(BOOTGLY_ENVIRONMENT);
