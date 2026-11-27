<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\DNS_Client_CLI\tests\fixtures;


use function assert;
use function file_put_contents;
use function is_dir;
use function microtime;
use function rmdir;
use function scandir;
use function unlink;
use Closure;
use Fiber;
use ReflectionMethod;

use Bootgly\ACI\Events\Loops;
use Bootgly\ACI\Events\Scheduler;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\WPI\Events;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Client_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Nodes\DNS_Client_CLI;
use Bootgly\WPI\Nodes\DNS_Client_CLI\Configs;
use Bootgly\WPI\Nodes\DNS_Client_CLI\Query;
use Bootgly\WPI\Nodes\DNS_Client_CLI\State;


/**
 * Shared setup of the resolver specs: a fake nameserver in a temporary
 * directory, Configs that never read the machine's files, and a worker-like
 * event loop installed where the resolver looks for it.
 */
final class Harness
{
   // * Config
   public private(set) Nameserver $Nameserver;
   public private(set) string $directory;

   // * Data
   // ...

   // * Metadata
   /** The server's reactor before the first `loop()` (null: none was installed). */
   private static null|(Events & Loops & Scheduler) $Original = null;
   private static bool $saved = false;


   private function __construct (Nameserver $Nameserver, string $directory)
   {
      // * Config
      $this->Nameserver = $Nameserver;
      $this->directory = $directory;
   }

   /**
    * Start a fake nameserver in a fresh temporary directory.
    */
   public static function start (string $label): self
   {
      $directory = Temporaries::reserve($label);
      $Nameserver = Nameserver::start("{$directory}/");

      return new self($Nameserver, $directory);
   }

   /**
    * Configs pointing at the fake: no system file, no environment, a fixed host name, and a probe
    * that sees an IPv4 source only. `$values` override any named argument.
    *
    * @param array<string,mixed> $values
    */
   public function configure (array $values = []): Configs
   {
      $defaults = [
         'nameservers' => ['127.0.0.1'],
         'port' => $this->Nameserver->port,
         'search' => [],
         'timeout' => 1,
         'attempts' => 1,
         'resolv' => null,
         'hosts' => null,
         'nsswitch' => null,
         'environment' => [],
         'hostname' => 'box.example',
         'Probe' => static fn (int $family): null|string => $family === 4 ? '192.0.2.50' : null,
      ];

      return new Configs(...($values + $defaults));
   }

   /**
    * Write a file in the temporary directory; returns its path.
    */
   public function write (string $name, string $contents): string
   {
      $path = "{$this->directory}/{$name}";
      file_put_contents($path, $contents);

      return $path;
   }

   /**
    * A fresh worker-like event loop, installed as the server's reactor.
    */
   public static function loop (): Select
   {
      // ! Remember what the process had installed, once
      if (self::$saved === false) {
         self::$saved = true;
         self::$Original = isSet(TCP_Server_CLI::$Event) ? TCP_Server_CLI::$Event : null;
      }

      $Loop = new TCP_Client_CLI(TCP_Client_CLI::MODE_TEST)->Event;
      TCP_Server_CLI::$Event = $Loop;

      return $Loop;
   }

   /**
    * Run tasks as Fibers on a fresh loop until they all end or `$budget` seconds pass. A destroyed
    * loop cannot run again, so each call installs its own.
    *
    * @param list<Closure(): void> $Tasks
    * @param null|Closure(Select): void $Setup Called with the loop before the tasks start.
    *
    * @return list<Fiber<mixed,mixed,mixed,mixed>> The Fibers (a still-running one was cut by the budget).
    */
   public static function run (array $Tasks, float $budget = 5.0, null|Closure $Setup = null): array
   {
      $Loop = self::loop();
      if ($Setup !== null) {
         $Setup($Loop);
      }

      $Fibers = [];
      foreach ($Tasks as $Task) {
         $Fiber = new Fiber($Task);
         $Fibers[] = $Fiber;
         $value = $Fiber->start();
         if ($Fiber->isTerminated() === false) {
            $Loop->schedule($Fiber, $value);
         }
      }

      // ! Stop when every Fiber ended, or at the budget (a lost wake never hangs the suite)
      $deadline = microtime(true) + $budget;
      $Loop->repeat(0.005, static function () use ($Loop, $Fibers, $deadline): void {
         $running = 0;
         foreach ($Fibers as $Fiber) {
            if ($Fiber->isTerminated() === false) {
               $running++;
            }
         }
         if ($running === 0 || microtime(true) >= $deadline) {
            $Loop->destroy();
         }
      });
      $Loop->loop();

      return $Fibers;
   }

   /**
    * Put back the reactor the process had before the first `loop()`. A typed static cannot be
    * unset: when there was none, the last test loop stays installed (destroyed, never run).
    */
   public static function restore (): void
   {
      if (self::$Original !== null) {
         TCP_Server_CLI::$Event = self::$Original;
      }
   }

   /**
    * The shared state a node's configuration uses in this process (created on first use).
    */
   public static function state (DNS_Client_CLI $DNS): State
   {
      $State = new ReflectionMethod($DNS, 'load')->invoke($DNS, Query::clock());
      assert($State instanceof State);

      return $State;
   }

   /**
    * Stop the fake and remove the temporary directory.
    */
   public function stop (): void
   {
      $this->Nameserver->stop();
      self::wipe($this->directory);
      self::restore();
   }

   /**
    * Remove a tree.
    */
   private static function wipe (string $path): void
   {
      if (is_dir($path) === false) {
         @unlink($path);

         return;
      }
      foreach (scandir($path) ?: [] as $entry) {
         if ($entry !== '.' && $entry !== '..') {
            self::wipe("{$path}/{$entry}");
         }
      }
      @rmdir($path);
   }
}
