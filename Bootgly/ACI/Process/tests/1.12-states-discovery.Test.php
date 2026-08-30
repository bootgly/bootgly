<?php

use Bootgly\ACI\Process\State;
use Bootgly\ACI\Process\States;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Process\States: locate/scan/authenticate discovery over qualified instance state (WPI and CLI shapes)',
   test: function () {
      $pidsDir = BOOTGLY_STORAGE_DIR . 'pids/';
      $id = 'StatesDiscoveryTest';
      $PID = posix_getpid();

      // ! A WPI-shaped instance (port qualifier) and a CLI-shaped one (PID qualifier),
      //   both flocked by this very process so authenticate() proves ownership
      $Server = new State($id, '7001');
      $Console = new State($id, '7002');
      $held = $Server->lock(LOCK_EX | LOCK_NB) && $Console->lock(LOCK_EX | LOCK_NB);
      $Server->save([
         'master' => $PID, 'workers' => [], 'started' => time(),
         'type' => 'WPI', 'host' => '127.0.0.1', 'port' => 7001,
      ]);
      $Console->save([
         'master' => $PID, 'workers' => [], 'started' => time(),
         'type' => 'CLI', 'project' => 'States Discovery',
      ]);

      // # locate(): both shapes pass validation
      $server = States::locate($id, '7001');
      yield assert(
         assertion: $held && $server !== null && $server['type'] === 'WPI' && $server['port'] === 7001,
         description: 'a WPI-shaped instance locates with host/port validated'
      );

      $console = States::locate($id, '7002');
      yield assert(
         assertion: $console !== null && $console['type'] === 'CLI'
            && isSet($console['host']) === false
            && ($console['project'] ?? null) === 'States Discovery',
         description: 'a CLI-shaped instance locates without host/port, extra keys passing through'
      );

      // # A WPI shape missing its address is refused
      $Server->save([
         'master' => $PID, 'workers' => [], 'started' => time(), 'type' => 'WPI',
      ]);
      yield assert(
         assertion: States::locate($id, '7001') === null,
         description: 'type WPI without host/port fails the shape validation'
      );
      $Server->save([
         'master' => $PID, 'workers' => [], 'started' => time(),
         'type' => 'WPI', 'host' => '127.0.0.1', 'port' => 7001,
      ]);

      // # An unauthenticated master is refused
      $Console->save([
         'master' => 99999999, 'workers' => [], 'started' => time(), 'type' => 'CLI',
      ]);
      yield assert(
         assertion: States::locate($id, '7002') === null,
         description: 'a master PID that does not hold the flock is refused'
      );
      $Console->save([
         'master' => $PID, 'workers' => [], 'started' => time(),
         'type' => 'CLI', 'project' => 'States Discovery',
      ]);

      // # scan(): every live qualified instance, keyed by qualifier
      $instances = States::scan($id);
      yield assert(
         assertion: array_map('strval', array_keys($instances)) === ['7001', '7002'],
         description: 'scan() enumerates the qualified instances — got '
            . implode(', ', array_keys($instances))
      );

      // # authenticate(): direct re-check by qualifier
      yield assert(
         assertion: States::authenticate($id, '7001', $PID) === true
            && States::authenticate($id, '7001', 99999999) === false,
         description: 'authenticate() accepts the flock holder and refuses anyone else'
      );

      // # A tombstoned instance disappears from discovery
      $Console->clean();
      yield assert(
         assertion: States::locate($id, '7002') === null
            && array_map('strval', array_keys(States::scan($id))) === ['7001'],
         description: 'a cleaned (tombstoned) instance no longer locates or scans'
      );

      // ! Cleanup
      $Server->clean();
      foreach (['7001', '7002'] as $qualifier) {
         @unlink("$pidsDir$id.$qualifier.json");
         @unlink("$pidsDir$id.$qualifier.command");
         @unlink("$pidsDir$id.$qualifier.lock");
      }
   }
);
