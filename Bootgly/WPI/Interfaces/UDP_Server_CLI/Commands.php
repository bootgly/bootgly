<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\UDP_Server_CLI;


use const E_ALL;
use const FILE_APPEND;
use const PHP_EOL;
use const SIGIO;
use const SIGIOT;
use const SIGUSR1;
use const SIGUSR2;
use function error_reporting;
use function file_put_contents;
use function function_exists;
use function ini_set;
use function opcache_get_status;

use const Bootgly\CLI;
use Bootgly\ACI\Logs\Logger;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\CLI;
use Bootgly\WPI\Interfaces\UDP_Server_CLI as Server;


class Commands extends CLI\Terminal
{
   // * Data
   public Logger $Logger {
      get {
         if ( isSet($this->Logger) === false ) {
            $this->Logger = new Logger(channel: static::class);
         }

         return $this->Logger;
      }
   }


   public Server $Server;

   // ! Command
   // * Data
   /** @var array<string> */
   public static array $commands = [
      // ! Server
      // @
      'status',
      // @ control
      'stop',
      'pause',
      'resume',
      'reload',
      // @ mode
      'monitor',
      // @ operations
      'check',
      'error',
      // ? `test` is deliberately absent: it is driven programmatically by the
      //   `Modes::Test` suite autoboots, never typed at a live server's prompt.
      // ! \ Connection
      'stats',
      'connections',
      // *
      'clear',
      'help'
   ];
   /** @var array<string,array<string>> */
   public static array $subcommands = [
      // ! Server
      // @ operations
      'check' => [
         'jit'
      ],
      'error' => [
         'on',
         'off',
      ],
      // ! \ Connection
      'stats' => [
         'reset'
      ]
   ];


   public function __construct (Server &$Server)
   {
      parent::__construct();
      $this->Server = $Server;
   }

   // ! Command<T>
   // @ Interact
   public function command (string $command): bool
   {
      return match ($command) {
         // ! Server
         // @
         'status' =>
            CLI->Commands->find('status', From: $this->Server)?->run() && true,
         // @ control
         // ? In-process, like the TCP twin: a signal raised at ourselves is
         //   only dispatched at the top of the NEXT console iteration, one
         //   prompt behind the operator. `pause()`/`resume()` own their
         //   worker signal legs.
         'stop' =>
            $this->stop(),
         'pause' =>
            $this->Server->pause() && false,
         'resume' =>
            $this->Server->resume() && false,
         // ? A `Modes::Test` master IS the suite-runner process: reloading it
         //   would drain the workers and exec-replace the runner itself.
         'reload' => $this->Server->Mode === Modes::Test
            ? true
            // @ Signal the MASTER (children: false) — reload() is master-level
            //   (`handle()` ignores SIGUSR2 in workers). The previous
            //   `master: false` made this command a complete no-op.
            : $this->Server->Process->Signals->send(SIGUSR2, children: false)
            && true,
         // @ mode
         'monitor' =>
            ($this->Server->Mode = Modes::Monitor) && true, // @phpstan-ignore-line
         // @ operations
         'check jit' =>
            $this->Logger->log(
               // @phpstan-ignore-next-line
               debug: (function_exists('opcache_get_status') && @opcache_get_status()['jit']['enabled'])
               ? 'JIT enabled' : 'JIT disabled'
            ) && true,
         'error on' =>
            error_reporting(E_ALL) && ini_set('display_errors', 'On') && true,
         'error off' =>
            error_reporting(0) && ini_set('display_errors', 'Off') && true,
         // ? Not an operator command — see the TCP twin. Only a `Modes::Test`
         //   server may drive its workers through the test sequence.
         'test' => $this->Server->Mode !== Modes::Test
            ? true
            : (
            $this->saveCommand('test init')
            && $this->Server->Process->Signals->send(SIGUSR1, master: false, children: true)

            && $this->saveCommand('test')
            && $this->Server->Process->Signals->send(SIGUSR1, master: true, children: false)

            && $this->saveCommand('test end')
            && $this->Server->Process->Signals->send(SIGUSR1, master: false, children: true) && true // @phpstan-ignore-line
            ),

         // ! \ Connection
         'stats' =>
            $this->Server->Process->Signals->send(SIGIO, master: false) && false,
         'stats reset' =>
            $this->saveCommand($command, 'Connections')
            && $this->Server->Process->Signals->send(SIGUSR1, master: false) && true,

         'connections' =>
            $this->Server->Process->Signals->send(SIGIOT, master: false) && false,
         // *
         'clear' =>
            $this->clear() && true, // @phpstan-ignore-line
         'help' =>
            $this->help() && true, // @phpstan-ignore-line

         default => true
      };
   }
   /** Stop the server in-process; only a `Modes::Test` server survives it. */
   private function stop (): bool
   {
      $this->Server->stop();

      // : Reachable only in `Modes::Test`, where `stop()` returns control to
      //   the suite runner instead of exiting — end the interaction.
      return false;
   }
   public function saveCommand (string $command, string $context = ''): bool
   {
      $file = $this->Server->Process->State->commandFile;

      $line = $command . ':' . $context . PHP_EOL;

      if (file_put_contents($file, $line, FILE_APPEND) === false) {
         return false;
      }

      return true;
   }
   public function help (): true
   {
      $this->Logger->log(debug: <<<'OUTPUT'
      @\;======================================================================
      @:i: `status` @;      = Show info about status of server;

      @:i: `stop` @;        = Stop the Server and all workers;
      @:i: `pause` @;       = Pause the Server and all workers;
      @:i: `resume` @;      = Resume the Server and all workers;
      @:i: `reload` @;      = Reload the Server and all workers;

      @:i: `monitor` @;     = Enter in monitor mode;

      @:i: `check jit` @;   = Check if JIT is enabled;
      @:i: `error on` @;    = Enable PHP error reporting;
      @:i: `error off` @;   = Disable PHP error reporting;

      @:i: `stats` @;       = Show stats about datagrams / data per worker;
      @:i: `stats reset` @; = Reset stats about datagrams / data per worker;
      @:i: `connections` @; = Show info about known peers;

      @:i: `clear` @;       = Clear this console screen;
      @:i: `help` @;        = Show this help message;
      ========================================================================

      OUTPUT);

      return true;
   }
}
