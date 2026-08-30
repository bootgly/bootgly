<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Interfaces\TCP_Client_CLI\Connections\Connection;
use Bootgly\WPI\Modules\WS;
use Bootgly\WPI\Nodes\WS_Client_CLI;
use Bootgly\WPI\Nodes\WS_Client_CLI\Decoders\Decoder_Framing;
use Bootgly\WPI\Nodes\WS_Client_CLI\Message\Frame as ClientFrame;
use Bootgly\WPI\Nodes\WS_Client_CLI\Session;
use Bootgly\WPI\Nodes\WS_Server_CLI\Message\Frame as ServerFrame;


/**
 * H8 PoC — malformed server DEFLATE must become a WebSocket 1007 close.
 *
 * Bootgly promotes PHP warnings to ErrorException. Applications may replace
 * that handler and throw any Throwable even under `@`, so the inflater must
 * prevent expected peer-data warnings from reaching application handlers.
 */
return new Test(
   description: 'H8: WS client contains malformed-DEFLATE warnings as close 1007',
   skip: extension_loaded('zlib') === false,

   test: function () {
      $Compress = static function (string $payload): string|false {
         $Deflator = deflate_init(ZLIB_ENCODING_RAW, ['window' => 15]);
         if ($Deflator === false) {
            return false;
         }

         $compressed = deflate_add($Deflator, $payload, ZLIB_SYNC_FLUSH);
         if ($compressed === false) {
            return false;
         }

         return str_ends_with($compressed, "\x00\x00\xff\xff")
            ? (string) substr($compressed, 0, -4)
            : $compressed;
      };

      /** @return array<string,mixed> */
      $Probe = static function (bool $noContext) use ($Compress): array {
         $asyncSupported = function_exists('pcntl_async_signals');
         $exerciseAsync = $noContext === false;
         $previousAsync = $asyncSupported
            ? pcntl_async_signals($exerciseAsync)
            : false;
         $Evidence = [
            'mode' => $noContext ? 'no-context-takeover' : 'context-takeover',
            'error' => '',
            'control' => false,
            'control_compressed_bytes' => 0,
            'attack_compressed_bytes' => 0,
            'handler_called' => false,
            'control_handler_restored' => false,
            'handler_restored' => false,
            'control_reporting_restored' => false,
            'reporting_restored' => false,
            'async_supported' => $asyncSupported,
            'async_expected' => $exerciseAsync,
            'control_async_restored' => $asyncSupported === false,
            'async_restored' => $asyncSupported === false,
            'async_cleanup' => $asyncSupported === false,
            'display_active' => false,
            'display_restored' => false,
            'diagnostic' => null,
            'warning_output' => null,
            'escaped' => '',
            'stop' => false,
            'closing' => false,
            'message' => false,
            'close' => null,
         ];
         $Listener = null;
         $Writer = null;
         $Reader = null;

         try {
            $controlBlocks = [];
            for ($index = 0; $index < 256; $index++) {
               $controlBlocks[] = hash(
                  'sha256',
                  "H8-client-control-{$Evidence['mode']}-$index",
                  true
               );
            }
            $controlPayload = implode('', $controlBlocks);
            $control = $Compress($controlPayload);
            if (is_string($control) === false) {
               throw new RuntimeException('could not build the valid DEFLATE control');
            }
            $Evidence['control_compressed_bytes'] = strlen($control);
            $attack = implode('', [$control, "\x07", str_repeat('A', 8192)]);
            $Evidence['attack_compressed_bytes'] = strlen($attack);

            $Listener = stream_socket_server(
               'tcp://127.0.0.1:0',
               $code,
               $message,
               STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
            );
            $address = is_resource($Listener)
               ? stream_socket_get_name($Listener, false)
               : false;
            $Writer = is_string($address)
               ? stream_socket_client("tcp://{$address}", $code, $message, 1.0)
               : false;
            $Reader = is_resource($Listener)
               ? stream_socket_accept($Listener, 1.0)
               : false;
            if (is_resource($Writer) === false || is_resource($Reader) === false) {
               throw new RuntimeException('could not create the client decoder TCP pair');
            }

            $Client = new WS_Client_CLI(WS_Client_CLI::MODE_TEST);
            $Client->configure(
               host: '127.0.0.1',
               port: 1,
               maxMessageSize: 65536,
               compression: true
            );
            $Client->reset();

            $Connection = new Connection($Writer, Client: $Client);
            $Session = new Session($Connection, 'H8-test-key', $Client);
            $Session->compress([
               'permessage-deflate' => true,
               'server_no_context_takeover' => $noContext,
            ]);
            $Client->Session = $Session;
            $Decoder = new Decoder_Framing;
            $reporting = error_reporting();

            $handlerCalled = false;
            $Handler = static function (
               int $level,
               string $message,
               string $file,
               int $line
            ) use (&$handlerCalled, $noContext): never {
               $handlerCalled = true;
               if ($noContext) {
                  throw new RuntimeException($message);
               }

               throw new ErrorException($message, 0, $level, $file, $line);
            };
            $Inspector = static function (
               int $level,
               string $message,
               string $file,
               int $line
            ): bool {
               return true;
            };
            $Sentinel = static function (
               int $level,
               string $message,
               string $file,
               int $line
            ): never {
               throw new RuntimeException('H8 error-handler sentinel was reached');
            };
            $Inspect = static function (
               Closure $Handler,
               Closure $Sentinel,
               Closure $Inspector
            ): bool {
               $PreviousHandler = set_error_handler($Inspector, E_WARNING);
               $topRestored = $PreviousHandler === $Handler;

               // @ Remove the inspector. Normalize any leaked inflater handlers
               //   so the adversarial application handler is active again.
               restore_error_handler();
               for ($depth = 0; $PreviousHandler !== $Handler && $depth < 1024; $depth++) {
                  restore_error_handler();
                  $PreviousHandler = set_error_handler($Inspector, E_WARNING);
                  restore_error_handler();
               }

               // @ Pop the application handler and verify its unique sentinel
               //   is immediately below it; then rebuild the expected pair.
               restore_error_handler();
               $PreviousHandler = set_error_handler($Inspector, E_WARNING);
               $stackRestored = $PreviousHandler === $Sentinel;
               restore_error_handler();
               for ($depth = 0; $PreviousHandler !== $Sentinel && $depth < 1024; $depth++) {
                  restore_error_handler();
                  $PreviousHandler = set_error_handler($Inspector, E_WARNING);
                  restore_error_handler();
               }
               set_error_handler($Handler, E_WARNING);

               return $topRestored && $stackRestored;
            };

            $displayErrors = ini_get('display_errors');
            ini_set('display_errors', '1');
            $buffering = ob_start();
            set_error_handler($Sentinel, E_WARNING);
            set_error_handler($Handler, E_WARNING);
            try {
               // # Positive control — this negotiated decoder accepts valid data.
               try {
                  $wire = ServerFrame::encode(
                     WS::OPCODE_BINARY,
                     $control,
                     rsv1: 0x40
                  );
                  $result = $Decoder->decode($Session, $wire);
                  $Evidence['control'] = is_array($result)
                     && ($result['message'] ?? null)?->payload === $controlPayload;
               }
               finally {
                  $Evidence['control_handler_restored'] = $Inspect(
                     $Handler,
                     $Sentinel,
                     $Inspector
                  );
                  $Evidence['control_reporting_restored'] = error_reporting()
                     === $reporting;
                  error_reporting($reporting);
                  if ($asyncSupported) {
                     $Evidence['control_async_restored'] = pcntl_async_signals()
                        === $exerciseAsync;
                     pcntl_async_signals($exerciseAsync);
                  }
               }

               // # Attack — replacement handlers ignore the `@` mask and throw
               //   different classes to prove the warning never reaches either.
               $Session->Message = null;
               error_clear_last();
               try {
                  $wire = ServerFrame::encode(
                     WS::OPCODE_BINARY,
                     $attack,
                     rsv1: 0x40
                  );
                  $result = $Decoder->decode($Session, $wire);
                  $Evidence['stop'] = is_array($result)
                     && ($result['stop'] ?? false) === true;
               }
               catch (Throwable $Throwable) {
                  $class = $Throwable::class;
                  $message = $Throwable->getMessage();
                  $Evidence['escaped'] = "$class: $message";
               }
               $Evidence['diagnostic'] = error_get_last();
            }
            finally {
               try {
                  $Evidence['handler_called'] = $handlerCalled;
                  $Evidence['handler_restored'] = $Inspect(
                     $Handler,
                     $Sentinel,
                     $Inspector
                  );
                  $Evidence['reporting_restored'] = error_reporting() === $reporting;
                  error_reporting($reporting);
                  if ($asyncSupported) {
                     $Evidence['async_restored'] = pcntl_async_signals()
                        === $exerciseAsync;
                  }
                  restore_error_handler();
                  restore_error_handler();
               }
               finally {
                  $Evidence['display_active'] = ini_get('display_errors') === '1';
                  $Evidence['warning_output'] = $buffering
                     ? ob_get_clean()
                     : false;
                  if (is_string($displayErrors)) {
                     ini_set('display_errors', $displayErrors);
                  }
                  else {
                     ini_restore('display_errors');
                  }
                  $Evidence['display_restored'] = (string) ini_get('display_errors')
                     === (string) $displayErrors;
               }
            }

            $Evidence['message'] = $Session->Message !== null;
            $Evidence['closing'] = $Session->closing;
            stream_set_blocking($Reader, false);
            $closeWire = stream_get_contents($Reader);
            $Close = is_string($closeWire) && $closeWire !== ''
               ? ClientFrame::decode($closeWire, 0, 1024)
               : null;
            $closeCode = $Close !== null && strlen($Close->payload) >= 2
               ? unpack('ncode', substr($Close->payload, 0, 2))
               : false;
            $Evidence['close'] = [
               'wire_bytes' => is_string($closeWire) ? strlen($closeWire) : 0,
               'opcode' => $Close?->opcode,
               'fin' => $Close?->fin,
               'masked' => $Close?->masked,
               'error' => $Close?->error,
               'consumed' => $Close?->consumed,
               'code' => is_array($closeCode) ? (int) $closeCode['code'] : null,
            ];
         }
         catch (Throwable $Throwable) {
            $class = $Throwable::class;
            $message = $Throwable->getMessage();
            $Evidence['error'] = "$class: $message";
         }
         finally {
            if ($asyncSupported) {
               pcntl_async_signals($previousAsync);
               $Evidence['async_cleanup'] = pcntl_async_signals() === $previousAsync;
            }
            foreach ([$Writer, $Reader, $Listener] as $Stream) {
               if (is_resource($Stream)) {
                  fclose($Stream);
               }
            }
         }

         return $Evidence;
      };

      $takeover = $Probe(false);
      $reset = $Probe(true);
      $evidence = json_encode([
         'takeover' => $takeover,
         'no_context_takeover' => $reset,
      ]);

      yield assert(
         assertion: $takeover['error'] === ''
            && $takeover['control'] === true
            && $takeover['control_compressed_bytes'] > 4096
            && $takeover['attack_compressed_bytes']
               - $takeover['control_compressed_bytes'] > 4096
            && $takeover['handler_called'] === false
            && $takeover['control_handler_restored'] === true
            && $takeover['handler_restored'] === true
            && $takeover['control_reporting_restored'] === true
            && $takeover['reporting_restored'] === true
            && $takeover['control_async_restored'] === true
            && $takeover['async_restored'] === true
            && $takeover['async_cleanup'] === true
            && $takeover['display_active'] === true
            && $takeover['display_restored'] === true
            && $reset['error'] === ''
            && $reset['control'] === true
            && $reset['control_compressed_bytes'] > 4096
            && $reset['attack_compressed_bytes']
               - $reset['control_compressed_bytes'] > 4096
            && $reset['handler_called'] === false
            && $reset['control_handler_restored'] === true
            && $reset['handler_restored'] === true
            && $reset['control_reporting_restored'] === true
            && $reset['reporting_restored'] === true
            && $reset['control_async_restored'] === true
            && $reset['async_restored'] === true
            && $reset['async_cleanup'] === true
            && $reset['display_active'] === true
            && $reset['display_restored'] === true,
         description: "H8 controls must decode valid messages and restore error handlers: $evidence"
      );

      $Secure = static function (array $Evidence): bool {
         $close = $Evidence['close'];

         return $Evidence['escaped'] === ''
            && $Evidence['diagnostic'] === null
            && $Evidence['warning_output'] === ''
            && $Evidence['stop'] === true
            && $Evidence['closing'] === true
            && $Evidence['message'] === false
            && is_array($close)
            && $close['opcode'] === WS::OPCODE_CLOSE
            && $close['fin'] === true
            && $close['masked'] === true
            && $close['error'] === 0
            && $close['consumed'] === $close['wire_bytes']
            && $close['code'] === 1007;
      };

      yield assert(
         assertion: $Secure($takeover) && $Secure($reset),
         description: "CONFIRMED H8: malformed server DEFLATE escaped the WS client protocol boundary before a complete close 1007; evidence=$evidence"
      );
   }
);
