<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Queues;


use const DIRECTORY_SEPARATOR;
use const SIGKILL;
use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;
use function array_slice;
use function assert;
use function count;
use function extension_loaded;
use function fclose;
use function fread;
use function function_exists;
use function fwrite;
use function implode;
use function ini_get;
use function ini_set;
use function is_resource;
use function pcntl_fork;
use function pcntl_waitpid;
use function posix_getpid;
use function posix_kill;
use function print_r;
use function str_contains;
use function stream_set_timeout;
use function stream_socket_accept;
use function stream_socket_get_name;
use function stream_socket_pair;
use function stream_socket_server;
use function strrpos;
use function substr;
use Closure;
use Redis as RedisClient;
use ReflectionParameter;
use RuntimeException;
use SensitiveParameter;
use SensitiveParameterValue;
use Throwable;

use Bootgly\ABI\Data\RESP\Decoder;
use Bootgly\ABI\Data\RESP\Encoder;
use Bootgly\ABI\Debugging\Data\Vars\Dumper;
use Bootgly\ACI\Queues;
use Bootgly\ACI\Queues\Drivers\Redis;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * The native RESP lane (no ext-redis) authenticates with
 * `dispatch(['AUTH', <password>])`, which hands the encoded bytes to `write()`
 * and waits in `read()`: a refused AUTH throws from under `dispatch()`, a dead
 * socket from under `write()` too. With `zend.exception_ignore_args=0` (the
 * development and CI setting) a trace holds every argument of every frame, so
 * neither may show the password.
 *
 * Where ext-redis is loaded, `connect()` takes the ext-redis lane instead
 * (`Redis->auth()` marks its own credential) and the real chain runs through
 * it; the native frames are driven directly in every run — `dispatch()` on a
 * native-lane driver over a socket pair.
 */
return new Test(
   description: 'Queues(Redis): the AUTH password stays out of the stack trace — the native RESP frames and the real connect() chain',
   skip: DIRECTORY_SEPARATOR === '\\'
      || function_exists('pcntl_fork') === false
      || function_exists('posix_kill') === false,
   test: function () {
      // ! SYNTH credential — never a real one — and its AUTH bytes on the wire
      $password = 'SYNTH-QUEUES-PASSWORD-4.5';
      $payload = (new Encoder)->encode(['AUTH', $password]);
      $refusal = "-WRONGPASS invalid username-password pair or user is disabled.\r\n";
      $Dumper = new Dumper('plain');
      // ! No truncation may hide a leak
      $Dumper->strings = 1_000_000;
      // ! The trace's own frames: every frame up to the last call made from this file — the
      //   runner's frames below it hold the suite state, which a full run makes unbounded
      $Frames = static function (Throwable $Throwable): array {
         $Trace = $Throwable->getTrace();
         $last = 0;
         foreach ($Trace as $index => $Frame) {
            if (($Frame['file'] ?? '') === __FILE__) {
               $last = $index;
            }
         }

         return array_slice($Trace, 0, $last + 1);
      };
      // ! Each channel a trace reaches
      $Channels = [
         'print_r' => static fn (Throwable $Throwable): string => print_r($Frames($Throwable), true),
         'getTraceAsString' => static fn (Throwable $Throwable): string => $Throwable->getTraceAsString(),
         'Dumper' => static fn (Throwable $Throwable): string => $Dumper->dump($Frames($Throwable)),
      ];
      // ! The channels a trace shows the password in
      $Find = static function (null|Throwable $Throwable) use ($Channels, $password): array {
         $found = [];
         foreach ($Channels as $channel => $Channel) {
            $output = $Throwable === null ? '' : $Channel($Throwable);

            if (str_contains($output, $password) === true) {
               $found[] = $channel;
            }
         }

         return $found;
      };
      // ! The first argument of the first frame that runs $class->$function
      $Argument = static function (null|Throwable $Throwable, string $class, string $function): mixed {
         foreach ($Throwable?->getTrace() ?? [] as $Frame) {
            if (($Frame['class'] ?? '') === $class && $Frame['function'] === $function) {
               return $Frame['args'][0] ?? null;
            }
         }

         return null;
      };
      // ! Whether a frame argument was redacted — and held exactly $value
      $Redacted = static fn (mixed $Argument, mixed $value): bool => $Argument instanceof SensitiveParameterValue
         && $Argument->getValue() === $value;
      // ! Send AUTH through dispatch() of a native-lane driver bound to $Socket
      $Dispatch = static function (mixed $Socket) use ($password): null|Throwable {
         // ? Only an open stream can carry the AUTH
         if (is_resource($Socket) === false) {
            return null;
         }

         $Driver = new Redis(new Config(['driver' => 'redis', 'password' => $password]));
         $Authenticate = Closure::bind(function () use ($Socket): mixed {
            $this->Socket = $Socket;
            $this->Encoder = new Encoder;
            $this->Decoder = new Decoder;

            return $this->dispatch(['AUTH', $this->Config->password]);
         }, $Driver, Redis::class);

         try {
            $Authenticate();
         }
         catch (Throwable $Throwable) {
            return $Throwable;
         }

         return null;
      };

      // @ Every native frame the password passes through marks it (attributes are not inherited)
      $Marked = static fn (string $method, string $parameter): bool => (new ReflectionParameter([Redis::class, $method], $parameter))
         ->getAttributes(SensitiveParameter::class) !== [];
      yield assert(
         assertion: $Marked('dispatch', 'arguments') === true && $Marked('write', 'payload') === true,
         description: 'dispatch() and write() mark the AUTH arguments and bytes #[SensitiveParameter]'
      );

      // ! Traces with arguments, at full length — the development and CI setting
      $ignore = ini_get('zend.exception_ignore_args');
      $length = ini_get('zend.exception_string_param_max_len');
      $ignoring = ini_set('zend.exception_ignore_args', '0');
      $lengthening = ini_set('zend.exception_string_param_max_len', '1000000');
      try {
         yield assert(
            assertion: $ignoring !== false && $lengthening !== false,
            description: 'The run takes traces with arguments at full length'
         );

         // @ Positive control: an unmarked frame shows the same values in every channel
         $Unmarked = static function (array $arguments, string $bytes): never {
            throw new RuntimeException('unmarked');
         };
         $Control = null;
         try {
            $Unmarked(['AUTH', $password], $payload);
         }
         catch (RuntimeException $Exception) {
            $Control = $Exception;
         }
         $shown = $Find($Control);
         $listed = implode(', ', $shown);
         yield assert(
            assertion: count($shown) === count($Channels),
            description: "An unmarked frame shows the password in each channel — the trace settings took effect (shown: [{$listed}])"
         );

         // # A dead peer: write() fails under dispatch(AUTH)
         $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         $Written = null;
         if ($Pair !== false) {
            fclose($Pair[1]);
            $Written = $Dispatch($Pair[0]);
         }
         yield assert(
            assertion: $Written?->getMessage() === 'Redis socket write failed.'
               && $Redacted($Argument($Written, Redis::class, 'write'), $payload) === true
               && $Redacted($Argument($Written, Redis::class, 'dispatch'), ['AUTH', $password]) === true,
            description: 'A dead socket throws from under write() and dispatch(), each argument a SensitiveParameterValue holding the AUTH'
         );
         $leaked = implode(', ', $Find($Written));
         yield assert(
            assertion: $leaked === '',
            description: "write() failure: the password reaches no print_r(getTrace()), getTraceAsString() or Dumper (leaked via: [{$leaked}])"
         );

         // # A refused AUTH: read() throws the WRONGPASS reply under dispatch(AUTH)
         $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         $Refused = null;
         if ($Pair !== false) {
            fwrite($Pair[1], $refusal);
            $Refused = $Dispatch($Pair[0]);
            fclose($Pair[1]);
         }
         yield assert(
            assertion: $Refused instanceof RuntimeException
               && str_contains($Refused->getMessage(), 'WRONGPASS') === true
               && $Redacted($Argument($Refused, Redis::class, 'dispatch'), ['AUTH', $password]) === true,
            description: 'A refused AUTH throws from under dispatch(), its argument a SensitiveParameterValue holding the AUTH'
         );
         $leaked = implode(', ', $Find($Refused));
         yield assert(
            assertion: $leaked === '',
            description: "Refused AUTH: the password reaches no print_r(getTrace()), getTraceAsString() or Dumper (leaked via: [{$leaked}])"
         );

         // # The real chain: Queues->fetch()->count() -> connect() -> AUTH, refused
         //   Native lane: dispatch() -> read(); ext-redis lane (when loaded): Redis->auth()
         $native = extension_loaded('redis') === false;
         $Listener = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
         $address = $Listener === false ? '' : (string) stream_socket_get_name($Listener, false);
         $port = (int) substr($address, (int) strrpos($address, ':') + 1);
         $PID = $Listener === false ? -1 : pcntl_fork();
         if ($PID === 0) {
            // ! A fake Redis on an ephemeral port: it refuses the AUTH, then waits for the hang-up
            $Peer = @stream_socket_accept($Listener, 5.0);
            if ($Peer !== false) {
               stream_set_timeout($Peer, 5);
               @fread($Peer, 8192);
               @fwrite($Peer, $refusal);
               @fread($Peer, 8192);
               @fclose($Peer);
            }

            // ! Hard exit — no shutdown handlers, no inherited output flush
            posix_kill(posix_getpid(), SIGKILL);
            exit(0);
         }
         if ($Listener !== false) {
            @fclose($Listener);
         }
         yield assert(
            assertion: $PID > 0 && $port > 0,
            description: 'The fake Redis is up'
         );

         $Chained = null;
         try {
            (new Queues([
               'driver' => 'redis',
               'host' => '127.0.0.1',
               'port' => $port,
               'password' => $password,
               'timeout' => 2.0,
               'persistent' => false
            ]))->fetch('jobs')->count();
         }
         catch (RuntimeException $Exception) {
            $Chained = $Exception;
         }
         // ! Reap the fake Redis (only a child this test forked)
         if ($PID > 0) {
            pcntl_waitpid($PID, $status);
         }

         // ! The frame that holds the AUTH: the driver's dispatch() natively, ext-redis's own auth() otherwise
         $lane = $native === true ? 'native lane: dispatch()' : 'ext-redis lane: Redis->auth()';
         $Authenticating = $native === true
            ? $Argument($Chained, Redis::class, 'dispatch')
            : $Argument($Chained, RedisClient::class, 'auth');
         $authentication = $native === true ? ['AUTH', $password] : $password;
         yield assert(
            assertion: $Chained !== null
               && str_contains($Chained->getMessage(), 'WRONGPASS') === true
               && $Redacted($Authenticating, $authentication) === true
               && str_contains($Chained->getTraceAsString(), 'Drivers\Redis->connect()') === true,
            description: "The real chain throws from under connect() ({$lane}), the AUTH argument a SensitiveParameterValue"
         );
         $leaked = implode(', ', $Find($Chained));
         yield assert(
            assertion: $leaked === '',
            description: "Real chain ({$lane}): the password reaches no print_r(getTrace()), getTraceAsString() or Dumper (leaked via: [{$leaked}])"
         );
      }
      finally {
         ini_set('zend.exception_ignore_args', (string) $ignore);
         ini_set('zend.exception_string_param_max_len', (string) $length);
      }
   }
);
