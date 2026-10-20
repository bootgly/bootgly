<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security\Tests\JWTRemoteDestinationPolicy;


use const BOOTGLY_ROOT_DIR;
use const PHP_BINARY;
use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SERVER_BIND;
use const STREAM_SERVER_LISTEN;
use const STREAM_SOCK_STREAM;
use function array_keys;
use function assert;
use function count;
use function fclose;
use function fgets;
use function fread;
use function function_exists;
use function fwrite;
use function getenv;
use function hrtime;
use function in_array;
use function is_array;
use function is_resource;
use function is_string;
use function json_decode;
use function json_encode;
use function pcntl_fork;
use function pcntl_waitpid;
use function pcntl_wexitstatus;
use function pcntl_wifexited;
use function preg_match;
use function proc_close;
use function proc_open;
use function proc_terminate;
use function str_contains;
use function str_starts_with;
use function stream_context_create;
use function stream_get_contents;
use function stream_select;
use function stream_set_timeout;
use function stream_socket_accept;
use function stream_socket_client;
use function stream_socket_get_name;
use function stream_socket_pair;
use function stream_socket_server;
use function strlen;
use function strrpos;
use function substr;
use function usleep;
use RuntimeException;
use Throwable;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Environment\Agent;


$supported = function_exists('pcntl_fork')
   && function_exists('pcntl_waitpid')
   && function_exists('openssl_pkey_get_details')
   && function_exists('proc_open')
   && function_exists('stream_socket_pair')
   && function_exists('stream_socket_server');


// ? No public address can be bound in a test, so the client subclass declares
//   one loopback address (127.0.0.9) public through `permit()` and models DNS
//   answers through `discover()`. Every other address keeps the shipped
//   classification: 127.0.0.1 and 127.0.0.2 stay non-public. The client runs in
//   a child PHP with `openssl.cafile` pointing at the loopback CA (a PERDIR ini).
return new Test(
   description: 'JWT: a remote JWKS redirect never crosses from a public to a non-public address',
   skip: $supported === false,
   test: function () {
      $directory = __DIR__;
      $certificate = "{$directory}/fixtures/jwt_loopback_tls.pem";
      $keys = "{$directory}/fixtures/jwt_rs256.php";
      $PUBLIC = '127.0.0.9';
      $Opened = [];
      $Listen = static function (string $address, $Context = null) use (&$Opened) {
         $Listener = $Context === null
            ? @stream_socket_server($address, $code, $error)
            : @stream_socket_server($address, $code, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $Context);
         if (is_resource($Listener) === false) {
            return null;
         }
         $Opened[] = $Listener;
         $name = (string) stream_socket_get_name($Listener, false);
         $separator = strrpos($name, ':');

         return [$Listener, $separator === false ? 0 : (int) substr($name, $separator + 1)];
      };

      // @ Listeners: a "public" origin (HTTP + TLS), a non-public origin and a
      //   non-public target; one port shared by `localhost` (127.0.0.1, ::1)
      //   and the "public" 127.0.0.9 — the rebinding pair
      $Public = $Listen("tcp://{$PUBLIC}:0");
      $TLS = $Listen("tls://{$PUBLIC}:0", stream_context_create([
         'ssl' => ['local_cert' => $certificate, 'verify_peer' => false],
      ]));
      $Intranet = $Listen('tcp://127.0.0.2:0');
      $Private = $Listen('tcp://127.0.0.1:0');
      // ! One port shared by `localhost` (127.0.0.1, ::1), the "public"
      //   127.0.0.9 and a closer (127.0.0.8: accepts, then hangs up)
      $Local4 = $Rebind = $Local6 = $Closer = null;
      for ($attempt = 0; $attempt < 20 && $Closer === null; $attempt++) {
         $Local4 = $Listen('tcp://127.0.0.1:0');
         if ($Local4 === null) {
            break;
         }
         $Rebind = $Listen("tcp://{$PUBLIC}:{$Local4[1]}");
         $Closer = $Rebind === null ? null : $Listen("tcp://127.0.0.8:{$Local4[1]}");
      }
      if ($Rebind !== null && $Local4 !== null) {
         $Local6 = $Listen("tcp://[::1]:{$Local4[1]}");
      }
      // ! Two loopback blackholes on the private listener's port: a listener
      //   whose accept queue is full drops new connections, so a dial waits out
      //   its whole timeout with no answer. Kept open by this process.
      $Holes = [];
      $Fillers = [];
      $holes = ['127.0.0.5', '127.0.0.6'];
      foreach ($holes as $hole) {
         if ($Private === null) {
            break;
         }
         $Hole = @stream_socket_server(
            "tcp://{$hole}:{$Private[1]}",
            $code,
            $error,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            stream_context_create(['socket' => ['backlog' => 0]])
         );
         if (is_resource($Hole) === false) {
            continue;
         }
         $Holes[] = $Hole;
         $Filler = @stream_socket_client((string) stream_socket_get_name($Hole, false), $code, $error, 0.2);
         if (is_resource($Filler)) {
            $Fillers[] = $Filler;
         }
      }
      // ? A probe: does a third connection wait (a blackhole) or fail at once?
      $blackholes = false;
      if (count($Holes) === 2 && count($Fillers) === 2) {
         $started = hrtime(true);
         $Probe = @stream_socket_client((string) stream_socket_get_name($Holes[0], false), $code, $error, 0.3);
         $blackholes = is_resource($Probe) === false && hrtime(true) - $started >= 250_000_000;
         if (is_resource($Probe)) {
            fclose($Probe);
         }
      }
      $holed = $blackholes ? $holes : [];
      // ! A staller on the private listener's port: accepts, holds the TLS
      //   handshake ~0.6 s, hangs up — a failed try that eats part of the budget
      $Stall = $Private === null ? null : $Listen("tcp://127.0.0.10:{$Private[1]}");
      $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      if (
         $Public === null || $TLS === null || $Intranet === null || $Private === null
         || $Local4 === null || $Rebind === null || $Closer === null || $Stall === null || $Pair === false
      ) {
         foreach ($Opened as $Listener) {
            fclose($Listener);
         }
         throw new RuntimeException('JWT destination fixture could not bind its loopback listeners.');
      }

      $Listeners = [
         'public' => $Public[0],
         'tls' => $TLS[0],
         'intranet' => $Intranet[0],
         'private' => $Private[0],
         'local4' => $Local4[0],
         'rebind' => $Rebind[0],
         'closer' => $Closer[0],
         'stall' => $Stall[0],
      ];
      if ($Local6 !== null) {
         $Listeners['local6'] = $Local6[0];
      }
      $ports = [
         'holes' => $holed,
         'public' => $Public[1],
         'tls' => $TLS[1],
         'intranet' => $Intranet[1],
         'private' => $Private[1],
         'local' => $Local4[1],
      ];
      // ! "Public" listeners serve the legitimate set, non-public ones the other
      $PUBLICS = ['public', 'tls', 'rebind'];

      [$Control, $ChildControl] = $Pair;
      stream_set_timeout($Control, 30);
      $PID = pcntl_fork();
      if ($PID === -1) {
         foreach ($Opened as $Listener) {
            fclose($Listener);
         }
         throw new RuntimeException('JWT destination fixture could not fork.');
      }

      if ($PID === 0) {
         fclose($Control);
         /** @var array<string,string> $fixtures */
         $fixtures = require $keys;
         $log = [];
         $routes = [
            '/to-private' => "http://127.0.0.1:{$ports['private']}/keys-A",
            '/to-public' => "http://{$PUBLIC}:{$ports['public']}/keys-B",
            '/to-private-C' => "http://127.0.0.1:{$ports['private']}/keys-C",
            '/to-localhost' => "http://localhost:{$ports['local']}/keys-D",
            '/to-mapped' => "http://[::ffff:127.0.0.1]:{$ports['private']}/keys-E",
            '/to-nat64' => "http://nat64.example:{$ports['private']}/keys-F",
            '/to-rebind' => "http://localhost:{$ports['local']}/keys-G",
            '/to-chain' => "http://{$PUBLIC}:{$ports['public']}/to-private-K",
            '/to-private-K' => "http://127.0.0.1:{$ports['private']}/keys-K",
            '/to-private-M' => "http://127.0.0.1:{$ports['private']}/keys-M",
            '/to-mixed-N' => "http://mixed-n.example:{$ports['private']}/keys-N",
            '/to-mixed-O' => "http://mixed-o.example:{$ports['public']}/keys-O",
            '/to-private-P' => "http://127.0.0.1:{$ports['private']}/keys-P",
            '/to-private-S' => "http://127.0.0.1:{$ports['private']}/keys-S",
         ];
         $deadline = hrtime(true) + 60_000_000_000;

         // @@ Serve every listener until the parent says stop
         while (hrtime(true) < $deadline) {
            $read = $Listeners;
            $read['control'] = $ChildControl;
            $write = $except = null;
            if (@stream_select($read, $write, $except, 0, 200_000) < 1) {
               continue;
            }
            if (isset($read['control'])) {
               break;
            }

            foreach ($read as $label => $Listener) {
               $Client = @stream_socket_accept($Listener, 2.0);
               if (is_resource($Client) === false) {
                  continue;
               }
               // ? The closer hangs up without a byte; the staller after ~0.6 s
               if ($label === 'closer' || $label === 'stall') {
                  if ($label === 'stall') {
                     usleep(600_000);
                  }
                  $log[] = ['listener' => $label, 'path' => '', 'host' => '', 'authorization' => ''];
                  fclose($Client);
                  continue;
               }
               stream_set_timeout($Client, 2);
               $head = '';
               while (str_contains($head, "\r\n\r\n") === false && strlen($head) < 65536) {
                  $chunk = @fread($Client, 8192);
                  if (is_string($chunk) === false || $chunk === '') {
                     break;
                  }
                  $head .= $chunk;
               }
               $path = preg_match('/^GET\s+(\S+)/', $head, $matches) === 1 ? $matches[1] : '';
               $host = preg_match('/^Host:\s*(.*?)\r$/mi', $head, $matches) === 1 ? $matches[1] : '';
               $authorization = preg_match('/^Authorization:\s*(.*?)\r$/mi', $head, $matches) === 1 ? $matches[1] : '';
               $log[] = ['listener' => $label, 'path' => $path, 'host' => $host, 'authorization' => $authorization];

               if (isset($routes[$path])) {
                  $response = "HTTP/1.1 302 Found\r\nLocation: {$routes[$path]}\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
               }
               elseif (str_starts_with($path, '/keys-')) {
                  $set = in_array($label, $PUBLICS, true) ? 'first' : 'second';
                  $body = $fixtures[$set]['body'];
                  $length = strlen($body);
                  $response = "HTTP/1.1 200 OK\r\nCache-Control: no-store\r\nContent-Type: application/json\r\nContent-Length: {$length}\r\nConnection: close\r\n\r\n{$body}";
               }
               else {
                  $response = "HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
               }
               @fwrite($Client, $response);
               fclose($Client);
            }
         }

         $encoded = (string) json_encode($log);
         @fwrite($ChildControl, "{$encoded}\n");
         fclose($ChildControl);
         exit(0);
      }

      fclose($ChildControl);
      foreach ($Opened as $Listener) {
         fclose($Listener);
      }

      $client = <<<'PHP'
$root = (string) getenv('BOOTGLY_JWT50_ROOT');
require "{$root}autoboot.php";
$fixtures = require (string) getenv('BOOTGLY_JWT50_KEYS');
$ports = json_decode((string) getenv('BOOTGLY_JWT50_PORTS'), true);
$public = (string) getenv('BOOTGLY_JWT50_PUBLIC');

final class Destination extends \Bootgly\API\Security\JWT\Remote
{
   /** @var array<string,array<int,array<int,string>>> DNS answers per name, consumed in order */
   public array $answers = [];
   /** @var array<int,string> Names this resolver looked up */
   public array $lookups = [];
   /** @var array<int,string> Addresses declared public for the test */
   public array $publics = [];

   protected function discover (string $host): array
   {
      $this->lookups[] = $host;
      if (isset($this->answers[$host])) {
         return count($this->answers[$host]) > 1
            ? array_shift($this->answers[$host])
            : $this->answers[$host][0];
      }

      return parent::discover($host);
   }

   protected function permit (string $IP): bool
   {
      return in_array($IP, $this->publics, true) || parent::permit($IP);
   }

   public function check (string $IP): bool
   {
      return parent::permit($IP);
   }
}

$run = static function (string $URI, array $answers = [], int|float $timeout = 2) use ($fixtures, $public): array {
   $Remote = new Destination($URI, ttl: 0, cooldown: 0, insecure: str_starts_with($URI, 'http:'));
   $Remote->timeout = $timeout;
   $Remote->publics = [$public];
   $Remote->answers = $answers;
   $Keys = $Remote->fetch();

   return [
      'kind' => $Keys instanceof \Bootgly\API\Security\JWT\Failures ? $Keys->name : get_debug_type($Keys),
      'set' => $Keys instanceof \Bootgly\API\Security\JWT\KeySet
         ? ($Keys->get($fixtures['first']['jwk']['kid']) !== null ? 'first'
            : ($Keys->get($fixtures['second']['jwk']['kid']) !== null ? 'second' : 'none'))
         : null,
      'message' => $Remote->message,
      'lookups' => $Remote->lookups,
   ];
};

$P = "http://{$public}:{$ports['public']}";
// ? An untrusted run: only the HTTPS leg, without the loopback CA
if (getenv('BOOTGLY_JWT50_UNTRUSTED') === '1') {
   echo json_encode([
      'results' => ['U' => $run("https://localhost:{$ports['tls']}/keys-U", ['localhost' => [[$public]]])],
      'table' => [],
   ], JSON_THROW_ON_ERROR);
   return;
}
// ! (The fallback resolver's AAAA records depend on the host's DNS: not pinned.)
// ! Two blackholes, then the reachable private listener (one port): the hop's
//   timeout ends the hop before the third address (T); a staller that eats
//   ~0.6 s leaves the next address only the rest of the budget (T2). Timed
//   legs run once, in the run with ext-sockets
$holes = $stalled = [];
if (count($ports['holes']) === 2 && function_exists('socket_addrinfo_lookup')) {
   $started = hrtime(true);
   $holes = $run("http://holes.example:{$ports['private']}/keys-T", ['holes.example' => [[...$ports['holes'], '127.0.0.1']]], 1);
   $holes['elapsed'] = (hrtime(true) - $started) / 1e9;
   $started = hrtime(true);
   $stalled = $run("https://stall.example:{$ports['private']}/keys-T2", ['stall.example' => [['127.0.0.10', $ports['holes'][0], '127.0.0.1']]], 1);
   $stalled['elapsed'] = (hrtime(true) - $started) / 1e9;
}
$results = [
   'T' => $holes,
   'T2' => $stalled,
   'A' => $run("http://127.0.0.2:{$ports['intranet']}/to-private"),
   'B' => $run("{$P}/to-public"),
   'C' => $run("{$P}/to-private-C"),
   'D' => $run("{$P}/to-localhost"),
   'E' => $run("{$P}/to-mapped"),
   'F' => $run("{$P}/to-nat64", ['nat64.example' => [['64:ff9b::7f00:1']]]),
   'G' => $run("{$P}/to-rebind", ['localhost' => [[$public], ['127.0.0.1']]]),
   'H' => $run("https://localhost:{$ports['tls']}/keys-H", ['localhost' => [[$public]]]),
   'I' => $run("https://wrong.example:{$ports['tls']}/keys-I", ['wrong.example' => [[$public]]]),
   'K' => $run("http://127.0.0.2:{$ports['intranet']}/to-chain"),
   'L' => $run("http://fallback.example:{$ports['private']}/keys-L", ['fallback.example' => [['127.0.0.3', '127.0.0.1']]]),
   'M' => $run("http://mixed-m.example:{$ports['public']}/to-private-M", ['mixed-m.example' => [[$public, '10.255.255.1']]]),
   'N' => $run("{$P}/to-mixed-N", ['mixed-n.example' => [['127.0.0.1', $public]]]),
   'O' => $run("{$P}/to-mixed-O", ['mixed-o.example' => [[$public, '127.0.0.1']]]),
   'Q' => $run("http://user:secret@127.0.0.2:{$ports['intranet']}/keys-Q?tenant=a%2Fb&p=1"),
   // ! The answering address arms the policy: a public second address that
   //   answers arms it (P); a non-public first address that answers does not (S)
   'P' => $run("http://arm-p.example:{$ports['public']}/to-private-P", ['arm-p.example' => [['127.0.0.3', $public]]]),
   'S' => $run("http://arm-s.example:{$ports['intranet']}/to-private-S", ['arm-s.example' => [['127.0.0.2', $public]]]),
   // ! An address that accepted the connection ends the hop, even without an
   //   answer: the next address is never dialed
   'C2' => $run("http://closer.example:{$ports['local']}/keys-C2", ['closer.example' => [['127.0.0.8', '127.0.0.1']]]),
   // ! The real resolver for a configured name; the run without ext-sockets'
   //   resolver marks its path (its fallback lists IPv4 first)
   'R' => $run(
      function_exists('socket_addrinfo_lookup')
         ? "http://localhost:{$ports['local']}/keys-R"
         : "http://localhost:{$ports['local']}/keys-R-socketless"
   ),
];

$table = [];
try {
   $Probe = new Destination('https://jwks.example/keys');
   foreach ([
      '8.8.8.8', '1.1.1.1', '2606:4700::1111', '::ffff:8.8.8.8',
      '127.0.0.1', '10.0.0.1', '172.16.0.1', '192.168.0.1', '169.254.169.254', '100.64.0.1',
      '0.0.0.0', '0.1.2.3', '224.0.0.1', '239.255.255.250', '240.0.0.1', '255.255.255.255',
      '192.88.99.1', '198.18.0.1', '::1', '::', '::7f00:1', 'fe80::1', 'fc00::1', 'fd12::1',
      'ff02::1', 'ff0e::1', '::ffff:127.0.0.1', '::ffff:10.0.0.1', '64:ff9b::7f00:1',
      '64:ff9b::808:808', '64:ff9b:1::a00:1', '2002:7f00:1::1', '2001:db8::1',
      '::ffff:0:7f00:1', 'fec0::1', '3fff::1', '5f00::1',
      '64:ff9b::e000:1', '64:ff9b::c058:6301', '64:ff9b::',
   ] as $IP) {
      $table[$IP] = $Probe->check($IP);
   }
}
catch (Throwable $Throwable) {
   $class = $Throwable::class;
   $table = ['error' => "{$class}: {$Throwable->getMessage()}"];
}

echo json_encode(['results' => $results, 'table' => $table], JSON_THROW_ON_ERROR);
PHP;
      $environment = getenv();
      unset($environment['AI_AGENT'], $environment['BOOTGLY_AGENT_STDOUT_REDIRECTED']);
      foreach (Agent::MARKERS as $variables) {
         foreach ($variables as $variable) {
            unset($environment[$variable]);
         }
      }
      $environment['BOOTGLY_JWT50_ROOT'] = BOOTGLY_ROOT_DIR;
      $environment['BOOTGLY_JWT50_KEYS'] = $keys;
      $environment['BOOTGLY_JWT50_PORTS'] = (string) json_encode($ports);
      $environment['BOOTGLY_JWT50_PUBLIC'] = $PUBLIC;

      // ! One client run: its PHP ini flags and extra environment
      $spawn = static function (array $flags, array $extra) use ($client, $environment): array {
         $output = '';
         $error = '';
         $exit = -1;
         $exception = '';
         $Process = null;
         $Pipes = [];
         try {
            $Process = proc_open(
               [PHP_BINARY, '-d', 'opcache.jit=0', ...$flags, '-r', $client],
               [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
               $Pipes,
               BOOTGLY_ROOT_DIR,
               $extra + $environment
            );
            if (is_resource($Process) === false) {
               throw new RuntimeException('JWT destination client process could not start.');
            }
            $output = (string) stream_get_contents($Pipes[1]);
            $error = (string) stream_get_contents($Pipes[2]);
            fclose($Pipes[1]);
            fclose($Pipes[2]);
            $Pipes = [];
            $exit = proc_close($Process);
            $Process = null;
         }
         catch (Throwable $Exception) {
            $class = $Exception::class;
            $exception = "{$class}: {$Exception->getMessage()}";
         }
         finally {
            foreach ($Pipes as $Pipe) {
               is_resource($Pipe) && fclose($Pipe);
            }
            if (is_resource($Process)) {
               proc_terminate($Process);
               proc_close($Process);
            }
         }
         $report = json_decode($output, true);

         return [
            'results' => is_array($report) && is_array($report['results'] ?? null) ? $report['results'] : [],
            'table' => is_array($report) && is_array($report['table'] ?? null) ? $report['table'] : [],
            'exit' => $exit,
            'error' => "{$exception}{$error}",
         ];
      };

      $log = null;
      $waited = -1;
      $status = -1;
      try {
         // @ The trusted run, the same legs without ext-sockets' resolver, and
         //   an HTTPS leg without the loopback CA
         $trusted = $spawn(['-d', "openssl.cafile={$certificate}"], []);
         $socketless = $spawn(['-d', "openssl.cafile={$certificate}", '-d', 'disable_functions=socket_addrinfo_lookup'], []);
         $untrusted = $spawn([], ['BOOTGLY_JWT50_UNTRUSTED' => '1']);
      }
      finally {
         foreach ([...$Fillers, ...$Holes] as $Socket) {
            fclose($Socket);
         }
         @fwrite($Control, "stop\n");
         $line = @fgets($Control);
         $log = is_string($line) ? json_decode($line, true) : null;
         fclose($Control);
         $waited = pcntl_waitpid($PID, $status);
      }

      $results = $trusted['results'];
      $table = $trusted['table'];
      $exit = $trusted['exit'];
      $exception = $trusted['error'];
      $error = '';
      $log = is_array($log) ? $log : [];
      // ! Which paths each listener received
      $reached = [];
      foreach ($log as $entry) {
         $reached[$entry['listener']][] = $entry['path'];
      }
      $hosts = [];
      foreach ($log as $entry) {
         $hosts[$entry['path']] = $entry['host'];
      }
      $leaked = [];
      foreach (['private', 'local4', 'local6'] as $label) {
         foreach ($reached[$label] ?? [] as $path) {
            // ! The intranet leg (A), the fallback leg (L) and the configured
            //   `localhost` leg (R) reach them by design
            if (in_array($path, ['/keys-A', '/keys-L', '/keys-R', '/keys-R-socketless', '/keys-S'], true) === false) {
               $leaked[] = "{$label}{$path}";
            }
         }
      }
      $refusal = 'Remote JWKS redirect from a public address to a non-public address was refused.';
      $Refused = static fn (string $leg): bool => ($results[$leg]['kind'] ?? null) === 'Network'
         && ($results[$leg]['message'] ?? null) === $refusal;
      $evidence = (string) json_encode(['results' => $results, 'log' => $log]);

      yield assert(
         assertion: $exception === ''
            && $exit === 0
            && $error === ''
            && $waited === $PID && pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0
            && $results !== [],
         description: "The destination fixture ran its client and reaped its server ({$exception}{$error})"
      );

      yield assert(
         assertion: ($results['A']['set'] ?? null) === 'second'
            && in_array('/keys-A', $reached['private'] ?? [], true),
         description: 'A non-public origin may still redirect to a non-public address (intranet IdP)'
      );

      yield assert(
         assertion: ($results['B']['set'] ?? null) === 'first'
            && in_array('/keys-B', $reached['public'] ?? [], true),
         description: 'A public origin may redirect to a public address'
      );

      yield assert(
         assertion: $Refused('C') && in_array('private/keys-C', $leaked, true) === false,
         description: "API-SEC-3: a public origin's redirect to a non-public literal is refused before any request; evidence={$evidence}"
      );

      yield assert(
         assertion: $Refused('D') && in_array('local4/keys-D', $leaked, true) === false
            && in_array('local6/keys-D', $leaked, true) === false,
         description: 'API-SEC-3: a redirect to a name that resolves to loopback (localhost) is refused'
      );

      yield assert(
         assertion: $Refused('E') && in_array('private/keys-E', $leaked, true) === false,
         description: 'API-SEC-3: an IPv4-mapped IPv6 literal is judged as the IPv4 address it carries'
      );

      yield assert(
         assertion: $Refused('F') && ($results['F']['lookups'] ?? []) !== []
            && in_array('nat64.example', $results['F']['lookups'], true),
         description: 'API-SEC-3: a name that resolves into the NAT64 prefix is refused'
      );

      yield assert(
         assertion: ($results['G']['set'] ?? null) === 'first'
            && ($results['G']['lookups'] ?? null) === ['127.0.0.9', 'localhost']
            && in_array('/keys-G', $reached['rebind'] ?? [], true)
            && ($hosts['/keys-G'] ?? null) === "localhost:{$ports['local']}",
         description: 'DNS rebinding: each hop is resolved once and its vetted literal is dialed with the name as Host'
      );

      yield assert(
         assertion: ($results['H']['set'] ?? null) === 'first'
            && ($hosts['/keys-H'] ?? null) === "localhost:{$ports['tls']}",
         description: 'HTTPS keeps verifying the certificate against the hop name while dialing its literal'
      );

      yield assert(
         assertion: ($results['I']['kind'] ?? null) === 'Network'
            && in_array('/keys-I', $reached['tls'] ?? [], true) === false,
         description: 'A certificate that does not name the hop still fails the fetch'
      );

      yield assert(
         assertion: $Refused('K') && in_array('private/keys-K', $leaked, true) === false,
         description: 'A non-public origin that passes through a public hop cannot come back to a non-public one'
      );

      $leaks = (string) json_encode($leaked);
      yield assert(
         assertion: $leaked === [],
         description: "No refused leg reached a non-public listener: {$leaks}"
      );

      yield assert(
         assertion: ($results['L']['set'] ?? null) === 'second'
            && in_array('/keys-L', $reached['private'] ?? [], true),
         description: 'A hop whose first address does not answer is dialed at its next vetted address'
      );

      yield assert(
         assertion: $Refused('M') && in_array('private/keys-M', $leaked, true) === false,
         description: 'An origin answered from a public address arms the policy even when its name also resolves to a non-public one'
      );

      yield assert(
         assertion: $Refused('N') && $Refused('O')
            && in_array('/keys-N', $reached['private'] ?? [], true) === false
            && in_array('/keys-O', $reached['public'] ?? [], true) === false,
         description: 'After a public hop, a hop with any non-public address is refused — whichever address comes first'
      );

      $query = [];
      foreach ($log as $entry) {
         if (str_starts_with($entry['path'], '/keys-Q')) {
            $query = $entry;
         }
      }
      yield assert(
         assertion: ($results['Q']['set'] ?? null) === 'second'
            && ($query['path'] ?? null) === '/keys-Q?tenant=a%2Fb&p=1'
            && ($query['authorization'] ?? null) === 'Basic dXNlcjpzZWNyZXQ=',
         description: 'The dialed request keeps the hop query byte for byte and its userinfo credentials'
      );

      $kinds = static function (array $run): array {
         $kinds = [];
         foreach ($run['results'] as $leg => $result) {
            // ? The timed legs run once
            if ($leg === 'T' || $leg === 'T2') {
               continue;
            }
            $kinds[$leg] = [$result['kind'] ?? null, $result['set'] ?? null, $result['message'] ?? null];
         }

         return $kinds;
      };
      $mirrored = $socketless['exit'] === 0 && $socketless['error'] === ''
         && $kinds($socketless) === $kinds($trusted) && $kinds($trusted) !== [];
      $mirror = (string) json_encode($kinds($socketless));
      yield assert(
         assertion: $mirrored,
         description: "Without ext-sockets' resolver every leg ends the same way: {$mirror}"
      );

      yield assert(
         assertion: ($results['R']['set'] ?? null) === 'second'
            && in_array('/keys-R-socketless', $reached['local4'] ?? [], true),
         description: 'A configured name resolves through the real resolver — and, without ext-sockets, through its IPv4-first fallback'
      );

      yield assert(
         assertion: $untrusted['exit'] === 0
            && ($untrusted['results']['U']['kind'] ?? null) === 'Network'
            && in_array('/keys-U', $reached['tls'] ?? [], true) === false,
         description: 'A certificate that does not chain to a trusted CA fails the fetch before any request'
      );

      yield assert(
         assertion: ($results['C2']['kind'] ?? null) === 'Network'
            && in_array('closer', array_keys($reached), true)
            && in_array('/keys-C2', $reached['local4'] ?? [], true) === false
            && in_array('/keys-C2', $reached['local6'] ?? [], true) === false,
         description: 'An address that took the request and hung up ends the hop: the next address is never dialed'
      );

      yield assert(
         assertion: $Refused('P') && in_array('private/keys-P', $leaked, true) === false,
         description: 'A public address that answers after an unreachable first one arms the policy'
      );

      yield assert(
         assertion: ($results['S']['set'] ?? null) === 'second' && in_array('/keys-S', $reached['private'] ?? [], true),
         description: 'A non-public first address that answers leaves the policy unarmed (the intranet keeps working)'
      );

      $timed = (string) json_encode([$results['T'] ?? null, $results['T2'] ?? null]);
      yield $blackholes
         ? assert(
            assertion: ($results['T']['kind'] ?? null) === 'Network'
               && in_array('/keys-T', $reached['private'] ?? [], true) === false
               && ($results['T']['elapsed'] ?? 99) < 1.5
               && ($results['T2']['kind'] ?? null) === 'Network'
               && in_array('stall', array_keys($reached), true)
               && ($results['T2']['elapsed'] ?? 99) < 1.3,
            description: "The hop's timeout bounds its tries together: blackholes end the hop before the next address, and a try after a staller gets only the rest — {$timed}"
         )
         : (new Assertion(description: 'this host refuses a full accept queue at once (no blackhole to time out against)'))->skip();

      $public = ['8.8.8.8', '1.1.1.1', '2606:4700::1111', '::ffff:8.8.8.8', '64:ff9b::808:808'];
      $classified = true;
      foreach ($table as $IP => $verdict) {
         if ($verdict !== in_array((string) $IP, $public, true)) {
            $classified = false;
         }
      }
      $verdicts = (string) json_encode($table);
      yield assert(
         assertion: $classified && isset($table['error']) === false && count($table) === 40,
         description: "The classifier admits only public unicast addresses: {$verdicts}"
      );
   }
);
