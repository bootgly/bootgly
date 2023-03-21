<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Web\TCP\Server\_\CLI;


use const Bootgly\HOME_DIR;

use Bootgly\Bootgly;
use Bootgly\CLI;
use Bootgly\CLI\Terminal\_\ {
   Logger\Logging
};
use Bootgly\Web\TCP\ {
   Server
};


class Terminal extends CLI\Terminal
{
   use Logging;


   public Server $Server;

   // ! Command
   // * Data
   public static array $commands = [
      // ! Server
      // @
      'status',
      // @ control
      'quit',
      'pause',
      'resume',
      'reload',
      // @ mode
      'monitor',
      // @ operations
      // TODO 'benchmark'
      'check',
      'debug',
      // TODO 'log'
      'test',
      // ! \ Connection
      'stats',
      'connections',
      // *
      'clear',
      'help'
   ];
   public static array $subcommands = [
      // ! Server
      // @ operations
      'check' => [
         'jit'
      ],
      'debug' => [
         'on',
         'off',
      ],
      // ! \ Connection
      'stats' => [
         'reset'
      ]
   ];

   // ***


   public function __construct (Server &$Server)
   {
      parent::__construct();
      $this->Server = $Server;
   }

   // ! Command<T>
   // @ Interact
   public function command (string $command) : bool
   {
      // TODO split command in subcommands by space

      return match ($command) {
         // ! Server
         // @
         'status' =>
            $this->Server->{'@status'} && true,
         // @ control
         'quit' =>
            $this->log(
               '@\;Stopping ' . $this->Server->Process->children . ' worker(s)... ',
               self::LOG_WARNING_LEVEL
            )
            && $this->Server->Process->sendSignal(SIGINT)
            && false,
         'pause' =>
            $this->Server->Process->sendSignal(SIGTSTP) && false,
         'resume' =>
            $this->Server->Process->sendSignal(SIGCONT) && false,
         'reload' =>
            $this->Server->Process->sendSignal(SIGUSR2, master: false)
            && true,
         // TODO restart command
         // @ mode
         'monitor' =>
            $this->Server->mode = Server::MODE_MONITOR,
         // @ operations
         // TODO 'benchmark'
         'check jit' =>
            $this->log(
               (function_exists('opcache_get_status') && @opcache_get_status()['jit']['enabled'])
               ? 'JIT enabled' : 'JIT disabled'
            ) && true,
         'debug on' =>
            Bootgly::debug(true) && true,
         'debug off' =>
            Bootgly::debug(false) && true,
         // TODO 'log'
         'test' => // TODO use CLI wizard to choose the tests
            $this->saveCommand('test init')
            && $this->Server->Process->sendSignal(SIGUSR1, master: false, children: true)

            && $this->saveCommand('test')
            && $this->Server->Process->sendSignal(SIGUSR1, master: true, children: false)

            && $this->saveCommand('test end')
            && $this->Server->Process->sendSignal(SIGUSR1, master: false, children: true) && true,

         // ! \ Connection
         'stats' =>
            $this->Server->Process->sendSignal(SIGIO, master: false) && false,
         'stats reset' =>
            $this->saveCommand($command, 'Connections')
            && $this->Server->Process->sendSignal(SIGUSR1, master: false) && true,

         'connections' =>
            $this->Server->Process->sendSignal(SIGIOT, master: false) && false,
         // *
         'clear' =>
            $this->clear() && true,
         'help' =>
            $this->help() && true,

         default => passthru($command) !== false && true
      };
   }
   public function saveCommand (string $command, string $context = ''): bool
   {
      $file = HOME_DIR . '/workspace/server.command';

      $line = $command . ':' . $context . PHP_EOL;

      if (file_put_contents($file, $line, FILE_APPEND) === false) {
         return false;
      }

      return true;
   }
   public function help ()
   {
      $this->log(<<<'OUTPUT'
      @\;======================================================================
      @:i: `status` @;      = Show info about status of server;

      @:i: `stop` @;        = Stop the Server and all workers;
      @:i: `pause` @;       = Pause the Server and all workers;
      @:i: `resume` @;      = Resume the Server and all workers;
      @:i: `reload` @;      = Reload the Server and all workers;

      @:i: `monitor` @;     = Enter in monitor mode;

      @:i: `check jit` @;   = Check if JIT is enabled;
      @:i: `debug on` @;    = Enable debug log output;
      @:i: `debug off` @;   = Disable debug log output;
      @:i: `test` @;        = Run Server test suites;

      @:i: `stats` @;       = Show stats about connections / data per worker;
      @:i: `stats reset` @; = Reset stats about connections / data per worker;
      @:i: `connections` @; = Show info about active connections remote peers;

      @:i: `clear` @;       = Clear this console screen;
      @:i: `help` @;        = Show this help message;
      ========================================================================

      OUTPUT);

      return true;
   }
}
