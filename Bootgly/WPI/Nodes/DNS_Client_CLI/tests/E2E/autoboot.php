<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\DNS_Client_CLI\tests\E2E;


use const BOOTGLY_ROOT_DIR;
use function define;
use function defined;
use function function_exists;

use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Tests\Suite;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Nodes\DNS_Client_CLI\tests\fixtures\Harness;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;


require_once __DIR__ . '/../fixtures/Nameserver.php';
require_once __DIR__ . '/../fixtures/Harness.php';


/**
 * The resolver inside a live test-mode server worker: a lookup made from a
 * deferral parks on the worker's loop while the worker answers other
 * connections. The fake nameserver is forked before the server, so the
 * worker inherits its address (`$GLOBALS['BOOTGLY_E2E_DNS']`).
 */
return new Suite(
   // * Config
   autoBoot: function (Suite|null $Suite = null): true {
      Display::show(Display::NONE);

      // @ A project context is required for the process state lock.
      if ( ! defined('BOOTGLY_PROJECT') ) {
         $projectFile = BOOTGLY_ROOT_DIR . 'projects/Demo/HTTP_Server_CLI/HTTP_Server_CLI.Project.php';
         $TestProject = require $projectFile;
         define('BOOTGLY_PROJECT', $TestProject);
      }

      HTTP_Server_CLI::pretest($Suite, 'E2E', __DIR__);

      // ! The fake nameserver, before the server forks its worker
      $Harness = null;
      if (function_exists('pcntl_fork')) {
         $Harness = Harness::start('dns-e2e');
         $GLOBALS['BOOTGLY_E2E_DNS'] = $Harness;
      }

      $HTTP_Server_CLI = new HTTP_Server_CLI(Mode: Modes::Test);
      $HTTP_Server_CLI->configure(new HTTP_Server_CLI\Configs(
         host: '0.0.0.0',
         // ? 8106 — 8081-8097 belong to the other E2E suites, 8098 to
         //   ACME_Challenge (and the E2E upstream fixture), 8099 to ACME_Swap,
         //   8100 to ACME_E2E, 8101 to E2E_DualStack, 8102 is the E2E TLS
         //   upstream fixture, 8103 is E2E_Idle, 8104 is E2E_Deferred and
         //   8105 is the MCP suite.
         port: 8106,
         workers: 1,
         health: '/health'
      ));

      $HTTP_Server_CLI->start();

      $HTTP_Server_CLI->Commands->command('test');

      // @ Teardown: terminate workers and release state lock so the next
      //   suite running in the same master PHP process can bind/lock cleanly.
      $HTTP_Server_CLI->Process->stopping = true;
      $HTTP_Server_CLI->Process->Children->terminate();
      $HTTP_Server_CLI->Process->State->clean();

      $Harness?->stop();
      unset($GLOBALS['BOOTGLY_E2E_DNS']);

      return true;
   },
   suiteName: __NAMESPACE__,
   // * Data
   tests: [
      '1.1-lookup_parks_while_worker_answers',
      '1.2-dial_parks_while_worker_answers',
   ]
);
