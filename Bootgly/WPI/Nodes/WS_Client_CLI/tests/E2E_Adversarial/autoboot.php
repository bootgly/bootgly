<?php

namespace Bootgly\WPI\Nodes\WS_Client_CLI\tests\E2E_Adversarial;


use const SIGKILL;
use const WNOHANG;
use function base64_encode;
use function fclose;
use function fread;
use function fwrite;
use function ord;
use function pack;
use function pcntl_fork;
use function pcntl_waitpid;
use function posix_getpid;
use function posix_getppid;
use function posix_kill;
use function preg_match;
use function sha1;
use function str_contains;
use function stream_socket_accept;
use function stream_socket_client;
use function stream_socket_server;
use function strlen;
use function substr;
use function trim;
use function usleep;
use RuntimeException;

use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Tests\Suite;


return new Suite(
   // * Config
   autoBoot: function (Suite|null $Suite = null): true {
      Display::show(Display::NONE);

      $port = 8096;

      // @ Fork a raw, deliberately-malformed WS server. For each connection it
      //   reads the client's selector and replies with one bad frame; the client
      //   must reject it (close the connection) instead of surfacing it.
      $parent = posix_getpid();
      $pid = pcntl_fork();
      if ($pid === 0) {
         $server = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
         if ($server === false) {
            exit(1);
         }
         // # One malformed server frame per selector.
         $masked = "\x81\x83" . 'abcd' . ('xyz' ^ substr('abcd', 0, 3));   // a server frame WITH a mask (illegal)
         $frames = [
            'masked'    => $masked,
            'rsv'       => "\xA1\x03xyz",                     // FIN + RSV2 + TEXT (RSV2 must be clear)
            'closecode' => "\x88\x02" . pack('n', 999),       // CLOSE with an illegal code (< 1000)
            'oversized' => "\x81\x7F" . pack('J', 2097152),   // TEXT, 64-bit length 2 MiB (> 1 MiB cap)
         ];

         while (true) {
            $conn = @stream_socket_accept($server, 1);
            if ($conn === false) {
               // ? Self-reap when the suite master is gone (e.g. a failing test
               //   exited the run before the `finally` teardown could kill us) —
               //   an orphaned accept loop would hold the CI step's pipes open.
               if (posix_getppid() !== $parent) {
                  exit(0);
               }

               continue;
            }
            // @ Read the upgrade (tolerates readiness probes that send nothing).
            $req = '';
            while (! str_contains($req, "\r\n\r\n")) {
               $b = fread($conn, 4096);
               if ($b === '' || $b === false) break;
               $req .= $b;
            }
            if (preg_match('/Sec-WebSocket-Key:\s*(\S+)/i', $req, $m) !== 1) {
               fclose($conn);
               continue;
            }
            $accept = base64_encode(sha1(trim($m[1]) . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
            fwrite($conn, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {$accept}\r\n\r\n");

            // @ Read the client's selector frame (a masked text message).
            $h = fread($conn, 2);
            if (strlen($h) >= 2) {
               $b1 = ord($h[1]); $len = $b1 & 0x7F; $isMasked = ($b1 & 0x80) !== 0;
               $key = $isMasked ? fread($conn, 4) : '';
               $payload = $len > 0 ? fread($conn, $len) : '';
               if ($isMasked) {
                  $unmasked = '';
                  for ($i = 0; $i < strlen($payload); $i++) { $unmasked .= $payload[$i] ^ $key[$i % 4]; }
                  $payload = $unmasked;
               }
               fwrite($conn, $frames[$payload] ?? ("\x88\x02" . pack('n', 1000)));
            }
            usleep(200000);   // let the client read + reject before the socket closes
            fclose($conn);
         }
         exit(0);
      }

      // @ Readiness probe (plain TCP — the raw server tolerates it).
      for ($i = 0; $i < 200; $i++) {
         $probe = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 0.05);
         if ($probe !== false) {
            fclose($probe);
            break;
         }
         usleep(25000);
      }

      // ? Fail loudly if the raw server died (e.g. it could not bind the port) —
      //   otherwise the specs would run against whatever else answered the probe.
      if (pcntl_waitpid($pid, $status, WNOHANG) === $pid) {
         throw new RuntimeException(
            "E2E_Adversarial raw server exited before the specs ran (port {$port} not bindable?)."
         );
      }

      try {
         $Suite->autoboot(__DIR__);
         $Suite->autoinstance(true);
         $Suite->summarize();
      }
      finally {
         posix_kill($pid, SIGKILL);
         pcntl_waitpid($pid, $status);
      }

      return true;
   },
   autoReport: true,
   suiteName: __NAMESPACE__,
   exitOnFailure: false,
   // * Data
   tests: [
      '1.1-faults'
   ]
);
