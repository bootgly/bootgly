<?php

namespace Bootgly\WPI\Interfaces\TCP_Server_CLI;


use const STREAM_SOCK_DGRAM;
use function assert;
use function fclose;
use function file_exists;
use function fread;
use function mkdir;
use function rmdir;
use function stream_set_blocking;
use function stream_socket_client;
use function strlen;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use function usleep;

use Bootgly\ABI\IO\IPC\Pipe as IPCPipe;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Tap hub: attach arms once, every session receives every frame, last detach disarms once',
   test: function () {
      $dir = sys_get_temp_dir() . '/bootgly-tap-' . uniqid();
      mkdir($dir, 0o700, true);
      $path = "$dir/probe.logs.sock";

      $Pipe = new IPCPipe(STREAM_SOCK_DGRAM);
      $Pipe->open();

      $attaches = 0;
      $detaches = 0;
      $Tap = new Tap($path, $Pipe);
      $Tap->onAttach = static function () use (&$attaches): void {
         $attaches++;
      };
      $Tap->onDetach = static function () use (&$detaches): void {
         $detaches++;
      };

      yield assert(
         assertion: $Tap->open() === true,
         description: 'the tap binds its unix socket'
      );

      // ? Nobody attached: a pump does not drain the pipe (zero-cost invariant)
      $frame = "{\"message\":\"tap-frame\"}\n";
      $Pipe->write($frame, strlen($frame));
      $Tap->pump();
      yield assert(
         assertion: $attaches === 0 && $Pipe->read(65536) === $frame,
         description: 'with no sessions the pipe is left untouched'
      );

      // # Two sessions attach — one arm transition
      $A = stream_socket_client("unix://$path");
      $B = stream_socket_client("unix://$path");
      stream_set_blocking($A, false); // @phpstan-ignore-line
      stream_set_blocking($B, false); // @phpstan-ignore-line
      $Tap->pump();

      yield assert(
         assertion: $attaches === 1 && $detaches === 0 && $Tap->attached === 2,
         description: 'the 0→1 transition fires onAttach exactly once for two sessions'
      );

      // # A drained frame reaches BOTH sessions
      $Pipe->write($frame, strlen($frame));
      $Tap->pump();
      usleep(10000);
      $a = (string) fread($A, 65536); // @phpstan-ignore-line
      $b = (string) fread($B, 65536); // @phpstan-ignore-line
      yield assert(
         assertion: $a === $frame && $b === $frame,
         description: 'both attached sessions receive the same frame'
      );

      // # Last detach — one disarm transition
      fclose($A); // @phpstan-ignore-line
      $Tap->pump();
      yield assert(
         assertion: $detaches === 0 && $Tap->attached === 1,
         description: 'closing one of two sessions never disarms'
      );

      fclose($B); // @phpstan-ignore-line
      $Tap->pump();
      yield assert(
         assertion: $detaches === 1 && $Tap->attached === 0,
         description: 'the 1→0 transition fires onDetach exactly once'
      );

      // @ Cleanup
      $Tap->close();
      yield assert(
         assertion: file_exists($path) === false,
         description: 'close() removes the socket pathname'
      );
      @unlink($path);
      @rmdir($dir);
   }
);
