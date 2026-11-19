<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Tests;


use function array_slice;
use function assert;
use function bin2hex;
use function chmod;
use function count;
use function file_put_contents;
use function implode;
use function ini_get;
use function ini_set;
use function is_dir;
use function is_file;
use function is_link;
use function print_r;
use function random_bytes;
use function rmdir;
use function scandir;
use function str_contains;
use function str_repeat;
use function sys_get_temp_dir;
use function touch;
use function unlink;
use Closure;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionParameter;
use ReflectionProperty;
use RuntimeException;
use SensitiveParameter;
use SensitiveParameterValue;
use Throwable;
use TypeError;

use Bootgly\ABI\Debugging\Data\Vars\Dumper;
use Bootgly\ABI\Resources\Cache as CacheResource;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Handler;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Handlers\Cache;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Handlers\File;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Handling;


/**
 * A session ID is the bearer credential of a session, and the session payload
 * carries what the application keeps in it — the CSRF token among them. Both
 * pass through the Session and through every frame of its handler, and the
 * Cache handler's config may carry its key. With `zend.exception_ignore_args=0`
 * (the development and CI setting) a trace holds every argument of every
 * frame, so no frame they pass through may show them.
 *
 * The real chains throw in-process: a Cache handler key shorter than 32 bytes
 * (refused under the Session constructor), a backend that fails every call
 * (a double standing in for the cache store, its own frames marked), and File
 * handler locks with unsafe metadata. `rotate()` and the private `decode()`
 * never throw for a string: a TypeError raised at the marked parameter throws
 * through them. Every marked frame is also pinned by reflection.
 *
 * The backend double marks its own frames, so the Cache handler cases pin the
 * Session and handler frames only. Below the handler, the real `Cache`
 * resource and its drivers take the session ID as an unmarked cache key: a
 * trace raised there still shows it (a recorded residual).
 */
return new Test(
   description: 'Session: the session ID, the payload and the Cache handler key stay out of every stack trace',
   test: function () {
      // ! SYNTH values — the session ID is hex, as the handlers require
      $id = bin2hex(random_bytes(20));
      $data = 'SYNTH-SESSION-PAYLOAD-6.2';
      $short = 'SYNTH-SESSION-SHORT-KEY-6.2';
      $rotated = 'SYNTH-SESSION-ROTATED-ID-6.2';
      $payload = 'SYNTH-SESSION-DECODED-PAYLOAD-6.2';
      $Dumper = new Dumper('plain');
      // ! No limit may hide a leak
      $Dumper->depth = 32;
      $Dumper->strings = 1_000_000;
      $Dumper->items = 10_000;
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
      /**
       * ! What a chain got wrong: an unmarked frame argument, a value a channel shows
       *
       * @param array<int,array{0:class-string,1:string,2:int}> $Marked
       * @param array<string,string> $secrets
       *
       * @return array<int,string>
       */
      $Check = static function (null|Throwable $Thrown, array $Marked, array $secrets) use ($Channels): array {
         // ?
         if ($Thrown === null) {
            return ['nothing was thrown'];
         }

         $wrong = [];
         // @@ Each frame the secret passes through
         foreach ($Marked as [$class, $function, $position]) {
            $redacted = false;
            foreach ($Thrown->getTrace() as $Frame) {
               if (($Frame['class'] ?? '') === $class && $Frame['function'] === $function) {
                  $redacted = ($Frame['args'][$position] ?? null) instanceof SensitiveParameterValue;
                  break;
               }
            }
            if ($redacted === false) {
               $wrong[] = "{$class}::{$function}() argument #{$position} is not a SensitiveParameterValue";
            }
         }
         // @@ Each channel
         foreach ($Channels as $channel => $Channel) {
            $output = $Channel($Thrown);

            foreach ($secrets as $name => $secret) {
               if (str_contains($output, $secret) === true) {
                  $wrong[] = "the {$name} via {$channel}";
               }
            }
         }

         return $wrong;
      };

      // @ Every frame a session ID, a payload or a key passes through marks it
      //   (attributes are not inherited — each frame is pinned)
      $Parameters = [
         [Session::class, '__construct', 'id'],
         [Session::class, 'rotate', 'newId'],
         [Session::class, 'decode', 'payload'],
         [Cache::class, '__construct', 'config'],
         [Cache::class, 'read', 'sessionId'],
         [Cache::class, 'fetch', 'sessionId'],
         [Cache::class, 'write', 'sessionId'],
         [Cache::class, 'write', 'sessionData'],
         [Cache::class, 'commit', 'sessionId'],
         [Cache::class, 'commit', 'sessionData'],
         [Cache::class, 'revoke', 'sessionId'],
         [Cache::class, 'touch', 'sessionId'],
         [Cache::class, 'destroy', 'sessionId'],
         [Cache::class, 'seal', 'sessionData'],
         [Cache::class, 'seal', 'sessionId'],
         [Cache::class, 'open', 'sessionId'],
         [Cache::class, 'validate', 'sessionId'],
         [Cache::class, 'load', 'config'],
         [File::class, 'read', 'sessionId'],
         [File::class, 'fetch', 'sessionId'],
         [File::class, 'write', 'sessionId'],
         [File::class, 'write', 'sessionData'],
         [File::class, 'commit', 'sessionId'],
         [File::class, 'commit', 'sessionData'],
         [File::class, 'store', 'sessionId'],
         [File::class, 'store', 'sessionData'],
         [File::class, 'pack', 'sessionData'],
         [File::class, 'unpack', 'record'],
         [File::class, 'load', 'file'],
         [File::class, 'touch', 'sessionId'],
         [File::class, 'revoke', 'sessionId'],
         [File::class, 'destroy', 'sessionId'],
         [File::class, 'resolve', 'sessionId'],
         [File::class, 'locate', 'sessionId'],
         [File::class, 'persist', 'data'],
         [File::class, 'open', 'path'],
         [File::class, 'inspect', 'path'],
         [File::class, 'verify', 'path'],
         [File::class, 'compare', 'path'],
      ];
      $unmarked = [];
      foreach ($Parameters as [$class, $method, $parameter]) {
         $Parameter = new ReflectionParameter([$class, $method], $parameter);
         if ($Parameter->getAttributes(SensitiveParameter::class) === []) {
            $unmarked[] = "{$class}::{$method}() \${$parameter}";
         }
      }
      $listed = implode(', ', $unmarked);
      yield assert(
         assertion: $unmarked === [],
         description: "Session, the Cache handler and the File handler mark every session ID, payload and key parameter #[SensitiveParameter] (unmarked: [{$listed}])"
      );

      // ! State this spec changes, restored in the finally
      $handler = [Handler::$class, Handler::$config, Handler::$instance];
      $Statics = [];
      foreach (['path', 'secret', 'secretPath'] as $name) {
         $Static = (new ReflectionClass(File::class))->getProperty($name);
         $Statics[$name] = [$Static, $Static->getValue()];
      }
      $temporary = sys_get_temp_dir();
      $suffix = bin2hex(random_bytes(8));
      $root = "{$temporary}/bootgly-session-traces-{$suffix}";
      $Cleanup = null;
      $Cleanup = static function (string $path) use (&$Cleanup): void {
         if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
         }
         if (is_dir($path) === false) {
            return;
         }

         @chmod($path, 0700);
         foreach (@scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
               continue;
            }
            $Cleanup("{$path}/{$entry}");
         }
         @rmdir($path);
      };
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

         // @ Positive control: an unmarked frame shows every value in every channel
         $Unmarked = static function (mixed ...$values): never {
            throw new RuntimeException('unmarked');
         };
         $Control = null;
         try {
            $Unmarked([$id, $data, $short], $id, $data, $short);
         }
         catch (RuntimeException $Exception) {
            $Control = $Exception;
         }
         $shown = $Check($Control, [], ['session ID' => $id, 'payload' => $data, 'key' => $short]);
         $listed = implode(', ', $shown);
         yield assert(
            assertion: count($shown) === 3 * count($Channels),
            description: "An unmarked frame shows each value in each channel — the trace settings took effect (shown: [{$listed}])"
         );

         // @ Session constructor → Handler::init() → Cache handler constructor → load(): a short key
         // ! Triggers run inline: a frame that receives a closure would show what the closure captured
         Handler::$instance = null;
         Handler::$class = Cache::class;
         Handler::$config = ['secret' => $short];
         $Thrown = null;
         try {
            new Session($id);
         }
         catch (Throwable $Throwable) {
            $Thrown = $Throwable;
         }
         $wrong = $Check(
            $Thrown,
            [[Session::class, '__construct', 0], [Cache::class, '__construct', 0], [Cache::class, 'load', 0]],
            ['session ID' => $id, 'key' => $short]
         );
         $listed = implode(', ', $wrong);
         yield assert(
            assertion: $Thrown instanceof InvalidArgumentException
               && $Thrown->getMessage() === 'Session Cache secrets must contain at least 32 bytes.'
               && $wrong === [],
            description: "A refused Cache handler key shows neither the key nor the session ID from under the Session constructor (wrong: [{$listed}])"
         );
         [Handler::$class, Handler::$config, Handler::$instance] = $handler;

         // ! A Cache handler over a backend that fails every call
         $Backend = new class(['driver' => 'memory']) extends CacheResource {
            public function fetch (#[SensitiveParameter] string $key): mixed
            {
               throw new RuntimeException('The session backend is down.');
            }

            /** @param array<int,string> $tags */
            public function store (
               #[SensitiveParameter] string $key,
               #[SensitiveParameter] mixed $value,
               int $TTL = 0,
               array $tags = []
            ): bool
            {
               throw new RuntimeException('The session backend is down.');
            }

            /** @param array<int,string> $tags */
            public function create (
               #[SensitiveParameter] string $key,
               #[SensitiveParameter] mixed $value,
               int $TTL = 0,
               array $tags = []
            ): bool
            {
               throw new RuntimeException('The session backend is down.');
            }

            public function delete (#[SensitiveParameter] string $key): bool
            {
               throw new RuntimeException('The session backend is down.');
            }
         };
         $CacheHandler = new Cache([
            'secret' => 'SYNTH-SESSION-CACHE-KEY-6.2-0123456789',
            'driver' => 'memory'
         ]);
         (new ReflectionProperty(Cache::class, 'Cache'))->setValue($CacheHandler, $Backend);

         // @ Session constructor → Cache handler fetch() → the backend
         Handler::$instance = $CacheHandler;
         $Thrown = null;
         try {
            new Session($id);
         }
         catch (Throwable $Throwable) {
            $Thrown = $Throwable;
         }
         $wrong = $Check(
            $Thrown,
            [[Session::class, '__construct', 0], [Cache::class, 'fetch', 0]],
            ['session ID' => $id]
         );
         $listed = implode(', ', $wrong);
         yield assert(
            assertion: $Thrown instanceof RuntimeException
               && $Thrown->getMessage() === 'The session backend is down.'
               && $wrong === [],
            description: "A failing backend shows no session ID in the Session and Cache handler frames (wrong: [{$listed}])"
         );
         [Handler::$class, Handler::$config, Handler::$instance] = $handler;

         // @ Every Cache handler operation over the failing backend
         $Chains = [
            'write()' => [
               static fn () => $CacheHandler->write($id, $data),
               [[Cache::class, 'write', 0], [Cache::class, 'write', 1]]
            ],
            'commit()' => [
               static function () use ($CacheHandler, $id, $data): void {
                  $revision = null;
                  $CacheHandler->commit($id, $data, $revision);
               },
               [[Cache::class, 'commit', 0], [Cache::class, 'commit', 1]]
            ],
            'read()' => [
               static fn () => $CacheHandler->read($id),
               [[Cache::class, 'read', 0], [Cache::class, 'fetch', 0]]
            ],
            'touch()' => [
               static fn () => $CacheHandler->touch($id),
               [[Cache::class, 'touch', 0]]
            ],
            'revoke()' => [
               static fn () => $CacheHandler->revoke($id, str_repeat('0', 64)),
               [[Cache::class, 'revoke', 0]]
            ],
            'destroy()' => [
               static fn () => $CacheHandler->destroy($id),
               [[Cache::class, 'destroy', 0]]
            ],
         ];
         $wrong = [];
         foreach ($Chains as $chain => [$Trigger, $Marked]) {
            $Thrown = null;
            try {
               $Trigger();
            }
            catch (Throwable $Throwable) {
               $Thrown = $Throwable;
            }
            foreach ($Check($Thrown, $Marked, ['session ID' => $id, 'payload' => $data]) as $problem) {
               $wrong[] = "{$chain}: {$problem}";
            }
            if ($Thrown?->getMessage() !== 'The session backend is down.') {
               $wrong[] = "{$chain}: not the backend failure";
            }
         }
         $listed = implode(', ', $wrong);
         yield assert(
            assertion: $wrong === [],
            description: "Every Cache handler operation keeps the session ID and the payload out of its own frames (wrong: [{$listed}])"
         );

         // ! File handlers: one whose records lock has unsafe metadata, one whose
         //   HMAC key lock has — over a fresh record, so read() reaches the key
         foreach ($Statics as [$Static]) {
            $Static->setValue(null, '');
         }
         $Records = new File(['save_path' => "{$root}/records"]);
         touch("{$root}/records/.records.lock");
         chmod("{$root}/records/.records.lock", 0644);
         $Key = new File(['save_path' => "{$root}/key"]);
         $record = "{$root}/key/session_{$id}";
         $filler = str_repeat('0', 64);
         file_put_contents($record, "{$filler}record");
         chmod($record, 0600);
         touch("{$root}/key/.secret.lock");
         chmod("{$root}/key/.secret.lock", 0644);

         // @ Every File handler operation under an unsafe lock
         $Chains = [
            'write()' => [
               static fn () => $Records->write($id, $data),
               [[File::class, 'write', 0], [File::class, 'write', 1], [File::class, 'store', 0], [File::class, 'store', 1]]
            ],
            'commit()' => [
               static function () use ($Records, $id, $data): void {
                  $revision = null;
                  $Records->commit($id, $data, $revision);
               },
               [[File::class, 'commit', 0], [File::class, 'commit', 1], [File::class, 'store', 0], [File::class, 'store', 1]]
            ],
            'touch()' => [
               static fn () => $Records->touch($id),
               [[File::class, 'touch', 0]]
            ],
            'revoke()' => [
               static fn () => $Records->revoke($id, str_repeat('0', 64)),
               [[File::class, 'revoke', 0]]
            ],
            'destroy()' => [
               static fn () => $Records->destroy($id),
               [[File::class, 'destroy', 0]]
            ],
            'read()' => [
               static fn () => $Key->read($id),
               [[File::class, 'read', 0], [File::class, 'fetch', 0], [File::class, 'load', 0]]
            ],
         ];
         $wrong = [];
         foreach ($Chains as $chain => [$Trigger, $Marked]) {
            $Thrown = null;
            try {
               $Trigger();
            }
            catch (Throwable $Throwable) {
               $Thrown = $Throwable;
            }
            foreach ($Check($Thrown, $Marked, ['session ID' => $id, 'payload' => $data]) as $problem) {
               $wrong[] = "{$chain}: {$problem}";
            }
            if ($Thrown?->getMessage() !== 'File session secret lock has unsafe metadata.') {
               $wrong[] = "{$chain}: not the lock refusal";
            }
         }
         $listed = implode(', ', $wrong);
         yield assert(
            assertion: $wrong === [],
            description: "Every File handler operation keeps the session ID, its record path and the payload out of the trace (wrong: [{$listed}])"
         );

         // @ rotate() and decode(): the throw goes through the marked parameter itself
         Handler::$instance = new class implements Handling {
            public function read (string $sessionId): string|false
            {
               return false;
            }

            public function write (string $sessionId, string $sessionData): bool
            {
               return true;
            }

            public function touch (string $sessionId): bool
            {
               return true;
            }

            public function destroy (string $sessionId): bool
            {
               return true;
            }

            public function purge (int $maxLifetime): bool
            {
               return true;
            }
         };
         $Session = new Session(bin2hex(random_bytes(16)));
         $Rotated = null;
         try {
            // @phpstan-ignore argument.type (a deliberate TypeError raised at the marked parameter)
            $Session->rotate([$rotated]);
         }
         catch (TypeError $Error) {
            $Rotated = $Error;
         }
         $Decode = Closure::bind(
            // @phpstan-ignore staticMethod.private, argument.type (bound to Session: a deliberate TypeError raised at the marked parameter)
            static fn () => Session::decode([$payload]),
            null,
            Session::class
         );
         $Decoded = null;
         try {
            $Decode();
         }
         catch (TypeError $Error) {
            $Decoded = $Error;
         }
         $wrong = [
            ...$Check($Rotated, [[Session::class, 'rotate', 0]], ['rotated ID' => $rotated]),
            ...$Check($Decoded, [[Session::class, 'decode', 0]], ['payload' => $payload])
         ];
         $listed = implode(', ', $wrong);
         yield assert(
            assertion: $Rotated instanceof TypeError && $Decoded instanceof TypeError && $wrong === [],
            description: "Session::rotate() and Session::decode() keep the new ID and the payload out of the trace (wrong: [{$listed}])"
         );
         $Session = null;
      }
      finally {
         ini_set('zend.exception_ignore_args', (string) $ignore);
         ini_set('zend.exception_string_param_max_len', (string) $length);
         [Handler::$class, Handler::$config, Handler::$instance] = $handler;
         foreach ($Statics as [$Static, $value]) {
            $Static->setValue(null, $value);
         }
         $Cleanup($root);
      }
   }
);
