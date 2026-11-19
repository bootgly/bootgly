<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Resources\Cache;


use const DIRECTORY_SEPARATOR;
use const SIGKILL;
use function array_diff;
use function array_slice;
use function assert;
use function extension_loaded;
use function fclose;
use function fread;
use function function_exists;
use function fwrite;
use function getmypid;
use function implode;
use function ini_get;
use function ini_set;
use function pcntl_fork;
use function pcntl_waitpid;
use function posix_kill;
use function print_r;
use function str_contains;
use function stream_set_timeout;
use function stream_socket_accept;
use function stream_socket_client;
use function stream_socket_get_name;
use function stream_socket_server;
use function strrpos;
use function substr;
use Closure;
use ReflectionParameter;
use RuntimeException;
use SensitiveParameter;
use SensitiveParameterValue;
use stdClass;
use Throwable;

use Bootgly\ABI\Data\RESP\Decoder;
use Bootgly\ABI\Data\RESP\Encoder;
use Bootgly\ABI\Debugging\Data\Vars\Dumper;
use Bootgly\ABI\Resources\Cache;
use Bootgly\ABI\Resources\Cache\Drivers\Redis;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * A Redis server that refuses the password (`-WRONGPASS`) makes the AUTH round trip throw
 * from under the frames that carry the password: the RESP command (`dispatch()`), its wire
 * bytes (`write()`) and their encoding (`Encoder::encode()`). A forked fake Redis on
 * 127.0.0.1 refuses it while traces hold every argument, uncapped.
 *
 * The facade (`Cache->fetch()`) is driven for real on the transport this process has:
 * ext-redis, when it is loaded (its `Redis::auth()` marks the password itself), else the
 * native RESP one. ext-redis keeps `connect()` off the native transport in-process, so the
 * native AUTH is also driven through the state `connect()` leaves and the very call it
 * makes (`dispatch(['AUTH', …])`), the outermost frame reachable here — and the attributes
 * are pinned by reflection.
 */
return new Test(
   description: 'Cache(Redis): a refused AUTH keeps the password out of the stack trace',
   skip: DIRECTORY_SEPARATOR === '\\'
      || function_exists('pcntl_fork') === false
      || function_exists('posix_kill') === false,
   test: function () {
      // ! Traces at their fullest — every argument, no string cap
      $ignore = ini_get('zend.exception_ignore_args');
      $length = ini_get('zend.exception_string_param_max_len');
      $ignored = ini_set('zend.exception_ignore_args', '0');
      $capped = ini_set('zend.exception_string_param_max_len', '1000000');
      try {
         yield assert(
            assertion: $ignored !== false && $capped !== false,
            description: 'This spec raises traces to every argument, uncapped'
         );

         // ! The renderers of a caught trace
         $Dumper = new Dumper('plain');
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
         $Renderers = [
            'print_r' => static fn (Throwable $Throwable): string => print_r($Frames($Throwable), true),
            'getTraceAsString' => static fn (Throwable $Throwable): string => $Throwable->getTraceAsString(),
            'Dumper' => static fn (Throwable $Throwable): string => $Dumper->dump($Frames($Throwable)),
         ];
         // ! The frames of a trace that hold a SensitiveParameterValue, and the renderers that show the secret
         $Inspect = static function (Throwable $Thrown, string $secret) use ($Renderers): array {
            $redacted = [];
            foreach ($Thrown->getTrace() as $frame) {
               foreach ($frame['args'] ?? [] as $argument) {
                  if ($argument instanceof SensitiveParameterValue) {
                     $redacted[] = ($frame['class'] ?? '') . ($frame['type'] ?? '') . $frame['function'];
                  }
               }
            }
            $leaks = [];
            foreach ($Renderers as $renderer => $Renderer) {
               if (str_contains($Renderer($Thrown), $secret) === true) {
                  $leaks[] = $renderer;
               }
            }

            return [$redacted, $leaks];
         };
         // ! A fake Redis on 127.0.0.1: it reads one command and refuses it as a wrong password
         $Serve = static function (): array {
            $Server = stream_socket_server('tcp://127.0.0.1:0', $code, $reason);
            if ($Server === false) {
               throw new RuntimeException("The fake Redis cannot listen on 127.0.0.1 ({$reason}).");
            }
            $name = (string) stream_socket_get_name($Server, false);
            $port = (int) substr($name, (int) strrpos($name, ':') + 1);

            $PID = pcntl_fork();
            // ? A failed fork never hands back -1: a signal to it would reach every process we own
            if ($PID < 0) {
               fclose($Server);

               throw new RuntimeException('The fake Redis cannot fork.');
            }
            // @ Child: refuse, wait for the hang-up, then die without the parent's shutdown
            //   functions and output buffers (the test report)
            if ($PID === 0) {
               $Peer = @stream_socket_accept($Server, 5);
               if ($Peer !== false) {
                  stream_set_timeout($Peer, 5);
                  @fread($Peer, 8192);
                  @fwrite($Peer, "-WRONGPASS invalid username-password pair or user is disabled.\r\n");
                  @fread($Peer, 8192);
               }
               posix_kill((int) getmypid(), SIGKILL);
               exit(0);
            }
            fclose($Server);

            return [$port, $PID];
         };
         $Reap = static function (int $PID): void {
            posix_kill($PID, SIGKILL);
            pcntl_waitpid($PID, $status);
         };
         $password = 'SYNTH-REDIS-PASSWORD-0123456789';
         $dispatch = Redis::class . '->dispatch';

         // # Positive control: an unmarked frame given the password shows it everywhere
         $Control = null;
         try {
            (static function (string $secret): never {
               throw new RuntimeException('Unmarked control.');
            })($password);
         }
         catch (RuntimeException $Exception) {
            $Control = $Exception;
         }
         $blind = [];
         foreach ($Renderers as $renderer => $Renderer) {
            if (str_contains($Renderer($Control), $password) === false) {
               $blind[] = $renderer;
            }
         }
         $unseen = implode(', ', $blind);
         yield assert(
            assertion: $blind === [],
            description: "An unmarked frame shows the password in every renderer (blind: [{$unseen}])"
         );

         // # The facade, on the transport this process has
         $native = extension_loaded('redis') === false;
         [$port, $PID] = $Serve();
         $Thrown = null;
         try {
            $Cache = new Cache([
               'driver' => 'redis',
               'host' => '127.0.0.1',
               'port' => $port,
               'password' => $password,
               'timeout' => 2.0
            ]);
            $Cache->fetch('key');
         }
         catch (Throwable $Throwable) {
            $Thrown = $Throwable;
         }
         finally {
            unset($Cache);
            $Reap($PID);
         }
         $transport = $native ? 'the native transport' : 'ext-redis';
         // ? ext-redis marks the password of its own `auth()` (this one does, by reflection): a
         //   phpredis that does not is a frame of the extension, not of the framework
         $marking = $native === true
            || (new ReflectionParameter(['Redis', 'auth'], 0))->getAttributes(SensitiveParameter::class) !== [];
         if ($marking === false) {
            yield (new Assertion(
               description: 'The facade on ext-redis is not asserted: this phpredis does not mark the password of Redis::auth()'
            ))->skip();
         }
         else {
            [$redacted, $leaks] = $Thrown !== null ? $Inspect($Thrown, $password) : [[], []];
            $expected = $native ? $dispatch : 'Redis->auth';
            $unmarked = implode(', ', array_diff([$expected], $redacted));
            $leaked = implode(', ', $leaks);
            yield assert(
               assertion: $Thrown !== null
                  && str_contains($Thrown->getMessage(), 'WRONGPASS') === true
                  && $unmarked === ''
                  && $leaks === [],
               description: "Cache->fetch() on {$transport}: the refused AUTH is thrown, its frame redacts "
                  . "the password (unmarked: [{$unmarked}]) and no renderer shows it (leaked: [{$leaked}])"
            );
         }
         if ($native === false) {
            yield (new Assertion(
               description: 'connect() takes ext-redis in this process: the native AUTH runs below, from the call connect() makes'
            ))->skip();
         }

         // # The native AUTH: the state connect() leaves on that transport, then its call
         [$port, $PID] = $Serve();
         $Thrown = null;
         try {
            $Socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $reason, 2.0);
            if ($Socket === false) {
               throw new RuntimeException("The fake Redis cannot be reached ({$reason}).");
            }
            $Authenticate = Closure::bind(
               static function (Redis $Driver, $Socket) use ($password): mixed {
                  $Driver->Socket = $Socket;
                  $Driver->Encoder = new Encoder;
                  $Driver->Decoder = new Decoder;
                  $Driver->ext = false;
                  $Driver->connected = true;

                  return $Driver->dispatch(['AUTH', $password]);
               },
               null,
               Redis::class
            );
            $Authenticate(new Redis(new Config(['driver' => 'redis', 'password' => $password])), $Socket);
         }
         catch (Throwable $Throwable) {
            $Thrown = $Throwable;
         }
         finally {
            $Reap($PID);
         }
         [$redacted, $leaks] = $Thrown !== null ? $Inspect($Thrown, $password) : [[], []];
         $unmarked = implode(', ', array_diff([$dispatch], $redacted));
         $leaked = implode(', ', $leaks);
         yield assert(
            assertion: $Thrown !== null
               && str_contains($Thrown->getMessage(), 'WRONGPASS') === true
               && $unmarked === ''
               && $leaks === [],
            description: 'The native AUTH refused by the server: dispatch() redacts the command '
               . "(unmarked: [{$unmarked}]) and no renderer shows the password (leaked: [{$leaked}])"
         );

         // # The native AUTH with no socket: write() refuses the RESP bytes
         $Thrown = null;
         try {
            $Orphan = Closure::bind(
               static function (Redis $Driver) use ($password): mixed {
                  $Driver->Socket = null;
                  $Driver->Encoder = new Encoder;
                  $Driver->Decoder = new Decoder;
                  $Driver->ext = false;
                  $Driver->connected = true;

                  return $Driver->dispatch(['AUTH', $password]);
               },
               null,
               Redis::class
            );
            $Orphan(new Redis(new Config(['driver' => 'redis', 'password' => $password])));
         }
         catch (RuntimeException $Exception) {
            $Thrown = $Exception;
         }
         [$redacted, $leaks] = $Thrown !== null ? $Inspect($Thrown, $password) : [[], []];
         $unmarked = implode(', ', array_diff([Redis::class . '->write', $dispatch], $redacted));
         $leaked = implode(', ', $leaks);
         yield assert(
            assertion: $Thrown !== null
               && $Thrown->getMessage() === 'Redis socket is not connected.'
               && $unmarked === ''
               && $leaks === [],
            description: 'The native AUTH with no socket: write() and dispatch() redact the bytes and the command '
               . "(unmarked: [{$unmarked}]) and no renderer shows the password (leaked: [{$leaked}])"
         );

         // # The encoding of the AUTH command (a value it cannot encode throws in its frame)
         $Thrown = null;
         try {
            // @phpstan-ignore argument.type (an object argument is the throw under test)
            (new Encoder)->encode(['AUTH', $password, new stdClass]);
         }
         catch (Throwable $Throwable) {
            $Thrown = $Throwable;
         }
         [$redacted, $leaks] = $Thrown !== null ? $Inspect($Thrown, $password) : [[], []];
         $unmarked = implode(', ', array_diff([Encoder::class . '->encode'], $redacted));
         $leaked = implode(', ', $leaks);
         yield assert(
            assertion: $Thrown !== null && $unmarked === '' && $leaks === [],
            description: 'Encoder->encode() redacts the command it encodes '
               . "(unmarked: [{$unmarked}]) and no renderer shows the password (leaked: [{$leaked}])"
         );
      }
      finally {
         ini_set('zend.exception_ignore_args', (string) $ignore);
         ini_set('zend.exception_string_param_max_len', (string) $length);
      }

      // # The attributes, pinned
      $sensitive = static fn (string $class, string $method, string $parameter): bool => (new ReflectionParameter([$class, $method], $parameter))
         ->getAttributes(SensitiveParameter::class) !== [];
      yield assert(
         assertion: $sensitive(Redis::class, 'dispatch', 'arguments') === true
            && $sensitive(Redis::class, 'write', 'payload') === true
            && $sensitive(Encoder::class, 'encode', 'command') === true,
         description: 'Redis::dispatch(), Redis::write() and RESP\Encoder::encode() mark what they carry as sensitive'
      );
   }
);
