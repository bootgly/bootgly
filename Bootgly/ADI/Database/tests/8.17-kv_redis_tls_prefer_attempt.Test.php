<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Database;


use const E_ALL;
use const E_WARNING;
use const LC_ALL;
use const OPENSSL_KEYTYPE_EC;
use const STREAM_CRYPTO_METHOD_TLS_SERVER;
use const STREAM_IPPROTO_IP;
use const STREAM_PEEK;
use const STREAM_PF_UNIX;
use const STREAM_SERVER_BIND;
use const STREAM_SERVER_LISTEN;
use const STREAM_SOCK_STREAM;
use function assert;
use function bin2hex;
use function count;
use function error_reporting;
use function explode;
use function fclose;
use function file_put_contents;
use function fread;
use function fsockopen;
use function fwrite;
use function getenv;
use function getmypid;
use function glob;
use function in_array;
use function ini_get;
use function is_array;
use function is_bool;
use function is_dir;
use function is_file;
use function is_resource;
use function is_string;
use function json_decode;
use function json_encode;
use function max;
use function microtime;
use function min;
use function mkdir;
use function openssl_csr_new;
use function openssl_csr_sign;
use function openssl_get_cert_locations;
use function openssl_pkey_export;
use function openssl_pkey_new;
use function openssl_x509_export;
use function ord;
use function pack;
use function pcntl_fork;
use function pcntl_waitpid;
use function rmdir;
use function round;
use function setlocale;
use function str_contains;
use function str_repeat;
use function stream_context_create;
use function stream_get_contents;
use function stream_select;
use function stream_set_blocking;
use function stream_set_timeout;
use function stream_socket_accept;
use function stream_socket_enable_crypto;
use function stream_socket_get_name;
use function stream_socket_pair;
use function stream_socket_recvfrom;
use function stream_socket_server;
use function strlen;
use function strrpos;
use function substr;
use function sys_get_temp_dir;
use function unlink;
use function usleep;
use function var_export;
use Throwable;

use Bootgly\ACI\Events\Scheduler;
use Bootgly\ACI\Tests\Fixture;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Config;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Databases\KV;
use Bootgly\ADI\Databases\KV\Drivers\Redis;


/**
 * The DEFAULT secure config (`prefer`, no `cafile`) must actually attempt TLS,
 * and `prefer` may downgrade to plaintext ONLY on an explicit refusal.
 *
 * An empty `cafile` handed to the SSL context made `stream_socket_enable_crypto`
 * throw `ValueError: Path must not be empty` before any ClientHello, so TLS was
 * never tried — and `prefer` read that LOCAL error as the peer refusing TLS and
 * reconnected in plaintext, sending AUTH in the clear to a server that may have
 * spoken TLS all along. Then a handshake budget was added so a plaintext Redis
 * — which parks a ClientHello in its query buffer and never answers — did not
 * cost the whole timeout, and `prefer` downgraded on the budget too: a TLS peer
 * whose ServerHello arrived one retransmission late got the password in
 * plaintext, on a connection the pool then reused. Silence is not a refusal.
 * The budget then capped the strict modes as well, where silence can never
 * downgrade and it bought nothing: a genuine TLS peer answering 1.2s late
 * failed `require` at 1s despite `timeout => 5`. And the refusal classifier
 * scanned the WHOLE handshake diagnostic for OpenSSL's reason strings, though
 * a peer name mismatch quotes the certificate CN the PEER chose — a leaf named
 * `wrong version number` read as a refusal, and `prefer` downgraded on it.
 * In-process fixtures see none of this: only a real listener does. This drives
 * one against scripted peer shapes — `silent` (drains the ClientHello and keeps
 * the socket open, as Redis does), `reset` (closes on contact with unread
 * bytes: RST), `chatty` (answers the ClientHello with RESP bytes), `partial`
 * (answers with part of a ServerHello, then closes: an on-path cut AFTER the
 * peer spoke TLS) and `tls` (a TLS server: by default one whose self-signed
 * certificate no store trusts, or one presenting a leaf minted at test time
 * — signed by a throwaway CA the client is handed as `cafile` — that answers
 * late, completes the handshake and serves RESP over it, or whose CN is an
 * OpenSSL refusal string) — plus a port nobody listens on — and reads what
 * reached the wire, connection by connection, plus how long the client took.
 * The `tls` peer also takes a CA store that OpenSSL cannot load, with
 * E_WARNING masked as the shipped `error off` masks it: PHP reports that
 * store without naming the function, and a handler that kept only
 * `stream_socket_enable_crypto` messages read the empty diagnostic as the
 * peer closing the connection — and downgraded.
 */
$timeout = 3.0;

// ! The first 64 bytes of a ServerHello record — record header, handshake
//   header, version, random, part of the session id — cut before the record's
//   declared length ever arrives: what an on-path cut after the ServerHello
//   leaves the client holding.
$body = "\x03\x03" . str_repeat("\x2a", 32) . "\x20" . str_repeat("\x2b", 32) . "\x13\x01\x00";
$handshake = "\x02" . substr(pack('N', strlen($body)), 1) . $body;
$partial = substr("\x16\x03\x03" . pack('n', strlen($handshake)) . $handshake, 0, 64);

/**
 * The throwaway PKI, as a Fixture so the runner purges it after the case on
 * EVERY outcome — a purge at the end of the generator never ran once a
 * failed assertion threw out of it, and the directory stayed behind. It
 * mints, in a temporary directory, a CA, a leaf for 127.0.0.1 it signed —
 * a genuine TLS peer once the CA is the client's `cafile` — and a leaf
 * whose CN is one of OpenSSL's refusal strings, `wrong version number`:
 * the text a peer name mismatch quotes back. Minted with the same
 * ext-openssl the handshake needs, so nothing else has to be installed; a
 * failure lands in `error`, never throws, and the legs that present the
 * PKI say so.
 */
$PKI = new class extends Fixture
{
   protected function setup (): void
   {
      foreach ($this->mint() as $key => $value) {
         $this->State->update($key, $value);
      }
   }

   protected function teardown (): void
   {
      $this->purge((string) $this->fetch('directory', ''));

      parent::teardown();
   }

   /**
    * @return array{error:string,directory:string,cafile:string,server:string,mismatch:string}
    */
   private function mint (): array
   {
      $minted = ['error' => '', 'directory' => '', 'cafile' => '', 'server' => '', 'mismatch' => '', 'anchor' => '', 'transport' => ''];
      $directory = sys_get_temp_dir() . '/bootgly-8.17-' . getmypid();
      if (is_dir($directory) === false && @mkdir($directory, 0700) === false) {
         $minted['error'] = "could not create {$directory}";

         return $minted;
      }
      $minted['directory'] = $directory;

      // ! The extensions each certificate carries: only a CA:TRUE issuer is a
      //   trust anchor, and only a SAN names 127.0.0.1 the way a client checks it
      $configuration = "{$directory}/openssl.cnf";
      $sections = "[req]\ndistinguished_name = dn\n[dn]\n"
         . "[v3_ca]\nbasicConstraints = critical, CA:TRUE\nkeyUsage = critical, keyCertSign\n"
         . "[v3_server]\nbasicConstraints = CA:FALSE\nsubjectAltName = IP:127.0.0.1\n"
         . "[v3_mismatch]\nbasicConstraints = CA:FALSE\n";
      if (file_put_contents($configuration, $sections) === false) {
         $minted['error'] = "could not write {$configuration}";

         return $minted;
      }
      $options = [
         'config' => $configuration,
         'digest_alg' => 'sha256',
         'private_key_type' => OPENSSL_KEYTYPE_EC,
         'curve_name' => 'prime256v1',
      ];

      $CAKey = openssl_pkey_new($options);
      $CARequest = $CAKey === false ? false : openssl_csr_new(['commonName' => 'Bootgly 8.17 throwaway CA'], $CAKey, $options);
      $CA = $CARequest === false || $CAKey === false
         ? false
         : openssl_csr_sign($CARequest, null, $CAKey, 2, $options + ['x509_extensions' => 'v3_ca'], 1);
      $PEM = '';
      if ($CA === false || $CAKey === false || openssl_x509_export($CA, $PEM) === false || file_put_contents("{$directory}/ca.pem", $PEM) === false) {
         $minted['error'] = 'could not mint the CA';

         return $minted;
      }
      $minted['cafile'] = "{$directory}/ca.pem";

      // @@ Each leaf: a certificate and its key in ONE file, what `local_cert` reads
      $leaves = [
         'server' => ['127.0.0.1', 'v3_server', 2],
         'mismatch' => ['wrong version number', 'v3_mismatch', 3],
         // ! CNs that carry the classifier's own anchors, the transport one whole
         //   (37 bytes, inside the 64-byte CN cap): peer-chosen text
         'anchor' => ['OpenSSL Error messages: wrong version number', 'v3_mismatch', 4],
         'transport' => ['stream_socket_enable_crypto(): SSL: x', 'v3_mismatch', 5],
      ];
      foreach ($leaves as $name => [$CN, $extensions, $serial]) {
         $Key = openssl_pkey_new($options);
         $Request = $Key === false ? false : openssl_csr_new(['commonName' => $CN], $Key, $options);
         $Certificate = $Request === false || $Key === false
            ? false
            : openssl_csr_sign($Request, $CA, $CAKey, 2, $options + ['x509_extensions' => $extensions], $serial);
         $certificate = '';
         $key = '';
         if (
            $Certificate === false || $Key === false
            || openssl_x509_export($Certificate, $certificate) === false
            || openssl_pkey_export($Key, $key, null, $options) === false
            || file_put_contents("{$directory}/{$name}.pem", $certificate . $key) === false
         ) {
            $minted['error'] = "could not mint the {$name} leaf";

            return $minted;
         }
         $minted[$name] = "{$directory}/{$name}.pem";
      }

      return $minted;
   }

   private function purge (string $directory): void
   {
      if ($directory === '' || is_dir($directory) === false) {
         return;
      }
      $files = glob("{$directory}/*");
      foreach (is_array($files) ? $files : [] as $file) {
         @unlink($file);
      }
      @rmdir($directory);
   }
};

/**
 * Pool::wait(), reduced to what one driver needs: advance, then block on the
 * readiness it armed, bounded by that readiness' deadline and the
 * operation's own expiry — the pool's part of the timeout contract.
 */
$pump = static function (Redis $Redis, Operation $Operation, float $limit): void {
   $until = microtime(true) + $limit;
   while ($Operation->finished === false && microtime(true) < $until) {
      if ($Operation->expire()) {
         break;
      }
      $Redis->advance($Operation);
      $Readiness = $Operation->Readiness;
      if ($Operation->finished || $Readiness === null) {
         break;
      }
      $read = [];
      $write = [];
      $except = [];
      if ($Readiness->flag === Scheduler::SCHEDULE_READ) {
         $read[] = $Readiness->socket;
      }
      else {
         $write[] = $Readiness->socket;
      }
      $remaining = $Readiness->deadline > 0.0 ? $Readiness->deadline - microtime(true) : 1.0;
      @stream_select($read, $write, $except, 0, (int) (max(0.0, min($remaining, 1.0)) * 1_000_000));
   }
};

/**
 * Drive the async Redis driver against a real listener whose behaviour is
 * scripted per contact, and read what reached the wire.
 *
 * `$shape` scripts what the peer does with each TLS contact (a ClientHello),
 * comma-separated in order — `silent`, `reset`, `chatty`, `partial`, `tls` —
 * while a plaintext contact is always answered. Options: `cafile` and `peer`
 * for the client; `certificate` (the `tls` peer's `local_cert`), `delay`
 * (seconds the `tls` peer waits before answering the ClientHello) and
 * `answer` (the `tls` peer completes the handshake and serves RESP over it
 * instead of closing) for the peer; `rounds` runs that many commands on ONE
 * driver, closing and re-binding its connection between them — by hand, as
 * fallback() itself reopens a generation, since the pool builds a fresh
 * driver on a closed connection and a fresh driver's `downgrade` is empty by
 * construction; `closed` closes the port before the client dials it, so no
 * peer ever runs.
 *
 * @param array{cafile?:string,peer?:string,certificate?:string,delay?:float,answer?:bool,rounds?:int,closed?:bool,verify?:bool} $options
 * @return array{error:string,connections:array<int,array{first_hex:string,auth_seen:bool,encrypted:bool}>,client_error:string,response:mixed,elapsed:float,downgrade:null|string,rounds:array<int,array{response:mixed,error:string,downgrade:null|string}>}
 */
$drive = static function (string $mode, string $shape, float $timeout = 3.0, array $options = []) use ($partial, $pump): array {
   $cafile = $options['cafile'] ?? '';
   $peer = $options['peer'] ?? '';
   $certificate = $options['certificate'] ?? __DIR__ . '/fixtures/postgresql_tls.pem';
   $delay = $options['delay'] ?? 0.0;
   $answer = $options['answer'] ?? false;
   $rounds = $options['rounds'] ?? 1;
   $closed = $options['closed'] ?? false;
   $verify = $options['verify'] ?? null;
   $shapes = explode(',', $shape);
   $failure = static fn (string $error): array => ['error' => $error, 'connections' => [], 'client_error' => '', 'response' => null, 'elapsed' => 0.0, 'downgrade' => null, 'rounds' => []];

   // ! The `tls` peer answers the ClientHello with a certificate — by default
   //   one no trust store accepts, the shape `prefer` must never mistake for
   //   a peer without TLS.
   $Context = in_array('tls', $shapes, true)
      ? stream_context_create(['ssl' => [
         'local_cert' => $certificate,
         'verify_peer' => false,
         'allow_self_signed' => true,
      ]])
      : null;
   $Listener = stream_socket_server(
      'tcp://127.0.0.1:0',
      $errorCode,
      $error,
      STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
      $Context,
   );
   $Channel = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
   if (is_resource($Listener) === false || $Channel === false) {
      return $failure('fixture could not create listener/channel');
   }
   $address = stream_socket_get_name($Listener, false);
   $separator = is_string($address) ? strrpos($address, ':') : false;
   $port = $separator === false ? 0 : (int) substr($address, $separator + 1);
   if ($port < 1) {
      fclose($Channel[0]);
      fclose($Channel[1]);
      fclose($Listener);

      return $failure('fixture could not resolve the listener port');
   }

   $PID = 0;
   if ($closed) {
      // @ Nobody listens: the port was bound long enough to be known, and is
      //   closed before the client dials it — no peer runs at all
      fclose($Channel[0]);
      fclose($Channel[1]);
      fclose($Listener);
   }
   else {
      $PID = pcntl_fork();
      if ($PID === -1) {
         fclose($Channel[0]);
         fclose($Channel[1]);
         fclose($Listener);

         return $failure('fixture could not fork');
      }
   }

   if ($PID === 0 && $closed === false) {
      fclose($Channel[0]);
      $connections = [];
      /** @var array<int,resource> $Held */
      $Held = [];
      $hello = 0;
      // @@ One contact more than the TLS contacts scripted — `prefer`
      //    reconnects once in plaintext after a refused handshake — or until
      //    the parent says the client is done, so an abort never waits on an
      //    accept and a refused config shows zero.
      $contacts = count($shapes) + 1;
      for ($n = 0; $n < $contacts; $n++) {
         $read = [$Listener, $Channel[1]];
         $write = [];
         $except = [];
         $selected = @stream_select($read, $write, $except, 5);
         if ($selected === false || $selected === 0 || in_array($Channel[1], $read, true)) {
            break;
         }

         $Peer = @stream_socket_accept($Listener, 1.0);
         if (is_resource($Peer) === false) {
            break;
         }
         stream_set_blocking($Peer, true);
         stream_set_timeout($Peer, 2);
         $peeked = @stream_socket_recvfrom($Peer, 1, STREAM_PEEK);
         $first = is_string($peeked) ? $peeked : '';
         $bytes = '';
         $encrypted = false;

         // ? A ClientHello is a TLS record (0x16); anything else is RESP
         if ($first !== '' && ord($first[0]) === 0x16) {
            $current = $shapes[min($hello, count($shapes) - 1)];
            $hello++;

            if ($current === 'reset') {
               // @ Close with the ClientHello unread: the kernel answers RST
               fclose($Peer);
            }
            else if ($current === 'tls') {
               // @ Answer it — after `delay`, for a peer whose ServerHello is
               //   late: the handshake runs until the client judges the
               //   certificate. Then close, or — `answer` — serve RESP over it
               //   the way a TLS Redis does.
               if ($delay > 0.0) {
                  usleep((int) ($delay * 1_000_000));
               }
               $encrypted = @stream_socket_enable_crypto($Peer, true, STREAM_CRYPTO_METHOD_TLS_SERVER) === true;
               if ($encrypted && $answer) {
                  $wire = '';
                  while (str_contains($wire, "PING\r\n") === false) {
                     $chunk = @fread($Peer, 8192);
                     if ($chunk === false || $chunk === '' || strlen($wire) > 65536) {
                        break;
                     }
                     $wire .= $chunk;
                  }
                  @fwrite($Peer, "+OK\r\n+PONG\r\n");
               }
               fclose($Peer);
            }
            else if ($current === 'chatty') {
               // @ A plaintext protocol talking back: RESP bytes where the
               //   client expects a ServerHello — an explicit refusal.
               usleep(100000);
               stream_set_blocking($Peer, false);
               @fread($Peer, 65536);
               @fwrite($Peer, "-ERR unknown command\r\n");
               usleep(100000);
               fclose($Peer);
            }
            else if ($current === 'partial') {
               // @ A TLS answer cut short: drain the ClientHello, send part of
               //   a ServerHello, then close cleanly (FIN) — the peer DID speak
               //   TLS, and the cut came after it did.
               usleep(100000);
               stream_set_blocking($Peer, false);
               @fread($Peer, 65536);
               @fwrite($Peer, $partial);
               usleep(100000);
               fclose($Peer);
            }
            else {
               // @ What a plaintext Redis does: read the ClientHello into its
               //   query buffer, find no command in it, and wait for more —
               //   the socket stays open and nothing is ever answered.
               usleep(100000);
               stream_set_blocking($Peer, false);
               @fread($Peer, 65536);
               $Held[] = $Peer;
            }
         }
         else if ($first !== '') {
            // @ The plaintext contact: answer AUTH and PING, so the client's
            //   round trip — not a timeout — ends the operation.
            $wire = '';
            while (str_contains($wire, "PING\r\n") === false) {
               $chunk = @fread($Peer, 8192);
               if ($chunk === false || $chunk === '' || strlen($wire) > 65536) {
                  break;
               }
               $wire .= $chunk;
            }
            $bytes = $wire;
            @fwrite($Peer, "+OK\r\n+PONG\r\n");
            fclose($Peer);
         }
         else {
            fclose($Peer);
         }

         // ! `auth_seen` is AUTH in PLAINTEXT: bytes read off a TLS contact
         //   are never counted, that is what the handshake is for
         $connections[] = ['first_hex' => bin2hex($first), 'auth_seen' => str_contains($bytes, 'AUTH'), 'encrypted' => $encrypted];
      }
      foreach ($Held as $Peer) {
         fclose($Peer);
      }
      fclose($Listener);
      @fwrite($Channel[1], (string) json_encode($connections));
      fclose($Channel[1]);

      exit(0);
   }

   if ($PID > 0) {
      fclose($Channel[1]);
      fclose($Listener);
   }

   $secure = ['mode' => $mode]; // ! nothing else: the shipped defaults
   if (is_bool($verify)) {
      $secure['verify'] = $verify;
   }
   if ($cafile !== '') {
      $secure['cafile'] = $cafile;
   }
   if ($peer !== '') {
      $secure['peer'] = $peer;
   }
   $config = [
      'driver' => 'redis',
      'host' => '127.0.0.1',
      'port' => $port,
      'password' => 'prefer-attempt-canary',
      'database' => '0',
      'timeout' => $timeout,
      'secure' => $secure,
      'pool' => ['max' => 1],
   ];
   $records = [];
   $start = microtime(true);
   if ($rounds > 1) {
      $Config = new Config($config);
      $Connection = new Connection($Config);
      $Redis = new Redis($Config, $Connection);
      $Connection->bind($Redis);
      for ($round = 0; $round < $rounds; $round++) {
         // @ Between rounds, what fallback() does to open a new generation
         //   on the same driver: close the connection, bind the driver again
         if ($round > 0) {
            $Connection->disconnect();
            $Connection->bind($Redis);
         }
         $Operation = $Redis->command('PING');
         $clientError = '';
         $response = null;
         try {
            $pump($Redis, $Operation, $timeout + 1.0);
            $response = $Operation->response;
            $clientError = (string) ($Operation->error ?? '');
         }
         catch (Throwable $Throwable) {
            $clientError = $Throwable::class . ': ' . $Throwable->getMessage();
         }
         $records[] = ['response' => $response, 'error' => $clientError, 'downgrade' => $Redis->downgrade];
      }
      $Connection->disconnect();
   }
   else {
      $KV = new KV($config);
      $Operation = $KV->command('PING');
      $clientError = '';
      $response = null;
      try {
         $KV->await($Operation);
         $response = $Operation->response;
         $clientError = (string) ($Operation->error ?? '');
      }
      catch (Throwable $Throwable) {
         $clientError = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         $KV->Connection->disconnect();
      }
      // ! The downgrade record the driver keeps for the plaintext generation
      $Protocol = $Operation->Protocol;
      $records[] = ['response' => $response, 'error' => $clientError, 'downgrade' => $Protocol instanceof Redis ? $Protocol->downgrade : null];
   }
   $elapsed = round(microtime(true) - $start, 3);
   $last = $records[count($records) - 1];

   $connections = [];
   if ($PID > 0) {
      // @ The client is done: the peer stops waiting for a contact that will never come
      @fwrite($Channel[0], 'done');
      stream_set_timeout($Channel[0], 6);
      $raw = stream_get_contents($Channel[0]);
      fclose($Channel[0]);
      pcntl_waitpid($PID, $status);
      $decoded = is_string($raw) ? json_decode($raw, true) : null;
      $connections = is_array($decoded) ? $decoded : [];
   }

   return [
      'error' => '',
      'connections' => $connections,
      'client_error' => $last['error'],
      'response' => $last['response'],
      'elapsed' => $elapsed,
      'downgrade' => $last['downgrade'],
      'rounds' => $records,
   ];
};

// ! The live lane: a real plaintext Redis, only where the environment names one
$host = getenv('REDIS_HOST') !== false ? (string) getenv('REDIS_HOST') : '127.0.0.1';
$port = getenv('REDIS_PORT') !== false ? (int) getenv('REDIS_PORT') : 0;

return new Test(
   description: 'The default secure config attempts TLS first; `prefer` downgrades only on an explicit refusal (RST, FIN, non-TLS bytes) and records its shape per generation, a silent peer fails `prefer` at the handshake budget naming `disable` while the strict modes wait for the ServerHello until the operation timeout, a closed port is a connection failure, and no certificate failure, peer name mismatch or local error — a CA store OpenSSL cannot load, whatever error_reporting() masks — ever downgrades',
   test: function (Fixture $PKI) use ($drive, $timeout, $host, $port) {
      // ! A ClientHello never leaves this host when its CA store cannot be
      //   loaded — that is a host fault, not this code's, so name the store.
      $locations = openssl_get_cert_locations();
      $file = (string) ($locations['default_cert_file'] ?? '');
      $directory = (string) ($locations['default_cert_dir'] ?? '');
      $store = 'openssl.cafile=' . var_export(ini_get('openssl.cafile'), true)
         . ", default_cert_file={$file}" . (is_file($file) ? ' (exists)' : ' (MISSING)')
         . ", default_cert_dir={$directory}" . (is_dir($directory) ? ' (exists)' : ' (MISSING)');
      $budget = min(Redis::HANDSHAKE_BUDGET, $timeout / 2);
      // ! The throwaway PKI the genuine-TLS and name-mismatch legs present —
      //   prepared by the runner before this body runs, purged after it
      $minting = (string) $PKI->fetch('error', 'the PKI fixture was not prepared');
      $CA = (string) $PKI->fetch('cafile', '');
      $server = (string) $PKI->fetch('server', '');
      $mismatch = (string) $PKI->fetch('mismatch', '');

      // @@ The budget is a contract, not a derivation: the legs below hardcode
      //    their ceilings against 1 s, so a changed constant fails here first
      yield assert(
         assertion: Redis::HANDSHAKE_BUDGET === 1.0,
         description: 'Redis::HANDSHAKE_BUDGET is 1.0s — ' . var_export(Redis::HANDSHAKE_BUDGET, true)
      );

      // @@ A) prefer, shipped defaults, a peer that resets the ClientHello — TLS
      //    first, plaintext only after the refusal, and the refusal on record
      $reset = $drive('prefer', 'reset', $timeout);
      $first = $reset['connections'][0] ?? [];
      $second = $reset['connections'][1] ?? [];

      yield assert(
         assertion: $reset['error'] === ''
            && ($first['first_hex'] ?? '') === '16'
            && ($first['auth_seen'] ?? true) === false,
         description: 'A) prefer with the shipped defaults opens with a TLS ClientHello, never with plaintext AUTH — '
            . json_encode($reset) . " — {$store}"
      );
      yield assert(
         assertion: ($second['first_hex'] ?? '') !== '' && ($second['first_hex'] ?? '') !== '16'
            && $reset['response'] === 'PONG'
            && is_string($reset['downgrade'])
            && str_contains($reset['downgrade'], 'reset the TLS handshake') === true,
         description: 'A) prefer reconnects in plaintext only on the SECOND contact, after the peer reset TLS, and the driver records the refusal it downgraded on — '
            . json_encode($reset)
      );

      // @@ B) prefer, shipped defaults, a peer that answers the ClientHello with
      //    RESP bytes — an explicit refusal too, and the record names it
      $chatty = $drive('prefer', 'chatty', $timeout);
      $first = $chatty['connections'][0] ?? [];
      $second = $chatty['connections'][1] ?? [];

      yield assert(
         assertion: $chatty['error'] === ''
            && ($first['first_hex'] ?? '') === '16'
            && ($first['auth_seen'] ?? true) === false
            && ($second['first_hex'] ?? '') !== '' && ($second['first_hex'] ?? '') !== '16'
            && $chatty['response'] === 'PONG'
            && is_string($chatty['downgrade'])
            && str_contains($chatty['downgrade'], 'non-TLS bytes') === true
            && $chatty['elapsed'] < $budget,
         description: 'B) prefer downgrades on a peer that answers the ClientHello with non-TLS bytes, before the budget, and records the refusal — '
            . json_encode($chatty)
      );

      // @@ C) prefer, shipped defaults, a peer that swallows the ClientHello and
      //    stays silent (what a plaintext Redis does) — silence is NOT a
      //    refusal: one contact, no plaintext AUTH, a failure at the budget
      //    that names the budget and the way out
      $silent = $drive('prefer', 'silent', $timeout);
      $only = $silent['connections'][0] ?? [];

      yield assert(
         assertion: $silent['error'] === ''
            && ($only['first_hex'] ?? '') === '16'
            && ($only['auth_seen'] ?? true) === false
            && count($silent['connections']) === 1
            && $silent['response'] === null
            && str_contains($silent['client_error'], "did not answer the TLS handshake within {$budget}s") === true
            && str_contains($silent['client_error'], "secure.mode => 'disable'") === true
            && $silent['elapsed'] >= $budget
            && $silent['elapsed'] < 1.5,
         description: "C) prefer never downgrades on a silent peer: one ClientHello, no plaintext AUTH, and a failure at the {$budget}s handshake budget (under 1.5s) naming it and `disable` — "
            . json_encode($silent)
      );

      // @@ D) prefer, `timeout => 0.5`, silent peer — the budget is half the
      //    timeout, and the failure lands AT the budget, not a second later
      $short = $drive('prefer', 'silent', 0.5);
      $only = $short['connections'][0] ?? [];

      yield assert(
         assertion: $short['error'] === ''
            && ($only['first_hex'] ?? '') === '16'
            && count($short['connections']) === 1
            && str_contains($short['client_error'], 'did not answer the TLS handshake within 0.25s') === true
            && $short['elapsed'] >= 0.25
            && $short['elapsed'] < 0.9,
         description: 'D) with timeout 0.5s the budget is 0.25s and the silent-peer failure lands at it — under 0.9s even on a loaded host, never the 1s an unhalved budget costs — naming 0.25s — '
            . json_encode($short)
      );

      // @@ E) require, shipped defaults, silent peer, `timeout => 1.0` — one
      //    ClientHello and NO budget: a strict mode waits for the ServerHello
      //    until the operation's own deadline, then times out like any other
      //    operation, never naming a handshake budget it does not have
      $require = $drive('require', 'silent', 1.0);
      $only = $require['connections'][0] ?? [];

      yield assert(
         assertion: $require['error'] === ''
            && ($only['first_hex'] ?? '') === '16'
            && count($require['connections']) === 1
            && str_contains($require['client_error'], 'Database operation timed out after 1 seconds') === true
            && str_contains($require['client_error'], 'handshake timed out') === false
            && str_contains($require['client_error'], 'must not be empty') === false
            && $require['elapsed'] >= 1.0
            && $require['elapsed'] < 1.9,
         description: 'E) require with the shipped defaults sends the ClientHello once and, against a silent peer, fails at the OPERATION timeout (1s) with the generic timeout error — no handshake budget applies to a strict mode — '
            . json_encode($require)
      );

      // @@ E2) require, `timeout => 5`, a genuine TLS peer — the minted leaf
      //    for 127.0.0.1, trusted through the minted CA — whose ServerHello
      //    comes 1.3s late: past the 1s budget `prefer` fails at, inside the
      //    timeout. The handshake completes and the command runs over TLS:
      //    one contact, encrypted, PONG, nothing in plaintext.
      if ($minting !== '') {
         yield assert(
            assertion: true,
            description: "E2) slow-TLS leg not run — no throwaway PKI: {$minting}"
         );
      }
      else {
         $late = $drive('require', 'tls', 5.0, ['cafile' => $CA, 'certificate' => $server, 'delay' => 1.3, 'answer' => true, 'verify' => true]);
         $only = $late['connections'][0] ?? [];

         yield assert(
            assertion: $late['error'] === ''
               && ($only['first_hex'] ?? '') === '16'
               && ($only['auth_seen'] ?? true) === false
               && ($only['encrypted'] ?? false) === true
               && count($late['connections']) === 1
               && $late['response'] === 'PONG'
               && $late['downgrade'] === ''
               && $late['elapsed'] >= 1.3
               && $late['elapsed'] < 5.0,
            description: 'E2) require completes the handshake with a TLS peer whose ServerHello arrives 1.3s late under timeout 5 — no budget cuts a strict mode short — and PONGs over TLS — '
               . json_encode($late)
         );
      }

      // @@ F) require, shipped defaults, a peer that resets — the failure names the refusal, not a local path error
      $refused = $drive('require', 'reset', $timeout);
      $only = $refused['connections'][0] ?? [];

      yield assert(
         assertion: $refused['error'] === ''
            && ($only['first_hex'] ?? '') === '16'
            && count($refused['connections']) === 1
            && str_contains($refused['client_error'], 'handshake failed') === true
            && str_contains($refused['client_error'], 'reset the TLS handshake') === true
            && str_contains($refused['client_error'], 'must not be empty') === false,
         description: 'F) require with the shipped defaults sends the ClientHello once and fails on the refused handshake — '
            . json_encode($refused)
      );

      // @@ G) prefer, shipped defaults, a TLS peer with an untrusted certificate
      //    that serves RESP over it — the handshake completes: `prefer` verifies
      //    nothing, so a self-signed peer is a TLS peer like any other. One
      //    contact, encrypted, PONG over TLS, no plaintext AUTH, no downgrade.
      $untrusted = $drive('prefer', 'tls', $timeout, ['answer' => true]);
      $only = $untrusted['connections'][0] ?? [];

      yield assert(
         assertion: $untrusted['error'] === ''
            && ($only['first_hex'] ?? '') === '16'
            && ($only['auth_seen'] ?? true) === false
            && ($only['encrypted'] ?? false) === true
            && count($untrusted['connections']) === 1
            && $untrusted['response'] === 'PONG'
            && $untrusted['client_error'] === ''
            && $untrusted['downgrade'] === ''
            && $untrusted['elapsed'] < $timeout,
         description: 'G) prefer with the shipped defaults completes the handshake with an untrusted certificate — no verification — and PONGs over TLS: one contact, no plaintext AUTH, no downgrade — '
            . json_encode($untrusted)
      );

      // @@ G2) prefer opted into verification, the same untrusted peer — the
      //    peer DID answer TLS, so there is nothing to downgrade from: the
      //    operation fails on the verification and no plaintext contact follows
      $verified = $drive('prefer', 'tls', $timeout, ['verify' => true]);
      $only = $verified['connections'][0] ?? [];

      yield assert(
         assertion: $verified['error'] === ''
            && ($only['first_hex'] ?? '') === '16'
            && count($verified['connections']) === 1
            && $verified['response'] === null
            && str_contains($verified['client_error'], 'certificate verify failed') === true
            && $verified['elapsed'] < $timeout,
         description: 'G2) prefer with `verify` never downgrades on an untrusted certificate: one TLS contact, a failure naming the verification, no plaintext AUTH — '
            . json_encode($verified)
      );

      // @@ H) require opted into verification, the same untrusted peer — one
      //    contact, the abort names the verification, nothing plaintext
      $strict = $drive('require', 'tls', $timeout, ['verify' => true]);
      $only = $strict['connections'][0] ?? [];

      yield assert(
         assertion: $strict['error'] === ''
            && ($only['first_hex'] ?? '') === '16'
            && ($only['auth_seen'] ?? true) === false
            && count($strict['connections']) === 1
            && $strict['response'] === null
            && str_contains($strict['client_error'], 'certificate verify failed') === true,
         description: 'H) require with `verify` aborts on an untrusted certificate naming the verify failure: one contact, no plaintext — '
            . json_encode($strict)
      );

      // @@ I) prefer, a `cafile` that does not exist — refused before any socket:
      //    the exception names the path and the listener never sees a contact
      $missing = __DIR__ . '/fixtures/no-such-ca-' . bin2hex((string) getmypid()) . '.pem';
      $cafile = $drive('prefer', 'silent', $timeout, ['cafile' => $missing, 'verify' => true]);

      yield assert(
         assertion: $cafile['error'] === ''
            && $cafile['connections'] === []
            && $cafile['response'] === null
            && str_contains($cafile['client_error'], $missing) === true
            && $cafile['elapsed'] < $budget,
         description: 'I) a nonexistent cafile fails naming the path before any connection reaches the peer — '
            . json_encode($cafile)
      );

      // @@ J) the refusal is classified by SHAPE, not by the C library's words:
      //    under a translated locale the RST diagnostic is no longer English,
      //    and `prefer` must still read it as the refusal it is
      $previous = setlocale(LC_ALL, '0');
      $localized = setlocale(LC_ALL, 'pt_BR.UTF-8', 'pt_BR.utf8', 'de_DE.utf8', 'de_DE.UTF-8', 'fr_FR.utf8');
      if ($localized === false) {
         // ! Named, not skipped in silence: a host without a translated locale
         //   cannot run this leg, and the report must say so
         yield assert(
            assertion: true,
            description: 'J) locale leg not run — no translated locale (pt_BR, de_DE or fr_FR) is installed on this host'
         );
      }
      else {
         try {
            $translated = $drive('prefer', 'reset', $timeout);
         }
         finally {
            setlocale(LC_ALL, $previous === false ? 'C' : $previous);
         }
         $second = $translated['connections'][1] ?? [];

         yield assert(
            assertion: $translated['error'] === ''
               && ($second['first_hex'] ?? '') !== '' && ($second['first_hex'] ?? '') !== '16'
               && $translated['response'] === 'PONG'
               && is_string($translated['downgrade'])
               && str_contains($translated['downgrade'], 'SSL: ') === true
               && str_contains($translated['downgrade'], 'Connection reset by peer') === false,
            description: "J) under locale {$localized} the RST diagnostic is translated and prefer still downgrades on it — "
               . json_encode($translated)
         );
      }

      // @@ L) prefer, a peer that answers the ClientHello with part of a
      //    ServerHello and then closes — an on-path cut AFTER the peer spoke
      //    TLS. Not an escalation (the same attacker resets the ClientHello),
      //    but the record must name the shape it saw — a closed handshake —
      //    never a phase it cannot know.
      $cut = $drive('prefer', 'partial', $timeout);
      $first = $cut['connections'][0] ?? [];
      $second = $cut['connections'][1] ?? [];

      yield assert(
         assertion: $cut['error'] === ''
            && ($first['first_hex'] ?? '') === '16'
            && ($first['auth_seen'] ?? true) === false
            && ($second['first_hex'] ?? '') !== '' && ($second['first_hex'] ?? '') !== '16'
            && $cut['response'] === 'PONG'
            && is_string($cut['downgrade'])
            && str_contains($cut['downgrade'], 'closed the TLS handshake') === true
            && str_contains($cut['downgrade'], 'ClientHello') === false,
         description: 'L) a peer that closes after a partial ServerHello is recorded as closing the TLS handshake — never as acting on the ClientHello — '
            . json_encode($cut)
      );

      // @@ M) prefer, a `cafile` that exists and is readable but holds no
      //    certificate — this very file — against a genuine TLS peer, with
      //    E_WARNING masked the way the shipped `error off` masks it. OpenSSL
      //    fails to load the store BEFORE any ClientHello, and PHP reports it
      //    WITHOUT naming the function: a handler that kept only
      //    `stream_socket_enable_crypto` messages forwarded this one, read the
      //    empty diagnostic as the peer closing the connection, and sent AUTH
      //    in plaintext to a server that speaks TLS. The framework's own
      //    handler hid that while E_WARNING was reported — it turns the
      //    warning into an exception — so it is masked here on purpose.
      $reporting = error_reporting(E_ALL & ~E_WARNING);
      try {
         $store = $drive('prefer', 'tls', $timeout, ['cafile' => __FILE__, 'verify' => true]);
      }
      finally {
         error_reporting($reporting);
      }
      $only = $store['connections'][0] ?? [];

      yield assert(
         assertion: $store['error'] === ''
            && count($store['connections']) <= 1
            && ($only['first_hex'] ?? '') === ''
            && ($only['auth_seen'] ?? false) === false
            && $store['response'] === null
            && $store['downgrade'] === ''
            && str_contains($store['client_error'], 'handshake failed') === true
            && str_contains($store['client_error'], __FILE__) === true
            && $store['elapsed'] < $budget,
         description: 'M) with E_WARNING masked, a readable cafile that holds no certificate fails the handshake naming the store before any ClientHello — at most one contact, no plaintext AUTH, no downgrade — '
            . json_encode($store)
      );

      // @@ N) the SSL context the Connection builds: SNI carries host names
      //    only (RFC 6066 §3), so an IP-literal peer disables it and a host
      //    name enables it — read off the context, not inferred from the wire
      $Listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
      $address = is_resource($Listener) ? stream_socket_get_name($Listener, false) : false;
      $separator = is_string($address) ? strrpos($address, ':') : false;
      $listening = $separator === false ? 0 : (int) substr($address, $separator + 1);
      $contexts = [];
      foreach (['127.0.0.1', 'redis.example'] as $peer) {
         $Connection = new Connection(new Config([
            'driver' => 'redis',
            'host' => '127.0.0.1',
            'port' => $listening,
            'secure' => ['mode' => 'prefer', 'peer' => $peer],
         ]));
         try {
            $Connection->connect();
            $contexts[$peer] = $Connection->SSL;
         }
         catch (Throwable $Throwable) {
            $contexts[$peer] = ['error' => $Throwable->getMessage()];
         }
         finally {
            $Connection->disconnect();
         }
      }
      if (is_resource($Listener)) {
         fclose($Listener);
      }

      yield assert(
         assertion: ($contexts['127.0.0.1']['SNI_enabled'] ?? null) === false
            && ($contexts['127.0.0.1']['peer_name'] ?? null) === '127.0.0.1'
            && ($contexts['redis.example']['SNI_enabled'] ?? null) === true
            && ($contexts['redis.example']['peer_name'] ?? null) === 'redis.example',
         description: 'N) the SSL context disables SNI for an IP-literal peer and enables it for a host name — '
            . json_encode($contexts)
      );

      // @@ O) prefer, the minted leaf whose CN is `wrong version number` — one
      //    of OpenSSL's refusal strings — trusted through the minted CA, but
      //    against a `peer` it does not name: the chain verifies and the NAME
      //    check fails, with a diagnostic that quotes the CN the peer chose
      //    and carries no library section. A classifier scanning the whole
      //    diagnostic read that CN as the peer refusing TLS and downgraded —
      //    AUTH in plaintext, to a peer that speaks TLS. A peer name mismatch
      //    is a handshake the peer DID answer: one contact, an abort naming
      //    the mismatch, nothing plaintext, no downgrade.
      if ($minting !== '') {
         yield assert(
            assertion: true,
            description: "O) peer-name-mismatch leg not run — no throwaway PKI: {$minting}"
         );
      }
      else {
         // @@ Three CNs the peer chose: a refusal string, the library anchor
         //    followed by one, and the transport anchor — none may reach the classifier
         foreach ([
            'mismatch' => 'wrong version number',
            'anchor' => 'OpenSSL Error messages: wrong version number',
            'transport' => 'stream_socket_enable_crypto(): SSL: x',
         ] as $leaf => $CN) {
            $named = $drive('prefer', 'tls', $timeout, ['cafile' => $CA, 'certificate' => (string) $PKI->fetch($leaf, ''), 'peer' => 'redis.example', 'verify' => true]);
            $only = $named['connections'][0] ?? [];

            yield assert(
               assertion: $named['error'] === ''
                  && ($only['first_hex'] ?? '') === '16'
                  && ($only['auth_seen'] ?? true) === false
                  && count($named['connections']) === 1
                  && $named['response'] === null
                  && $named['downgrade'] === ''
                  && str_contains($named['client_error'], 'handshake failed') === true
                  && str_contains($named['client_error'], 'did not match expected CN') === true
                  && str_contains($named['client_error'], $CN) === true,
               description: "O) prefer never downgrades on a peer name mismatch whose CN is `{$CN}`: one contact, no plaintext AUTH, no downgrade, an abort naming the mismatch — "
                  . json_encode($named)
            );
         }
      }

      // @@ P) the downgrade record is per generation: `prefer` downgrades once
      //    (a peer that resets) and PONGs in plaintext, then opens a fresh
      //    connection — on the SAME driver, as fallback() itself reopens one —
      //    against a peer that now completes TLS and answers over it. The
      //    record kept for the plaintext generation must not outlive it: the
      //    TLS generation reads as empty.
      if ($minting !== '') {
         yield assert(
            assertion: true,
            description: "P) downgrade-reset leg not run — no throwaway PKI: {$minting}"
         );
      }
      else {
         $again = $drive('prefer', 'reset,tls', $timeout, ['cafile' => $CA, 'certificate' => $server, 'answer' => true, 'rounds' => 2, 'verify' => true]);
         $first = $again['rounds'][0] ?? [];
         $second = $again['rounds'][1] ?? [];
         $third = $again['connections'][2] ?? [];

         yield assert(
            assertion: $again['error'] === ''
               && count($again['connections']) === 3
               && ($first['response'] ?? null) === 'PONG'
               && is_string($first['downgrade'] ?? null)
               && str_contains($first['downgrade'] ?? '', 'reset the TLS handshake') === true
               && ($second['response'] ?? null) === 'PONG'
               && ($second['downgrade'] ?? null) === ''
               && ($third['first_hex'] ?? '') === '16'
               && ($third['encrypted'] ?? false) === true
               && ($third['auth_seen'] ?? true) === false,
            description: 'P) a downgrade recorded for one plaintext generation is cleared by the next TLS attempt on the same driver: round 1 downgrades on a reset and PONGs in plaintext, round 2 completes TLS and PONGs with an empty record — '
               . json_encode($again)
         );
      }

      // @@ Q) prefer, a port nobody listens on — the dial is refused before any
      //    handshake. Read at the handshake, the refusal was `SSL: Connection
      //    refused`, the transport shape of a reset ClientHello: `prefer`
      //    recorded a TLS refusal that never happened and spent a plaintext
      //    retry on the closed port. It is a connection failure: no contact,
      //    an abort naming the refused connection, no downgrade, at once.
      $closed = $drive('prefer', 'silent', $timeout, ['closed' => true]);

      yield assert(
         assertion: $closed['error'] === ''
            && $closed['connections'] === []
            && $closed['response'] === null
            && $closed['downgrade'] === ''
            && str_contains($closed['client_error'], 'connection failed') === true
            && str_contains($closed['client_error'], 'refused') === true
            && str_contains($closed['client_error'], 'TLS handshake') === false
            && $closed['elapsed'] < $budget,
         description: 'Q) a closed port is a connection failure, not a TLS refusal: no downgrade, an abort naming the refused connection and never the handshake, before the budget — '
            . json_encode($closed)
      );

      // @@ K) live lane — a real plaintext Redis named by REDIS_PORT: the default
      //    config must FAIL at the budget naming `disable`, and `disable` must PONG
      if ($port < 1) {
         return;
      }
      $Probe = @fsockopen($host, $port, $errorCode, $error, 0.2);
      if (is_resource($Probe) === false) {
         yield assert(
            assertion: false,
            description: "REDIS_PORT names {$host}:{$port} but nothing answers there: {$error}"
         );

         return;
      }
      fclose($Probe);

      $clientError = '';
      $response = null;
      $Live = new KV(['driver' => 'redis', 'host' => $host, 'port' => $port, 'timeout' => $timeout]);
      $start = microtime(true);
      try {
         $response = $Live->await($Live->command('PING'))->response;
      }
      catch (Throwable $Throwable) {
         $clientError = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         $Live->Connection->disconnect();
      }
      $elapsed = round(microtime(true) - $start, 3);

      yield assert(
         assertion: $response === null
            && str_contains($clientError, "did not answer the TLS handshake within {$budget}s") === true
            && str_contains($clientError, "secure.mode => 'disable'") === true
            && $elapsed >= $budget
            && $elapsed < 2.0,
         description: "K) the default config fails against a real plaintext Redis at {$host}:{$port} at the {$budget}s budget, naming `disable` — elapsed {$elapsed}s, response "
            . var_export($response, true) . ", error `{$clientError}`"
      );

      $clientError = '';
      $response = null;
      $Plain = new KV(['driver' => 'redis', 'host' => $host, 'port' => $port, 'timeout' => $timeout, 'secure' => ['mode' => 'disable']]);
      $start = microtime(true);
      try {
         $response = $Plain->await($Plain->command('PING'))->response;
      }
      catch (Throwable $Throwable) {
         $clientError = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         $Plain->Connection->disconnect();
      }
      $elapsed = round(microtime(true) - $start, 3);

      yield assert(
         assertion: $response === 'PONG' && $elapsed < 0.5,
         description: "K) `disable` PINGs the same plaintext Redis at once — elapsed {$elapsed}s, response "
            . var_export($response, true) . ", error `{$clientError}`"
      );
   },
   Fixture: $PKI,
);
