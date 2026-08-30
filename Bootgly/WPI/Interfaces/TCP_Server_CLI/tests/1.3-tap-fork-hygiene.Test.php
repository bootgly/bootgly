<?php

namespace Bootgly\WPI\Interfaces\TCP_Server_CLI;


use const STREAM_SOCK_DGRAM;
use const WUNTRACED;
use function assert;
use function fclose;
use function file_exists;
use function fread;
use function function_exists;
use function mkdir;
use function pcntl_fork;
use function pcntl_waitpid;
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
   description: 'Tap hub: a forked child drops inherited descriptors without disturbing the master hub',
   test: function () {
      if (function_exists('pcntl_fork') === false) {
         yield assert(
            assertion: true,
            description: 'pcntl unavailable — fork hygiene not exercisable here'
         );
         return;
      }

      $dir = sys_get_temp_dir() . '/bootgly-tapfork-' . uniqid();
      mkdir($dir, 0o700, true);
      $path = "$dir/probe.logs.sock";

      $Pipe = new IPCPipe(STREAM_SOCK_DGRAM);
      $Pipe->open();

      $Tap = new Tap($path, $Pipe);
      $Tap->open();

      $Session = stream_socket_client("unix://$path");
      stream_set_blocking($Session, false); // @phpstan-ignore-line
      $Tap->pump();

      // @ The child inherits the hub and must only close its copies — no unlink
      $PID = pcntl_fork();
      if ($PID === 0) {
         $Tap->drop();
         exit(0);
      }
      pcntl_waitpid($PID, $status, WUNTRACED);

      yield assert(
         assertion: file_exists($path) === true,
         description: 'the child exit leaves the socket pathname in place (drop, not close)'
      );

      // # The master hub still serves after the child dropped its copies
      $frame = "{\"message\":\"post-fork\"}\n";
      $Pipe->write($frame, strlen($frame));
      $Tap->pump();
      usleep(10000);
      $bytes = (string) fread($Session, 65536); // @phpstan-ignore-line

      yield assert(
         assertion: $bytes === $frame,
         description: 'the attached session keeps receiving frames from the master'
      );

      // @ Cleanup
      fclose($Session); // @phpstan-ignore-line
      $Tap->close();
      @unlink($path);
      @rmdir($dir);
   }
);
