<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Errors;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\HPACK;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * H2 remediation regression — an HTTP/2 file response may reopen its file for
 * each positive flow-control quantum, but it must never retain that handler
 * while the stream is parked.
 *
 * The first leg transfers one runtime fixture under an initial stream window
 * of zero. Four WINDOW_UPDATE frames grant exactly [1, 7, 13, 41] bytes, and a
 * PING barrier after every grant proves the worker processed the quantum before
 * /proc is sampled. Every cumulative prefix must be exact, every sample must
 * contain zero target descriptors, and the final byte must carry END_STREAM.
 *
 * The second leg grants one byte and atomically replaces the pathname with a
 * different inode of the same size. The next reopen must reject that changed
 * representation with RST_STREAM(INTERNAL_ERROR), without mixing replacement
 * bytes into the response or leaving either a live or deleted target handler.
 */
$credits = [1, 7, 13, 41];
$original = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
$replacement = strrev($original);
$probe = [
   'error' => '',
   'token' => '',
   'setup' => [],
   'normal' => [
      'initial' => [],
      'steps' => [],
      'data' => '',
      'ended' => false,
      'reset' => null,
   ],
   'replacement' => [
      'initial' => [],
      'first' => [],
      'atomic' => [],
      'after' => [],
   ],
   'health' => [],
];

return new Test(
   description: 'HTTP/2 file reopens must be ephemeral and preserve representation identity',
   Separator: new Separator(line: true),

   request: static function (
      string $hostPort,
      int $testIndex,
   ) use (
      &$probe,
      $credits,
      $original,
      $replacement,
   ): string {
      $token = bin2hex(random_bytes(8));
      $relative = 'statics/h2-identity-' . $token . '.txt';
      $target = BOOTGLY_PROJECT->path . $relative;
      $replacementPath = $target . '.replacement';
      $probe['token'] = $token;

      $Headers = static function (
         int $testIndex,
         string $path,
         string $token,
      ): string {
         return HPACK::encode([
            [':method', 'GET'],
            [':scheme', 'http'],
            [':path', $path],
            [':authority', 'localhost'],
            ['x-bootgly-test', (string) $testIndex],
            ['x-h2-identity-token', $token],
         ]);
      };

      $Write = static function ($Socket, string $wire): void {
         $offset = 0;
         $length = strlen($wire);
         while ($offset < $length) {
            $written = @fwrite($Socket, substr($wire, $offset));
            if ($written === false || $written === 0) {
               throw new RuntimeException(
                  "Short HTTP/2 fixture write at {$offset}/{$length} bytes."
               );
            }
            $offset += $written;
         }
      };

      $Read = static function ($Socket, float $timeout): string {
         stream_set_blocking($Socket, false);

         $wire = '';
         $deadline = microtime(true) + $timeout;
         while (microtime(true) < $deadline) {
            $chunk = @fread($Socket, 65535);
            if ($chunk !== false && $chunk !== '') {
               $wire .= $chunk;
               continue;
            }
            if (@feof($Socket)) {
               break;
            }
            usleep(5_000);
         }

         return $wire;
      };

      $Call = static function (
         string $hostPort,
         int $testIndex,
         string $path,
         string $token,
      ) use ($Read, $Write): string {
         $Socket = @stream_socket_client(
            "tcp://{$hostPort}",
            $errorNumber,
            $errorMessage,
            timeout: 3,
         );
         if (! is_resource($Socket)) {
            throw new RuntimeException(
               "Could not call {$path}: {$errorNumber} {$errorMessage}"
            );
         }

         stream_set_blocking($Socket, true);
         stream_set_timeout($Socket, 3);
         $request = "GET {$path} HTTP/1.1\r\n"
            . "X-Bootgly-Test: {$testIndex}\r\n"
            . "X-H2-Identity-Token: {$token}\r\n"
            . "Host: localhost\r\n"
            . "Connection: close\r\n\r\n";
         try {
            $Write($Socket, $request);
         }
         catch (Throwable $Throwable) {
            @fclose($Socket);
            throw $Throwable;
         }

         $wire = $Read($Socket, 3.0);
         @fclose($Socket);

         return $wire;
      };

      $Decode = static function (string $wire): null|array {
         $separator = strpos($wire, "\r\n\r\n");
         if ($separator === false) {
            return null;
         }

         $decoded = json_decode(substr($wire, $separator + 4), true);

         return is_array($decoded) ? $decoded : null;
      };

      $Walk = static function (string $wire): array {
         $state = [
            'headers' => [],
            'data' => [],
            'ended' => [],
            'pings' => [],
            'resets' => [],
            'frames' => 0,
            'parsed' => 0,
         ];
         $offset = 0;
         $length = strlen($wire);
         while ($offset + 9 <= $length) {
            $head = substr($wire, $offset, 9);
            $size = (ord($head[0]) << 16)
               | (ord($head[1]) << 8)
               | ord($head[2]);
            if ($size > 0x1000000 || $offset + 9 + $size > $length) {
               break;
            }

            $type = ord($head[3]);
            $flags = ord($head[4]);
            /** @var array{1:int} $StreamID */
            $StreamID = unpack('N', substr($head, 5, 4));
            $streamID = $StreamID[1] & 0x7fffffff;
            $payload = substr($wire, $offset + 9, $size);
            $state['frames']++;

            if ($type === HTTP2::FRAME_HEADERS) {
               $state['headers'][$streamID] =
                  ($state['headers'][$streamID] ?? 0) + 1;
               if (($flags & HTTP2::FLAG_END_STREAM) !== 0) {
                  $state['ended'][$streamID] = true;
               }
            }
            else if ($type === HTTP2::FRAME_DATA) {
               $state['data'][$streamID] =
                  ($state['data'][$streamID] ?? '') . $payload;
               if (($flags & HTTP2::FLAG_END_STREAM) !== 0) {
                  $state['ended'][$streamID] = true;
               }
            }
            else if (
               $type === HTTP2::FRAME_PING
               && ($flags & HTTP2::FLAG_ACK) !== 0
               && $size === 8
            ) {
               $state['pings'][$payload] = true;
            }
            else if ($type === HTTP2::FRAME_RST_STREAM && $size >= 4) {
               /** @var array{1:int} $Error */
               $Error = unpack('N', substr($payload, 0, 4));
               $state['resets'][$streamID] = $Error[1];
            }

            $offset += 9 + $size;
         }
         $state['parsed'] = $offset;

         return $state;
      };

      $Await = static function (
         $Socket,
         string &$wire,
         callable $check,
         float $timeout = 4.0,
      ) use ($Walk): array {
         stream_set_blocking($Socket, false);
         $deadline = microtime(true) + $timeout;
         do {
            $chunk = @fread($Socket, 65535);
            if ($chunk !== false && $chunk !== '') {
               $wire .= $chunk;
            }

            $state = $Walk($wire);
            if ($check($state) === true) {
               return $state;
            }
            if (@feof($Socket)) {
               break;
            }
            usleep(5_000);
         }
         while (microtime(true) < $deadline);

         return $Walk($wire);
      };

      $Snapshot = static function (int $PID, string $target): array {
         $directory = "/proc/{$PID}/fd";
         $entries = @scandir($directory);
         if (! is_array($entries)) {
            throw new RuntimeException("Could not enumerate {$directory}.");
         }

         $targetFDs = 0;
         $deletedFDs = 0;
         $matches = [];
         foreach ($entries as $entry) {
            if (preg_match('/^[0-9]+$/D', $entry) !== 1) {
               continue;
            }

            $link = @readlink($directory . '/' . $entry);
            if (! is_string($link)) {
               continue;
            }
            if ($link === $target) {
               $targetFDs++;
               $matches[(int) $entry] = $link;
            }
            else if ($link === $target . ' (deleted)') {
               $deletedFDs++;
               $matches[(int) $entry] = $link;
            }
         }

         return [
            'target_fds' => $targetFDs,
            'deleted_fds' => $deletedFDs,
            'matches' => $matches,
         ];
      };

      $Start = static function (
         string $hostPort,
         int $testIndex,
         string $token,
         string $prefix,
      ) use (
         $Headers,
         $Write,
         $Await,
      ): array {
         $Socket = @stream_socket_client(
            "tcp://{$hostPort}",
            $errorNumber,
            $errorMessage,
            timeout: 3,
         );
         if (! is_resource($Socket)) {
            throw new RuntimeException(
               "Could not open HTTP/2 fixture: {$errorNumber} {$errorMessage}"
            );
         }

         stream_set_blocking($Socket, true);
         stream_set_timeout($Socket, 3);
         $wire = '';
         $openPing = $prefix . 'OPEN000';
         $Write(
            $Socket,
            HTTP2::PREFACE
            . Frame::pack(
               HTTP2::FRAME_SETTINGS,
               0,
               0,
               pack('nN', HTTP2::SETTINGS_INITIAL_WINDOW_SIZE, 0),
            )
            . Frame::pack(HTTP2::FRAME_PING, 0, 0, $openPing),
         );
         $state = $Await(
            $Socket,
            $wire,
            static fn(array $state): bool => isset($state['pings'][$openPing]),
         );
         if (! isset($state['pings'][$openPing])) {
            @fclose($Socket);
            throw new RuntimeException(
               "The {$prefix} HTTP/2 preflight PING was not acknowledged."
            );
         }

         $headPing = $prefix . 'HEAD000';
         $Write(
            $Socket,
            Frame::pack(
               HTTP2::FRAME_HEADERS,
               HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
               1,
               $Headers($testIndex, '/h2-identity-file', $token),
            )
            . Frame::pack(HTTP2::FRAME_PING, 0, 0, $headPing),
         );
         $state = $Await(
            $Socket,
            $wire,
            static fn(array $state): bool =>
               isset($state['headers'][1])
               && isset($state['pings'][$headPing]),
         );
         if (
            ! isset($state['headers'][1])
            || ! isset($state['pings'][$headPing])
         ) {
            @fclose($Socket);
            throw new RuntimeException(
               "The {$prefix} zero-credit response was not materialized."
            );
         }

         return [$Socket, $wire, $state];
      };

      $NormalSocket = null;
      $ReplacementSocket = null;
      try {
         if (! is_dir('/proc/self/fd')) {
            throw new RuntimeException('H2 requires Linux /proc descriptor inspection.');
         }
         if (strlen($original) !== 62 || strlen($replacement) !== 62) {
            throw new RuntimeException('The same-size identity fixtures are not 62 bytes.');
         }

         @unlink($replacementPath);
         @unlink($target);
         $written = @file_put_contents($target, $original, LOCK_EX);
         if ($written !== strlen($original)) {
            throw new RuntimeException('Could not create the runtime file fixture.');
         }

         $setupWire = $Call(
            $hostPort,
            $testIndex,
            '/h2-identity-setup',
            $token,
         );
         $setup = $Decode($setupWire);
         if (
            ! is_array($setup)
            || ($setup['phase'] ?? null) !== 'setup'
            || ! is_int($setup['pid'] ?? null)
            || ($setup['pid'] ?? 0) <= 0
            || ! is_string($setup['target'] ?? null)
            || ($setup['target'] ?? '') === ''
            || ($setup['size'] ?? null) !== strlen($original)
         ) {
            throw new RuntimeException(
               'The setup route did not return a usable worker PID and target.'
            );
         }
         $probe['setup'] = $setup;
         $PID = $setup['pid'];
         $workerTarget = $setup['target'];

         // # Normal reopen leg.
         [$NormalSocket, $normalWire] = $Start(
            $hostPort,
            $testIndex,
            $token,
            'N',
         );
         $probe['normal']['initial'] = $Snapshot($PID, $workerTarget);

         $expectedLength = 0;
         foreach ($credits as $index => $credit) {
            $expectedLength += $credit;
            $ping = sprintf('NCR%05d', $index);
            $Write(
               $NormalSocket,
               Frame::pack(
                  HTTP2::FRAME_WINDOW_UPDATE,
                  0,
                  1,
                  pack('N', $credit),
               )
               . Frame::pack(HTTP2::FRAME_PING, 0, 0, $ping),
            );
            $state = $Await(
               $NormalSocket,
               $normalWire,
               static fn(array $state): bool =>
                  isset($state['pings'][$ping])
                  && (
                     strlen($state['data'][1] ?? '') >= $expectedLength
                     || isset($state['resets'][1])
                  ),
            );
            $data = $state['data'][1] ?? '';
            $probe['normal']['steps'][] = [
               'credit' => $credit,
               'expected_length' => $expectedLength,
               'data' => $data,
               'exact_prefix' =>
                  $data === substr($original, 0, $expectedLength),
               'ended' => isset($state['ended'][1]),
               'reset' => $state['resets'][1] ?? null,
               'ping' => isset($state['pings'][$ping]),
               'snapshot' => $Snapshot($PID, $workerTarget),
            ];
         }
         $normalState = $Walk($normalWire);
         $probe['normal']['data'] = $normalState['data'][1] ?? '';
         $probe['normal']['ended'] = isset($normalState['ended'][1]);
         $probe['normal']['reset'] = $normalState['resets'][1] ?? null;
         @fclose($NormalSocket);
         $NormalSocket = null;

         // # Atomic replacement leg.
         [$ReplacementSocket, $replacementWire] = $Start(
            $hostPort,
            $testIndex,
            $token,
            'R',
         );
         $probe['replacement']['initial'] = $Snapshot($PID, $workerTarget);

         $firstPing = 'RFIRST00';
         $Write(
            $ReplacementSocket,
            Frame::pack(
               HTTP2::FRAME_WINDOW_UPDATE,
               0,
               1,
               pack('N', 1),
            )
            . Frame::pack(HTTP2::FRAME_PING, 0, 0, $firstPing),
         );
         $firstState = $Await(
            $ReplacementSocket,
            $replacementWire,
            static fn(array $state): bool =>
               isset($state['pings'][$firstPing])
               && (
                  strlen($state['data'][1] ?? '') >= 1
                  || isset($state['resets'][1])
               ),
         );
         $probe['replacement']['first'] = [
            'data' => $firstState['data'][1] ?? '',
            'ended' => isset($firstState['ended'][1]),
            'reset' => $firstState['resets'][1] ?? null,
            'ping' => isset($firstState['pings'][$firstPing]),
            'snapshot' => $Snapshot($PID, $workerTarget),
         ];

         $before = @stat($target);
         $replacementWritten = @file_put_contents(
            $replacementPath,
            $replacement,
            LOCK_EX,
         );
         $candidate = @stat($replacementPath);
         $renamed = $replacementWritten === strlen($replacement)
            && @rename($replacementPath, $target);
         clearstatcache(true, $target);
         $after = @stat($target);
         $probe['replacement']['atomic'] = [
            'renamed' => $renamed,
            'same_size' =>
               is_array($before)
               && is_array($after)
               && ($before['size'] ?? -1) === ($after['size'] ?? -2),
            'different_inode' =>
               is_array($before)
               && is_array($candidate)
               && (
                  ($before['dev'] ?? null) !== ($candidate['dev'] ?? null)
                  || ($before['ino'] ?? null) !== ($candidate['ino'] ?? null)
               ),
            'before_inode' => is_array($before) ? ($before['ino'] ?? null) : null,
            'after_inode' => is_array($after) ? ($after['ino'] ?? null) : null,
         ];
         if ($renamed === false) {
            throw new RuntimeException('Could not atomically replace the runtime fixture.');
         }

         $resetPing = 'RRESET00';
         $Write(
            $ReplacementSocket,
            Frame::pack(
               HTTP2::FRAME_WINDOW_UPDATE,
               0,
               1,
               pack('N', 7),
            )
            . Frame::pack(HTTP2::FRAME_PING, 0, 0, $resetPing),
         );
         $afterState = $Await(
            $ReplacementSocket,
            $replacementWire,
            static fn(array $state): bool =>
               isset($state['pings'][$resetPing])
               && isset($state['resets'][1]),
         );
         $probe['replacement']['after'] = [
            'data' => $afterState['data'][1] ?? '',
            'ended' => isset($afterState['ended'][1]),
            'reset' => $afterState['resets'][1] ?? null,
            'ping' => isset($afterState['pings'][$resetPing]),
            'snapshot' => $Snapshot($PID, $workerTarget),
         ];
         @fclose($ReplacementSocket);
         $ReplacementSocket = null;

         $healthWire = $Call(
            $hostPort,
            $testIndex,
            '/h2-identity-health',
            $token,
         );
         $health = $Decode($healthWire);
         $probe['health'] = is_array($health) ? $health : [];
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         if (is_resource($NormalSocket)) {
            @fclose($NormalSocket);
         }
         if (is_resource($ReplacementSocket)) {
            @fclose($ReplacementSocket);
         }
         @unlink($replacementPath);
         @unlink($target);
      }

      return "GET /h2-identity-harness HTTP/1.1\r\n"
         . "X-Bootgly-Test: {$testIndex}\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n\r\n";
   },

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ) {
      static $workerToken = '';

      $Resolve = static function (string $token): null|array {
         if (preg_match('/^[a-f0-9]{16}$/D', $token) !== 1) {
            return null;
         }

         $relative = 'statics/h2-identity-' . $token . '.txt';
         $target = realpath(BOOTGLY_PROJECT->path . $relative);
         if (! is_string($target)) {
            return null;
         }

         return [
            'relative' => $relative,
            'target' => $target,
         ];
      };

      yield $Router->route('/h2-identity-setup', static function (
         Request $Request,
         Response $Response,
      ) use (&$workerToken, $Resolve): Response {
         $token = $Request->Header->get('X-H2-Identity-Token') ?? '';
         $fixture = $Resolve($token);
         if (! is_array($fixture)) {
            return $Response->code(400)->JSON->send([
               'phase' => 'setup',
               'error' => 'invalid token or fixture',
            ]);
         }

         $size = @filesize($fixture['target']);
         if (! is_int($size)) {
            return $Response->code(500)->JSON->send([
               'phase' => 'setup',
               'error' => 'fixture metadata unavailable',
            ]);
         }

         $workerToken = $token;

         return $Response->JSON->send([
            'phase' => 'setup',
            'pid' => getmypid(),
            'target' => $fixture['target'],
            'size' => $size,
         ]);
      }, GET);

      yield $Router->route('/h2-identity-file', static function (
         Request $Request,
         Response $Response,
      ) use (&$workerToken, $Resolve): Response {
         $token = $Request->Header->get('X-H2-Identity-Token') ?? '';
         if (
            $token === ''
            || $workerToken === ''
            || ! hash_equals($workerToken, $token)
         ) {
            return $Response->code(403);
         }

         $fixture = $Resolve($token);
         if (! is_array($fixture)) {
            return $Response->code(404);
         }

         return $Response->upload($fixture['relative']);
      }, GET);

      yield $Router->route('/h2-identity-health', static function (
         Request $Request,
         Response $Response,
      ) use (&$workerToken): Response {
         $token = $Request->Header->get('X-H2-Identity-Token') ?? '';
         if (
            $token === ''
            || $workerToken === ''
            || ! hash_equals($workerToken, $token)
         ) {
            return $Response->code(403)->JSON->send([
               'phase' => 'health',
               'error' => 'invalid token',
            ]);
         }

         return $Response->JSON->send([
            'phase' => 'health',
            'pid' => getmypid(),
         ]);
      }, GET);

      yield $Router->route('/h2-identity-harness', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response->JSON->send([
            'phase' => 'harness',
            'pid' => getmypid(),
         ]);
      }, GET);
   },

   test: static function (string $response) use (
      &$probe,
      $credits,
      $original,
   ): bool|string {
      $separator = strpos($response, "\r\n\r\n");
      $harness = $separator === false
         ? null
         : json_decode(substr($response, $separator + 4), true);

      if ($probe['error'] !== '') {
         Vars::$labels = ['H2 identity fixture evidence'];
         dump(json_encode($probe, JSON_UNESCAPED_SLASHES));

         return 'H2 identity fixture error: ' . $probe['error'];
      }

      $PID = $probe['setup']['pid'] ?? 0;
      $healthPID = $probe['health']['pid'] ?? 0;
      $harnessPID = is_array($harness) ? ($harness['pid'] ?? 0) : 0;
      if (
         ! is_int($PID)
         || $PID <= 0
         || ! is_int($healthPID)
         || $healthPID !== $PID
         || ! is_int($harnessPID)
         || $harnessPID !== $PID
         || ($probe['health']['phase'] ?? null) !== 'health'
         || (is_array($harness) ? ($harness['phase'] ?? null) : null) !== 'harness'
      ) {
         Vars::$labels = ['H2 identity worker controls'];
         dump(json_encode($probe, JSON_UNESCAPED_SLASHES));

         return 'H2 identity control failed: setup, health, and harness did '
            . 'not execute on the same live worker.';
      }

      $normalInitial = $probe['normal']['initial'];
      $normalSecure =
         ($normalInitial['target_fds'] ?? -1) === 0
         && ($normalInitial['deleted_fds'] ?? -1) === 0
         && count($probe['normal']['steps']) === count($credits)
         && ($probe['normal']['data'] ?? null) === $original
         && ($probe['normal']['ended'] ?? false) === true
         && ($probe['normal']['reset'] ?? null) === null;
      $expectedLength = 0;
      foreach ($credits as $index => $credit) {
         $expectedLength += $credit;
         $step = $probe['normal']['steps'][$index] ?? [];
         $final = $index === count($credits) - 1;
         $normalSecure = $normalSecure
            && ($step['credit'] ?? null) === $credit
            && ($step['expected_length'] ?? null) === $expectedLength
            && ($step['exact_prefix'] ?? false) === true
            && ($step['ping'] ?? false) === true
            && ($step['reset'] ?? null) === null
            && ($step['ended'] ?? false) === $final
            && ($step['snapshot']['target_fds'] ?? -1) === 0
            && ($step['snapshot']['deleted_fds'] ?? -1) === 0;
      }
      if ($normalSecure === false) {
         Vars::$labels = ['H2 ephemeral reopen evidence'];
         dump(json_encode($probe, JSON_UNESCAPED_SLASHES));

         return 'H2 regression failed: positive flow-control credits did not '
            . 'produce exact cumulative prefixes with zero parked target '
            . 'descriptors and a final END_STREAM.';
      }

      $first = $probe['replacement']['first'];
      $atomic = $probe['replacement']['atomic'];
      $after = $probe['replacement']['after'];
      $replacementSecure =
         ($probe['replacement']['initial']['target_fds'] ?? -1) === 0
         && ($probe['replacement']['initial']['deleted_fds'] ?? -1) === 0
         && ($first['data'] ?? null) === $original[0]
         && ($first['ended'] ?? true) === false
         && ($first['reset'] ?? null) === null
         && ($first['ping'] ?? false) === true
         && ($first['snapshot']['target_fds'] ?? -1) === 0
         && ($first['snapshot']['deleted_fds'] ?? -1) === 0
         && ($atomic['renamed'] ?? false) === true
         && ($atomic['same_size'] ?? false) === true
         && ($atomic['different_inode'] ?? false) === true
         && ($after['data'] ?? null) === $original[0]
         && ($after['ended'] ?? true) === false
         && ($after['reset'] ?? null) === Errors::Internal->value
         && ($after['ping'] ?? false) === true
         && ($after['snapshot']['target_fds'] ?? -1) === 0
         && ($after['snapshot']['deleted_fds'] ?? -1) === 0;
      if ($replacementSecure === false) {
         Vars::$labels = ['H2 representation identity evidence'];
         dump(json_encode($probe, JSON_UNESCAPED_SLASHES));

         return 'H2 regression failed: an atomic same-size pathname '
            . 'replacement was not rejected with RST_STREAM(INTERNAL_ERROR) '
            . 'without mixed bytes or retained/deleted target descriptors.';
      }

      return true;
   },
);
