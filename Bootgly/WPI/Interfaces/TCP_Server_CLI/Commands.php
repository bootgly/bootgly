<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\TCP_Server_CLI;


use const E_ALL;
use const LOCK_EX;
use const LOCK_SH;
use const LOCK_UN;
use const PHP_EOL;
use const SIGCONT;
use const SIGINT;
use const SIGIO;
use const SIGIOT;
use const SIGTSTP;
use const SIGUSR1;
use const SIGUSR2;
use function array_pad;
use function bin2hex;
use function chmod;
use function count;
use function error_reporting;
use function explode;
use function fclose;
use function fflush;
use function flock;
use function fopen;
use function fstat;
use function ftruncate;
use function function_exists;
use function fwrite;
use function ini_set;
use function is_array;
use function is_link;
use function is_resource;
use function is_string;
use function lstat;
use function opcache_get_status;
use function random_bytes;
use function rewind;
use function rtrim;
use function stream_get_contents;
use function strlen;
use function umask;

use const Bootgly\CLI;
use Bootgly\ACI\Logs\Logger;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI as Server;


class Commands extends CLI\Terminal
{
   public Logger $Logger {
      get {
         if ( isSet($this->Logger) === false ) {
            $this->Logger = new Logger(channel: static::class);
         }

         return $this->Logger;
      }
   }


   public Server $Server;
   /** Last command sequence consumed by this process. */
   private string $sequence = '';

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
      // TODO 'benchmark'
      'check',
      'error',
      // TODO 'log'
      'test',
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

   // ***


   public function __construct (Server &$Server)
   {
      parent::__construct();
      $this->Server = $Server;
   }

   // ! Command<T>
   // @ Interact
   public function command (string $command): bool
   {
      // TODO split command in subcommands by space

      return match ($command) {
         // ! Server
         // @
         'status' =>
            CLI->Commands->find('status', From: $this->Server)?->run() && true,
         // @ control
         'stop' =>
            $this->Logger->log(
               warning: '@\;Stopping ' . (string) count($this->Server->Process->Children->PIDs) . ' worker(s)... '
            )
            && $this->Server->Process->Signals->send(SIGINT)
            && false,
         'pause' =>
            $this->Server->Process->Signals->send(SIGTSTP) && false,
         'resume' =>
            $this->Server->Process->Signals->send(SIGCONT) && false,
         'reload' =>
            // @ Signal the MASTER (children: false) — it orchestrates the graceful
            //   re-exec in reload(); workers are driven from there via SIGQUIT.
            $this->Server->Process->Signals->send(SIGUSR2, children: false)
            && true,
         // TODO restart command
         // @ mode
         'monitor' =>
            ($this->Server->Mode = Modes::Monitor) && true, // @phpstan-ignore-line
         // @ operations
         // TODO 'benchmark'
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
         // TODO 'log'
         'test' => // TODO use CLI wizard to choose the tests
            $this->save('test init')
            && $this->Server->Process->Signals->send(SIGUSR1, master: false, children: true)

            && $this->save('test')
            && $this->Server->Process->Signals->send(SIGUSR1, master: true, children: false)

            && $this->save('test end')
            && $this->Server->Process->Signals->send(SIGUSR1, master: false, children: true) && true, // @phpstan-ignore-line

         // ! \ Connection
         'stats' =>
            $this->Server->Process->Signals->send(SIGIO, master: false) && false,
         'stats reset' =>
            $this->save($command, 'Connections')
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
   public function save (string $command, string $context = ''): bool
   {
      $line = bin2hex(random_bytes(8)) . "\t" . $command . ':' . $context . PHP_EOL;
      if (strlen($line) > 8192) {
         return false;
      }
      $Handle = $this->open();
      if ($Handle === false || flock($Handle, LOCK_EX) === false) {
         is_resource($Handle) && fclose($Handle);
         return false;
      }

      try {
         if (
            ftruncate($Handle, 0) === false
            || rewind($Handle) === false
            || fwrite($Handle, $line) !== strlen($line)
            || fflush($Handle) === false
         ) {
            return false;
         }
      }
      finally {
         flock($Handle, LOCK_UN);
         fclose($Handle);
      }

      return true;
   }

   /** Read one complete command under the same advisory lock as writers. */
   public function read (): null|string
   {
      $Handle = $this->open();
      if ($Handle === false || flock($Handle, LOCK_SH) === false) {
         is_resource($Handle) && fclose($Handle);
         return null;
      }

      try {
         $line = stream_get_contents($Handle, 8193);
      }
      finally {
         flock($Handle, LOCK_UN);
         fclose($Handle);
      }

      if (is_string($line) === false || $line === '' || strlen($line) > 8192) {
         return null;
      }
      [$sequence, $command] = array_pad(explode("\t", rtrim($line), 2), 2, '');
      if ($sequence === '' || $command === '' || $sequence === $this->sequence) {
         return null;
      }
      $this->sequence = $sequence;

      return $command;
   }

   /** Clear a stale command before workers inherit the channel. */
   public function erase (): bool
   {
      $Handle = $this->open();
      if ($Handle === false || flock($Handle, LOCK_EX) === false) {
         is_resource($Handle) && fclose($Handle);
         return false;
      }

      try {
         $erased = ftruncate($Handle, 0) && fflush($Handle);
      }
      finally {
         flock($Handle, LOCK_UN);
         fclose($Handle);
      }
      if ($erased) {
         $this->sequence = '';
      }

      return $erased;
   }

   /** @return resource|false Open the command inode without following links. */
   private function open (): mixed
   {
      $file = $this->Server->Process->State->commandFile;
      if (is_link($file)) {
         return false;
      }
      $before = @lstat($file);
      $previousMask = umask(0077);
      try {
         $Handle = $before === false
            ? @fopen($file, 'x+b')
            : @fopen($file, 'c+b');
         // @phpstan-ignore identical.alwaysTrue (intentional final-link race recheck)
         if ($Handle === false && $before === false && is_link($file) === false) {
            $before = @lstat($file);
            $Handle = @fopen($file, 'c+b');
         }
      }
      finally {
         umask($previousMask);
      }
      if ($Handle === false) {
         return false;
      }

      $opened = fstat($Handle);
      $after = @lstat($file);
      if (is_array($opened) === false || is_array($after) === false) {
         fclose($Handle);
         return false;
      }
      $same = $opened['dev'] === $after['dev']
         && $opened['ino'] === $after['ino']
         && ((int) $opened['mode'] & 0170000) === 0100000;
      if (is_array($before)) {
         $same = $same
            && $before['dev'] === $opened['dev']
            && $before['ino'] === $opened['ino'];
      }
      if ($same === false) {
         fclose($Handle);
         return false;
      }
      if (chmod($file, 0600) === false) {
         fclose($Handle);
         return false;
      }
      $secured = @lstat($file);
      $opened = fstat($Handle);
      if (
         is_array($secured) === false || is_array($opened) === false
         || $secured['dev'] !== $opened['dev']
         || $secured['ino'] !== $opened['ino']
         || ((int) $opened['mode'] & 0777) !== 0600
      ) {
         fclose($Handle);
         return false;
      }

      return $Handle;
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
