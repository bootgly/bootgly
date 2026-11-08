<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\MCP\tests;


use const BOOTGLY_ROOT_DIR;
use const BOOTGLY_WORKING_DIR;
use function crc32;
use function define;
use function defined;

use Bootgly\ABI\Resources\Cache;
use Bootgly\ABI\Resources\Cache\Drivers\Shared;
use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Tests\Suite;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;


/**
 * MCP over Streamable HTTP (`Router\MCP`) against a live test-mode server
 * with TWO workers: a session opened on one worker must be resumed on the
 * other (the session store crosses workers). Each spec builds its own
 * transport with its own store prefix, in a shared-memory segment of the
 * specs' own (removed at teardown).
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

      HTTP_Server_CLI::pretest($Suite, 'MCP', __DIR__);

      $HTTP_Server_CLI = new HTTP_Server_CLI(Mode: Modes::Test);
      $HTTP_Server_CLI->configure(new HTTP_Server_CLI\Configs(
         host: '0.0.0.0',
         // ? 8105 — 8081-8097 belong to the other E2E suites, 8098 to
         //   ACME_Challenge (and the E2E upstream fixture), 8099 to ACME_Swap,
         //   8100 to ACME_E2E, 8101 to E2E_DualStack, 8102 is the E2E TLS
         //   upstream fixture, 8103 is E2E_Idle and 8104 is E2E_Deferred.
         port: 8105,
         workers: 2,
         health: '/health'
      ));

      $HTTP_Server_CLI->start();

      $HTTP_Server_CLI->Commands->command('test');

      // @ Teardown: terminate workers and release state lock so the next
      //   suite running in the same master PHP process can bind/lock cleanly.
      $HTTP_Server_CLI->Process->stopping = true;
      $HTTP_Server_CLI->Process->Children->terminate();
      $HTTP_Server_CLI->Process->State->clean();

      // @ The specs' session stores and the sweep stamp of the one spec that
      //   fills its store: shared-memory segments removed so no run leaves
      //   state behind (never the project's own stores or stamps)
      $scope = BOOTGLY_WORKING_DIR;
      $filled = 0x4d435054;
      $stamps = crc32("bootgly.mcp.sweeps\0{$scope}\0shared\0{$filled}\0mcp-spec-1.11:") & 0x7fffffff;
      foreach ([0x4d435053 => 16_777_216, 0x4d435054 => 32_768, $stamps => 65_536] as $segment => $size) {
         $Store = new Cache(['driver' => 'shared', 'segment' => $segment, 'size' => $size]);
         // ! Attach (a cheap read) — destroy() removes an attached segment only
         $Store->check('teardown');
         $Driver = $Store->Driver;
         if ($Driver instanceof Shared) {
            $Driver->destroy();
         }
      }

      return true;
   },
   suiteName: __NAMESPACE__,
   // * Data
   tests: [
      '1.1-flow_across_workers',
      '1.2-session_rules',
      '1.3-session_expiry',
      '1.4-accept_matrix',
      '1.5-body_limit',
      '1.6-origin_validation',
      '1.7-get_stream',
      '1.8-methods',
      '1.9-delete',
      '1.10-construction',
      '1.11-store_capacity',
   ]
);
