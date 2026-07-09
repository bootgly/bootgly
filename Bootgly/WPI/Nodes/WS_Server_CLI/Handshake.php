<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\WS_Server_CLI;


use function array_map;
use function array_pad;
use function array_shift;
use function base64_decode;
use function base64_encode;
use function explode;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function sha1;
use function strcasecmp;
use function strlen;
use function strtolower;
use function trim;
use Closure;

use Bootgly\WPI\Modules\WS;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating\Guard;
use Bootgly\WPI\Nodes\WS_Server_CLI\Handshake\Request;
use Bootgly\WPI\Nodes\WS_Server_CLI\Handshake\Response;


class Handshake
{
   // * Config
   // @ Handshake negotiation policy, set by WS_Server_CLI::configure().
   /** @var array<string> */
   public static array $subprotocols = [];
   public static bool $compression = true;
   /** @var array<object> */
   public static array $Guards = [];
   // @ Custom upgrade predicate (Events::HandshakeRequested) — e.g. Origin allowlist.
   public static null|Closure $predicate = null;
   /**
    * HTTP fallback for plain (non-upgrade) requests — lets the server hand
    * out its own client page next to the WebSocket endpoint.
    * Return `[mediaType, body]` to serve, or null to answer 404.
    * When unset, non-upgrade requests are rejected with 400 (as before).
    *
    * @var null|Closure(string $target, array<string,mixed> $fields): (null|array{string,string})
    */
   public static null|Closure $fallback = null;


   /**
    * Validate a parsed HTTP upgrade request.
    *
    * @param array<string, mixed> $fields Lowercased header map from Frame::parse().
    *
    * @return null|int `null` on success, or the HTTP status code to reject with.
    */
   public static function validate (string $method, string $protocol, array $fields): null|int
   {
      // ? Request line
      if ($method !== 'GET') {
         return 400;
      }
      if ($protocol !== 'HTTP/1.1') {
         return 400;
      }
      // ? Host (RFC 9110 §7.2)
      if (self::read($fields, 'host') === '') {
         return 400;
      }
      // ? Upgrade: websocket (token match, not substring)
      if (self::check(self::read($fields, 'upgrade'), 'websocket') === false) {
         return 400;
      }
      // ? Connection: Upgrade (token, comma-separated)
      if (self::check(self::read($fields, 'connection'), 'upgrade') === false) {
         return 400;
      }
      // ? Sec-WebSocket-Key: 16 bytes, base64
      $key = self::read($fields, 'sec-websocket-key');
      if ($key === '' || strlen((string) base64_decode($key, true)) !== 16) {
         return 400;
      }
      // ? Sec-WebSocket-Version: 13
      if (self::read($fields, 'sec-websocket-version') !== '13') {
         return 426;
      }

      // :
      return null;
   }

   /**
    * Compute the Sec-WebSocket-Accept value (RFC 6455 §4.2.2).
    */
   public static function accept (string $key): string
   {
      return base64_encode(sha1($key . WS::HANDSHAKE_GUID, true));
   }

   /**
    * Pick the first server-supported subprotocol the client offered.
    *
    * @param array<string> $supported
    */
   public static function negotiate (string $offered, array $supported): string
   {
      // ?
      if ($offered === '' || $supported === []) {
         return '';
      }

      // @ Server preference wins: the first supported protocol the client offered.
      $offers = array_map('trim', explode(',', $offered));
      foreach ($supported as $candidate) {
         if (in_array($candidate, $offers, true)) {
            return $candidate;
         }
      }

      // :
      return '';
   }

   /**
    * Build the 101 Switching Protocols response.
    */
   public static function build (string $accept, string $subprotocol = '', string $extensions = ''): string
   {
      // !
      $response = "HTTP/1.1 101 Switching Protocols\r\n"
         . "Upgrade: websocket\r\n"
         . "Connection: Upgrade\r\n"
         . "Sec-WebSocket-Accept: {$accept}\r\n";

      // @
      if ($subprotocol !== '') {
         $response .= "Sec-WebSocket-Protocol: {$subprotocol}\r\n";
      }
      if ($extensions !== '') {
         $response .= "Sec-WebSocket-Extensions: {$extensions}\r\n";
      }

      // :
      return "{$response}\r\n";
   }

   /**
    * Negotiate the permessage-deflate extension (RFC 7692) from the client
    * offer. Returns the accepted parameter set, or [] when not offered/enabled.
    *
    * @return array<string, mixed>
    */
   public static function resolve (string $offer): array
   {
      // ?
      if ($offer === '' || self::$compression === false) {
         return [];
      }

      // @ Each comma-separated entry is one extension offer; we accept the
      //   first valid `permessage-deflate`.
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
                  // A bare offer is a capability; a value bounds the client window.
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
    * Build the negotiated Sec-WebSocket-Extensions response value.
    *
    * @param array<string, mixed> $params
    */
   public static function extend (array $params): string
   {
      // ?
      if (empty($params['permessage-deflate'])) {
         return '';
      }

      // @ The server inflates with a full window, so only the server's own
      //   compressor window + the no-context-takeover flags are echoed.
      $value = 'permessage-deflate';
      if (! empty($params['server_no_context_takeover'])) {
         $value .= '; server_no_context_takeover';
      }
      if (! empty($params['client_no_context_takeover'])) {
         $value .= '; client_no_context_takeover';
      }
      $serverBits = $params['server_max_window_bits'] ?? 15;
      if (is_int($serverBits) && $serverBits < 15) {
         $value .= "; server_max_window_bits={$serverBits}";
      }

      // :
      return $value;
   }

   /**
    * Run handshake authentication guards against the upgrade request.
    *
    * @param array<string, mixed> $fields Lowercased header map from Frame::parse().
    * @param array<object> $Guards Guard instances; the first to pass wins.
    *
    * @return null|Request The authenticated request adapter (carrying any
    *   identity/claims/tokenHeaders exposed by the guard) on success, or null
    *   when every guard denies.
    */
   public static function authenticate (array $fields, array $Guards): null|Request
   {
      // ! A request adapter exposing the upgrade headers + a Basic parser, so
      //   Bearer/JWT/Basic and header-reading guards work and can `expose()`.
      $Request = new Request($fields);

      // @
      foreach ($Guards as $Guard) {
         if ($Guard instanceof Guard && $Guard->authenticate($Request) === true) {
            return $Request;
         }
      }

      // :
      return null;
   }

   /**
    * Build the `WWW-Authenticate` challenge value from the first guard that
    * emits one (announce-style guards: Bearer/JWT/custom). Returns '' when none
    * do (e.g. Basic, which has no application-readable WS retry path).
    *
    * @param array<object> $Guards
    */
   public static function challenge (array $Guards): string
   {
      $Response = new Response;

      // @
      foreach ($Guards as $Guard) {
         if ($Guard instanceof Guard) {
            $Guard->challenge($Response);
            $value = $Response->Header->get('WWW-Authenticate');
            if ($value !== null) {
               return $value;
            }
         }
      }

      // :
      return '';
   }

   /**
    * Build a handshake rejection response for a status code.
    */
   /**
    * Run the custom upgrade predicate (Events::HandshakeRequested) against the
    * parsed request — e.g. an Origin allowlist or a rate-limit gate. Returns
    * true when no predicate is configured.
    *
    * @param array<string, mixed> $fields Lowercased header map from Frame::parse().
    */
   public static function permit (array $fields): bool
   {
      // ?
      if (self::$predicate === null) {
         return true;
      }

      // :
      return (self::$predicate)(new Request($fields)) === true;
   }

   public static function deny (int $code, string $challenge = ''): string
   {
      return match ($code) {
         426 => "HTTP/1.1 426 Upgrade Required\r\nSec-WebSocket-Version: 13\r\nConnection: close\r\n\r\n",
         403 => "HTTP/1.1 403 Forbidden\r\nConnection: close\r\n\r\n",
         401 => "HTTP/1.1 401 Unauthorized\r\n"
            . ($challenge !== '' ? "WWW-Authenticate: {$challenge}\r\n" : '')
            . "Connection: close\r\n\r\n",
         404 => "HTTP/1.1 404 Not Found\r\nConnection: close\r\n\r\n",
         default => "HTTP/1.1 400 Bad Request\r\nConnection: close\r\n\r\n",
      };
   }

   /**
    * Build a plain HTTP 200 response for a non-upgrade request — the
    * `$fallback` path (e.g. serving the WebSocket client page).
    */
   public static function serve (string $type, string $body): string
   {
      $length = strlen($body);

      return "HTTP/1.1 200 OK\r\n"
         . "Content-Type: {$type}\r\n"
         . "Content-Length: {$length}\r\n"
         . "Cache-Control: no-cache\r\n"
         . "Connection: close\r\n"
         . "\r\n"
         . $body;
   }

   /**
    * Read a single header value from the lowercased fields map.
    *
    * @param array<string, mixed> $fields
    */
   public static function read (array $fields, string $key): string
   {
      $value = $fields[$key] ?? '';

      // : First value when a header was repeated; the raw string otherwise.
      if (is_array($value)) {
         $first = $value[0] ?? '';
         return is_string($first) ? $first : '';
      }

      return is_string($value) ? $value : '';
   }

   /**
    * Check a comma-separated header for a case-insensitive token.
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
