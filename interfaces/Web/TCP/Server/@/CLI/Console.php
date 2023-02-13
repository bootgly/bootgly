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
use Bootgly\Web\TCP\ {
   Server
};


class Console extends CLI\Console
{
   public Server $Server;

   // ! Command
   // * Data
   public static array $commands = [
      'stop', 'exit', 'quit',
      'pause',
      'resume',
      'reload',

      'status',
      'monitor',
      'check',

      'stats',
      'peers', 'connections',

      'clear',
      'help'
   ];
   public static array $subcommands = [
      'stats' => [
         'reset'
      ],
      'check' => [
         'jit'
      ]
   ];

   // ***


   public function __construct (Server &$Server)
   {
      $this->Server = $Server;
   }

   // If return true -> interact imediatily in the next loop otherwise wait for output...
   public function interact () : bool
   {
      // @ Register CLI autocomplete function
      // Use TAB key as trigger
      readline_completion_function([$this, 'autocomplete']);

      // @ Get user input (read line)
      $input = readline('>_: ');

      // @ Sanitize user input
      $command = trim($input);

      if ($command === '') return true;

      // @ Clear last used command (returned by autocomplete function)
      self::$command = [];

      // @ Enable command history and add the last command to history
      // Use UP/DOWN key to access the history
      readline_add_history($command);

      // @ Execute command
      return $this->command($command);
   }

   // ! Command
   public function command (string $command) : bool
   {
      // TODO split command in subcommands by space

      return match ($command) {
         // ! Server
         'stop', 'exit', 'quit' =>
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

         'monitor' =>
            $this->Server->mode = Server::MODE_MONITOR,

         'status' =>
            $this->Server->{'@status'} && true,

         'debug on' =>
            Bootgly::debug(true) && true,
         'debug off' =>
            Bootgly::debug(false) && true,
         // TODO 'benchmark'
         'test' => // TODO use CLI wizard to choose the tests
            $this->saveCommand('test')
            && $this->Server->Process->sendSignal(SIGUSR1, master: true, children: false) && true,
         // TODO 'log'

         'check jit' => $this->log(
            (function_exists('opcache_get_status') && @opcache_get_status()['jit']['enabled'])
            ? 'JIT enabled' : 'JIT disabled') && true,

         // ! \Connection
         'stats' =>
         // $this->Server->Process->Signal->send(SIGIO, ...)
            $this->Server->Process->sendSignal(SIGIO, master: false) && false,
         'stats reset' =>
            $this->saveCommand($command, 'Connections')
            && $this->Server->Process->sendSignal(SIGUSR1, master: false) && true,

         'peers', 'connections' =>
            $this->Server->Process->sendSignal(SIGIOT, master: false) && false,

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

      $line = $command . ':' . $context;

      if (file_put_contents($file, $line) === false) {
         return false;
      }

      return true;
   }

   public function help ()
   {
      $this->log(<<<'OUTPUT'
      @\;======================================================================
      @:i: `stop` @;        = Stop the Server and all workers;
      @:i: `pause` @;       = Pause the Server and all workers;
      @:i: `resume` @;      = Resume the Server and all workers;
      @:i: `reload` @;      = Reload the Server and all workers;

      @:i: `monitor` @;     = Enter in monitor mode;

      @:i: `status` @;      = Show info about status of server;
      @:i: `stats` @;       = Show stats about connections / data per worker;
      @:i: `connections` @; = Show info about active connections remote peers;

      @:i: `clear` @;       = Clear this console screen;
      ========================================================================

      OUTPUT);

      return true;
   }
}
