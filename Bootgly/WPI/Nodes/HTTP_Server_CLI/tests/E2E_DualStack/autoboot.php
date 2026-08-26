<?php

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\tests\E2E_DualStack;


use const BOOTGLY_ROOT_DIR;
use function define;
use function defined;

use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Tests\Suite;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;


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

      HTTP_Server_CLI::pretest($Suite, 'E2E_DualStack');

      $HTTP_Server_CLI = new HTTP_Server_CLI(Mode: Modes::Test);
      $HTTP_Server_CLI->configure(
         // ! Dual-stack listener — Bootgly always builds TCP listeners with
         //   'ipv6_v6only' => false, so IPv4 hops land on this socket as
         //   IPv4-mapped '::ffff:a.b.c.d' peers (MW-6 regression surface)
         host: '[::]',
         // ? 8101 — 8081-8097 belong to the other E2E suites, 8098 to
         //   ACME_Challenge (and the E2E upstream fixture), 8099 to ACME_Swap,
         //   8100 is referenced by Fuzz fixtures and 8102 is the E2E TLS
         //   upstream fixture.
         port: 8101,
         workers: 1,
         health: '/health'
      );

      $HTTP_Server_CLI->start();

      $HTTP_Server_CLI->Commands->command('test');

      // @ Teardown: terminate workers and release state lock so the next
      //   suite running in the same master PHP process can bind/lock cleanly.
      $HTTP_Server_CLI->Process->stopping = true;
      $HTTP_Server_CLI->Process->Children->terminate();
      $HTTP_Server_CLI->Process->State->clean();

      return true;
   },
   suiteName: __NAMESPACE__,
   // * Data
   tests: [
      '1.1-ipv4_mapped_peer_trust'
   ]
);
