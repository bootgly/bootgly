<?php

namespace Bootgly\API\Security\Tests\JWTRemoteRedirectOpaqueTarget;


use const SIGTERM;
use function assert;
use function chdir;
use function fclose;
use function file_exists;
use function file_put_contents;
use function fopen;
use function fread;
use function function_exists;
use function fwrite;
use function getcwd;
use function getmypid;
use function is_resource;
use function json_encode;
use function mkdir;
use function pcntl_fork;
use function pcntl_waitpid;
use function posix_kill;
use function posix_mkfifo;
use function rmdir;
use function str_contains;
use function stream_socket_accept;
use function stream_socket_get_name;
use function stream_socket_server;
use function strrpos;
use function substr;
use function sys_get_temp_dir;
use function unlink;
use function usleep;
use RuntimeException;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Security\JWT\Failures;
use Bootgly\API\Security\JWT\Remote;


$supported = function_exists('pcntl_fork') && function_exists('posix_mkfifo');


// ? `Location: https:keys.json` has a scheme and no authority. PHP's stream
//   wrappers read it as a file relative to the working directory, so a key-set
//   fetch that followed it would open a local file. The shared URI resolver
//   refuses it: the fetch fails and nothing local is ever opened.
return new Test(
   description: 'JWT: a redirect to an opaque target ends the fetch without opening a local file',
   skip: $supported === false,
   test: function () {
      $directory = sys_get_temp_dir() . '/jwt143-' . getmypid();
      $fifo = "{$directory}/https:keys.json";
      $marker = "{$directory}/opened";
      $cwd = (string) getcwd();
      $PIDs = [];

      try {
         @mkdir($directory, 0700, true);
         if (posix_mkfifo($fifo, 0600) === false) {
            throw new RuntimeException('JWT opaque-target fixture could not create its FIFO.');
         }

         // ! A writer that records whether anything ever opened the FIFO for reading
         $Writer = pcntl_fork();
         if ($Writer < 0) {
            throw new RuntimeException('JWT opaque-target fixture could not fork its writer.');
         }
         if ($Writer === 0) {
            // ! Serves every reader until it is killed — a fetch that loops on the
            //   file must fail its assertion, never hang on a writer-less FIFO
            while (true) {
               $Handle = @fopen($fifo, 'w');
               if (is_resource($Handle) === false) {
                  exit(0);
               }
               file_put_contents($marker, 'opened');
               @fwrite($Handle, '{"keys":[]}');
               fclose($Handle);
            }
         }
         $PIDs[] = $Writer;

         // ! An origin that answers the key-set request with the opaque target
         $Listener = @stream_socket_server('tcp://127.0.0.1:0', $errorCode, $error);
         if (is_resource($Listener) === false) {
            throw new RuntimeException("JWT opaque-target listener failed: {$errorCode} {$error}");
         }
         $address = (string) stream_socket_get_name($Listener, false);
         $port = (int) substr($address, strrpos($address, ':') + 1);
         $Origin = pcntl_fork();
         if ($Origin < 0) {
            throw new RuntimeException('JWT opaque-target fixture could not fork its origin.');
         }
         if ($Origin === 0) {
            $Peer = @stream_socket_accept($Listener, 5);
            if (is_resource($Peer)) {
               $head = '';
               while (str_contains($head, "\r\n\r\n") === false) {
                  $chunk = @fread($Peer, 8192);
                  if ($chunk === false || $chunk === '') {
                     break;
                  }
                  $head .= $chunk;
               }
               @fwrite($Peer, "HTTP/1.1 302 Found\r\nLocation: https:keys.json\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
               usleep(50000);
               fclose($Peer);
            }
            exit(0);
         }
         $PIDs[] = $Origin;
         fclose($Listener);

         // @ Fetch from inside the directory the opaque target names a file in
         chdir($directory);
         $Remote = new Remote("http://127.0.0.1:{$port}/keys", ttl: 0, insecure: true);
         $Remote->timeout = 2;
         $Remote->redirects = 2;
         $result = $Remote->fetch();
         chdir($cwd);
         usleep(100000);
         $opened = file_exists($marker);
      }
      finally {
         chdir($cwd);
         foreach ($PIDs as $PID) {
            posix_kill($PID, SIGTERM);
            pcntl_waitpid($PID, $status);
         }
         @unlink($fifo);
         @unlink($marker);
         @rmdir($directory);
      }

      yield assert(
         assertion: $result === Failures::Network && $opened === false,
         description: 'The fetch fails and the local file is never opened: '
            . json_encode(['result' => $result instanceof Failures ? $result->name : 'KeySet', 'opened' => $opened])
      );
   }
);
