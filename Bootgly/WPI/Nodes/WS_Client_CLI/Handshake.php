<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\WS_Client_CLI;


use function array_map;
use function array_pad;
use function array_shift;
use function base64_encode;
use function explode;
use function is_int;
use function random_bytes;
use function sha1;
use function strcasecmp;
use function strtolower;
use function trim;

use Bootgly\WPI\Modules\WS;


/**
 * RFC 6455 / RFC 7692 client handshake codec.
 *
 * Pure helpers: nonce generation, the accept hash (to verify the server's
 * `Sec-WebSocket-Accept`), the permessage-deflate parameter grammar, and the
 * client extension offer. Response orchestration lives in `Decoders\Decoder_`.
 */
class Handshake
{
   /**
    * Generate a fresh `Sec-WebSocket-Key` nonce (16 random bytes, base64).
    */
   public static function generate (): string
   {
      return base64_encode(random_bytes(16));
   }

   /**
    * Compute the `Sec-WebSocket-Accept` value (RFC 6455 §4.2.2) for the nonce we
    * sent, to compare against the server's response header.
    */
   public static function accept (string $key): string
   {
      return base64_encode(sha1($key . WS::HANDSHAKE_GUID, true));
   }

   /**
    * Build the client request `Sec-WebSocket-Extensions` offer value.
    *
    * @param array<string, mixed> $params
    */
   public static function offer (array $params = []): string
   {
      $value = 'permessage-deflate';
      if (! empty($params['client_no_context_takeover'])) {
         $value .= '; client_no_context_takeover';
      }
      if (! empty($params['server_no_context_takeover'])) {
         $value .= '; server_no_context_takeover';
      }

      // @ Advertise the client-window capability: a bare token lets the server
      //   pick, a value bounds our own compressor window.
      $clientBits = $params['client_max_window_bits'] ?? null;
      if (is_int($clientBits) && $clientBits >= 9 && $clientBits < 15) {
         $value .= "; client_max_window_bits={$clientBits}";
      }
      else {
         $value .= '; client_max_window_bits';
      }

      // :
      return $value;
   }

   /**
    * Parse the server's negotiated `Sec-WebSocket-Extensions` response value.
    * Returns the agreed `permessage-deflate` parameter set, or [] when the
    * server did not negotiate it.
    *
    * @return array<string, mixed>
    */
   public static function resolve (string $offer): array
   {
      // ?
      if ($offer === '') {
         return [];
      }

      // @ Each comma-separated entry is one extension; accept the first valid
      //   `permessage-deflate`.
      foreach (explode(',', $offer) as $entry) {
         $parts = array_map('trim', explode(';', $entry));
         $name = strtolower((string) array_shift($parts));
         if ($name !== 'permessage-deflate') {
            continue;
         }

         $accepted = [
            'permessage-deflate' => true,
            'server_no_context_takeover' => false,
            'client_no_context_takeover' => false,
            'server_max_window_bits' => 15,
            'client_max_window_bits' => 15,
         ];
         $valid = true;

         foreach ($parts as $param) {
            if ($param === '') {
               continue;
            }
            [$key, $value] = array_pad(explode('=', $param, 2), 2, null);
            $key = strtolower(trim((string) $key));
            $value = $value !== null ? trim($value, " \t\"") : null;

            switch ($key) {
               case 'server_no_context_takeover':
                  $accepted['server_no_context_takeover'] = true;
                  break;
               case 'client_no_context_takeover':
                  $accepted['client_no_context_takeover'] = true;
                  break;
               case 'server_max_window_bits':
                  if ($value !== null) {
                     $bits = (int) $value;
                     if ($bits < 9 || $bits > 15) {
                        $valid = false;
                     }
                     else {
                        $accepted['server_max_window_bits'] = $bits;
                     }
                  }
                  break;
               case 'client_max_window_bits':
                  if ($value !== null) {
                     $bits = (int) $value;
                     if ($bits < 9 || $bits > 15) {
                        $valid = false;
                     }
                     else {
                        $accepted['client_max_window_bits'] = $bits;
                     }
                  }
                  break;
               default:
                  $valid = false;
            }
         }

         if ($valid) {
            return $accepted;
         }
      }

      // :
      return [];
   }

   /**
    * Check a comma-separated header for a case-insensitive token (e.g. the
    * response `Upgrade: websocket` / `Connection: Upgrade`).
    */
   public static function check (string $header, string $token): bool
   {
      foreach (explode(',', $header) as $part) {
         if (strcasecmp(trim($part), $token) === 0) {
            return true;
         }
      }

      return false;
   }
}
