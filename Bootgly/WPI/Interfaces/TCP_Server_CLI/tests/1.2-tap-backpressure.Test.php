<?php

namespace Bootgly\WPI\Interfaces\TCP_Server_CLI;


use const STREAM_SOCK_DGRAM;
use function assert;
use function fclose;
use function fread;
use function microtime;
use function mkdir;
use function rmdir;
use function round;
use function str_repeat;
use function stream_set_blocking;
use function stream_socket_client;
use function strlen;
use function substr_count;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use Bootgly\ABI\IO\IPC\Pipe as IPCPipe;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Tap hub: a stalled session drops frames alone — the pump never blocks, healthy sessions stay complete',
   test: function () {
      $dir = sys_get_temp_dir() . '/bootgly-tapbp-' . uniqid();
      mkdir($dir, 0o700, true);
      $path = "$dir/probe.logs.sock";

      $Pipe = new IPCPipe(STREAM_SOCK_DGRAM);
      $Pipe->open();

      $Tap = new Tap($path, $Pipe);
      $Tap->open();

      // ! One healthy session (drained every pass) and one stalled (never read)
      $Healthy = stream_socket_client("unix://$path");
      $Stalled = stream_socket_client("unix://$path");
      stream_set_blocking($Healthy, false); // @phpstan-ignore-line
      stream_set_blocking($Stalled, false); // @phpstan-ignore-line
      $Tap->pump();

      // @@ Push ~2 MiB of frames through — far past any kernel buffer + pending cap
      $frame = "{\"payload\":\"" . str_repeat('x', 32768) . "\"}\n";
      $length = strlen($frame);
      $received = 0;
      $start = microtime(true);
      for ($round = 0; $round < 64; $round++) {
         $Pipe->write($frame, $length);
         $Tap->pump();
         while (($bytes = fread($Healthy, 65536)) !== '' && $bytes !== false) { // @phpstan-ignore-line
            $received += substr_count($bytes, "\n");
         }
      }
      $elapsed = microtime(true) - $start;

      yield assert(
         assertion: $elapsed < 2.0,
         description: 'the pump never blocks on the stalled session — 64 rounds in '
            . round($elapsed * 1000) . 'ms'
      );

      // @ Drain the healthy leftovers
      $Tap->pump();
      while (($bytes = fread($Healthy, 65536)) !== '' && $bytes !== false) { // @phpstan-ignore-line
         $received += substr_count($bytes, "\n");
      }

      yield assert(
         assertion: $received === 64,
         description: "the healthy session received every frame despite the staller — got $received/64"
      );

      // @ Cleanup
      fclose($Healthy); // @phpstan-ignore-line
      fclose($Stalled); // @phpstan-ignore-line
      $Tap->close();
      @unlink($path);
      @rmdir($dir);
   }
);
