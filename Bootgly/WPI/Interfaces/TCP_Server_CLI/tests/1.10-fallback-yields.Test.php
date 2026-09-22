<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\TCP_Server_CLI;


use const BOOTGLY_STORAGE_DIR;
use function array_diff;
use function array_filter;
use function array_values;
use function assert;
use function class_exists;
use function clearstatcache;
use function count;
use function explode;
use function file_get_contents;
use function filesize;
use function is_dir;
use function is_link;
use function rmdir;
use function scandir;
use function str_contains;
use function unlink;

use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Logs\Handlers;
use Bootgly\ACI\Logs\Handlers\File as FileHandler;
use Bootgly\ACI\Logs\Handlers\Memory as MemoryHandler;
use Bootgly\ACI\Logs\Logger;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Interfaces\TCP_Server_CLI as TCPServer;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Configs as TCPConfigs;
use Bootgly\WPI\Interfaces\UDP_Server_CLI as UDPServer;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Configs as UDPConfigs;


if (! class_exists(TCPServerCLIFallbackProbe::class, false)) {
   class TCPServerCLIFallbackProbe extends TCPServer
   {
      public function store (bool $starting = false): void
      {
         parent::store($starting);
      }
   }
   class UDPServerCLIFallbackProbe extends UDPServer
   {
      public function store (bool $starting = false): void
      {
         parent::store($starting);
      }
   }
}


/**
 * The Daemon fallback sink yields to a sink registered before start().
 *
 * store() decides at configure() that a Daemon with no sinks configured
 * persists to `storage/logs/{channel}.log`, but a platform shell registers
 * its own sinks after that — the Web App pushes a File sink at that very path
 * (`Logger::$Sinks ??= new Handlers; ->push(new File(...))`) between
 * configure() and start(). Two File handlers on one path wrote every record
 * twice (LOGS-10). At start() the fallback and its notice give way to what
 * was registered since; with nothing registered the fallback is confirmed
 * and the notice is start()'s first record; sinks a project configured itself
 * are never touched. Launched as the current user — the launch that keeps its
 * identity; the root launch's hold takes the same turn in 1.6 (leg K).
 */
return new Test(
   description: 'The Daemon fallback sink yields — notice included — to a sink registered between configure() and start(); confirmed otherwise, and project sinks are kept',
   test: function () {
      $base = Temporaries::reserve('tcp-fallback-yields');
      $Previous = Logger::$Sinks;
      $display = Display::$segments;
      $lines = static fn (string $file): array => array_values(array_filter(
         explode("\n", (string) @file_get_contents($file))
      ));
      $count = static fn (array $records, string $needle): int => count(array_filter(
         $records,
         static fn (string $record): bool => str_contains($record, $needle)
      ));
      $entries = static fn (string $directory): array => is_dir($directory)
         ? array_values(array_diff((array) @scandir($directory), ['.', '..']))
         : [];
      $purge = static function (string $path) use (&$purge): void {
         if (is_dir($path) && is_link($path) === false) {
            foreach (array_diff((array) @scandir($path), ['.', '..']) as $child) {
               $purge("$path/$child");
            }
            @rmdir($path);
            return;
         }
         @unlink($path);
      };

      try {
         Display::show(Display::NONE);

         // @@ A) The Web App's shape, on both server interfaces: the fallback
         //       installed at configure() yields to the sink pushed beside it
         foreach ([
            'TCP' => [TCPServerCLIFallbackProbe::class, 65011, 'TCP.Server.CLI'],
            'UDP' => [UDPServerCLIFallbackProbe::class, 65012, 'UDP.Server.CLI'],
         ] as $name => [$class, $port, $channel]) {
            $fallback = "$base/$name-fallback";
            $app = "$base/$name-app";
            Logger::$Sinks = null;
            MemoryHandler::release();
            $Probe = new $class(Modes::Daemon);
            $Probe->configure(
               $class === UDPServerCLIFallbackProbe::class
                  ? new UDPConfigs(host: '127.0.0.1', port: $port, workers: 1)
                  : new TCPConfigs(host: '127.0.0.1', port: $port, workers: 1)
            );
            // ! The fallback is in place, alone — retargeted at the fixture so
            //   whatever it ever writes is measurable
            $Fallback = Logger::$Sinks?->Handlers[0] ?? null;
            $installed = $Fallback instanceof FileHandler
               && count(Logger::$Sinks->Handlers) === 1
               && str_contains($Fallback->path, 'logs/{channel}.log');
            if ($Fallback instanceof FileHandler) {
               $Fallback->path = "$fallback/{channel}.log";
            }
            // @ Web\App::start(): its own File sink, pushed beside the fallback
            $App = new FileHandler("$app/{channel}.log");
            Logger::$Sinks ??= new Handlers;
            Logger::$Sinks->push($App);
            // @ Server::start(): store() again, then the first record
            $Probe->store(starting: true);
            $Sinks = Logger::$Sinks?->Handlers ?? [];
            $Probe->Logger->log(notice: 'first-record-of-start');
            $records = $lines("$app/$channel.log");

            yield assert(
               assertion: $installed
                  && count($Sinks) === 1 && $Sinks[0] === $App
                  && $entries($fallback) === []
                  && count($records) === 1
                  && $count($records, 'first-record-of-start') === 1
                  && $count($records, 'No global log sinks') === 0,
               description: "$name: configure() installs the fallback alone; a sink registered before start() takes its place — "
                  . 'the fallback never writes, no notice, and the first record lands once in the newcomer '
                  . '(sinks=' . count($Sinks) . ', fallback entries=' . count($entries($fallback)) . ', records=' . count($records) . ')'
            );
         }

         // @@ B) Nothing registered in between: the fallback is confirmed at
         //       start(), and the notice — deferred until then — is its first record
         $fallback = "$base/bare-fallback";
         Logger::$Sinks = null;
         MemoryHandler::release();
         // ! Where the fallback would write before it is retargeted: unchanged
         //   through configure() — twice, as a platform shell refining its
         //   Configs does — proves nothing is written before start()
         $original = BOOTGLY_STORAGE_DIR . 'logs/TCP.Server.CLI.log';
         clearstatcache(true, $original);
         $before = @filesize($original);
         $Probe = new TCPServerCLIFallbackProbe(Modes::Daemon);
         $Probe->configure(new TCPConfigs(host: '127.0.0.1', port: 65013, workers: 1));
         $Probe->configure(new TCPConfigs(host: '127.0.0.1', port: 65013, workers: 1));
         clearstatcache(true, $original);
         $silent = @filesize($original) === $before;
         $Fallback = Logger::$Sinks?->Handlers[0] ?? null;
         if ($Fallback instanceof FileHandler) {
            $Fallback->path = "$fallback/{channel}.log";
         }
         $Probe->store(starting: true);
         $Sinks = Logger::$Sinks?->Handlers ?? [];
         $Probe->Logger->log(notice: 'first-record-of-start');
         $records = $lines("$fallback/TCP.Server.CLI.log");

         yield assert(
            assertion: $Fallback instanceof FileHandler
               && $silent
               && count($Sinks) === 1 && $Sinks[0] === $Fallback
               && count($records) === 2
               && str_contains($records[0], 'No global log sinks configured')
               && str_contains($records[1], 'first-record-of-start'),
            description: 'nothing registered in between: the fallback stays, writes nothing before start() (a second configure() included), and its notice is '
               . 'the first record there, followed by the first record start() logs — each once '
               . '(sinks=' . count($Sinks) . ', records=' . count($records) . ')'
         );

         // @@ C) A project that configured its own sinks has no fallback: what
         //       the platform pushes beside them rides along, nothing yields
         $project = "$base/project";
         $app = "$base/project-app";
         $Own = new FileHandler("$project/{channel}.log");
         Logger::$Sinks = new Handlers;
         Logger::$Sinks->push($Own);
         MemoryHandler::release();
         $Probe = new TCPServerCLIFallbackProbe(Modes::Daemon);
         $Probe->configure(new TCPConfigs(host: '127.0.0.1', port: 65014, workers: 1));
         $App = new FileHandler("$app/{channel}.log");
         Logger::$Sinks ??= new Handlers;
         Logger::$Sinks->push($App);
         $Probe->store(starting: true);
         $Sinks = Logger::$Sinks?->Handlers ?? [];
         $Probe->Logger->log(notice: 'first-record-of-start');
         $own = $lines("$project/TCP.Server.CLI.log");
         $beside = $lines("$app/TCP.Server.CLI.log");

         yield assert(
            assertion: count($Sinks) === 2 && $Sinks[0] === $Own && $Sinks[1] === $App
               && count($own) === 1 && $count($own, 'first-record-of-start') === 1
               && count($beside) === 1 && $count($beside, 'first-record-of-start') === 1
               && $count($own, 'No global log sinks') === 0,
            description: 'a project-configured sink is kept beside the one registered later — no fallback, no notice, '
               . 'the record lands once in each (sinks=' . count($Sinks) . ', own=' . count($own) . ', beside=' . count($beside) . ')'
         );
      }
      finally {
         MemoryHandler::release();
         Logger::$Sinks = $Previous;
         Display::show($display);
         $purge($base);
      }
   }
);
