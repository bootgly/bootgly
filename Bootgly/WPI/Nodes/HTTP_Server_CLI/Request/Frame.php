<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;


use function ctype_digit;
use function explode;
use function is_string;
use function preg_match;
use function strcasecmp;
use function stripos;
use function strlen;
use function strpos;
use function strspn;
use function strstr;
use function strtolower;
use function substr;
use function trim;

use Bootgly\API\Environments;
use Bootgly\API\Workables\Server;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;


/**
 * Centralized HTTP/1.1 framing parser (Recommendation #1).
 *
 * Performs a SINGLE linear scan over the request-head buffer and produces a
 * canonical value object. Replaces the previous combination of:
 *   - ad-hoc `stripos` chains in `Request::decode()` for `Content-Length`,
 *     `Transfer-Encoding`, `Expect`, `Content-Type`, `Connection`, and `Host`;
 *   - a parallel parse in `Request\Raw\Header::build()` that built the
 *     application-facing `$fields` map.
 *
 * One scan, one source of truth: the same loop that decides framing also
 * builds the lowercased `$fields` array consumed by `Header::adopt()`.
 *
 * Rejection contract: on any rejection, `Frame::parse()` calls
 * `$Package->reject()` itself and returns `null`. The caller (`Request::decode()`)
 * just returns `0`. Incomplete buffers also return `null` (without `reject()`)
 * so the caller waits for more bytes.
 */
final class Frame
{
   // # Request line
   public string $method = '';
   public string $URI = '';
   public string $protocol = '';

   // # Header block
   /** Raw header block, with any test-only `X-Bootgly-Test:` line stripped. */
   public string $headerRaw = '';
   /** @var array<string, string|array<int,string>> Lowercase-keyed field map. */
   public array $fields = [];

   // # Framing decisions
   public ?int $contentLength = null;
   public bool $chunked = false;
   public bool $expectContinue = false;
   public string $contentType = '';
   public string $multipartBoundary = '';
   public string $hostValue = '';
   public bool $closeConnection = false;

   // # Offsets
   public int $separatorPosition = 0;
   public int $length = 0;


   /**
    * Parse the request head out of `$buffer`.
    *
    * @return self|null `null` when the head is incomplete (caller waits for
    *   more bytes) OR when the request was rejected (`$Package->reject()`
    *   has already been invoked). Both cases must result in the caller
    *   returning `0` from `Request::decode()`.
    */
   public static function parse (Packages $Package, string &$buffer, int $size): ?self
   {
      // @ Locate the head/body separator.
      $separator_position = strpos($buffer, "\r\n\r\n");
      if ($separator_position === false) {
         if ($size >= 16384) {
            $Package->reject("HTTP/1.1 413 Request Entity Too Large\r\n\r\n");
         }
         return null;
      }

      // @ Request line (first line of the head).
      $meta_raw = strstr($buffer, "\r\n", true);
      if ($meta_raw === false) {
         $Package->reject("HTTP/1.1 400 Bad Request\r\n\r\n");
         return null;
      }
      @[$method, $URI, $protocol] = explode(' ', $meta_raw, 3);
      if (! $method || ! $URI || ! $protocol) {
         $Package->reject("HTTP/1.1 400 Bad Request\r\n\r\n");
         return null;
      }
      switch ($method) {
         case 'GET':
         case 'HEAD':
         case 'POST':
         case 'PUT':
         case 'PATCH':
         case 'DELETE':
         case 'OPTIONS':
            break;
         case 'TRACE':
         case 'CONNECT':
            $Package->reject("HTTP/1.1 501 Not Implemented\r\n\r\n");
            return null;
         default:
            $Package->reject(
               "HTTP/1.1 405 Method Not Allowed\r\n"
               . "Allow: GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS\r\n\r\n"
            );
            return null;
      }
      if (strlen($URI) > 8192) {
         $Package->reject("HTTP/1.1 414 URI Too Long\r\n\r\n");
         return null;
      }

      $meta_length = strlen($meta_raw);
      $header_raw = substr(
         $buffer,
         $meta_length + 2,
         $separator_position - $meta_length
      );

      // @ Strip the test-only `X-Bootgly-Test:` line BEFORE the field scan
      //   so neither `$Request->raw` nor `$Request->headers` surface it. The
      //   value is parked on the Encoder_Testing dispatch path via SAPI.
      if (Server::$Environment === Environments::Test) {
         $xbStart = stripos($header_raw, "\r\nX-Bootgly-Test:");
         if ($xbStart === false && stripos($header_raw, "X-Bootgly-Test:") === 0) {
            $xbLineEnd = strpos($header_raw, "\r\n");
            if ($xbLineEnd !== false) {
               $value = trim(substr($header_raw, 15, $xbLineEnd - 15));
               Server::$testIndexHeader = ctype_digit($value) ? (int) $value : null;
               $header_raw = substr($header_raw, $xbLineEnd + 2);
            }
         }
         else if ($xbStart !== false) {
            $xbLineEnd = strpos($header_raw, "\r\n", $xbStart + 2);
            if ($xbLineEnd !== false) {
               $value = trim(substr(
                  $header_raw, $xbStart + 17, $xbLineEnd - $xbStart - 17
               ));
               Server::$testIndexHeader = ctype_digit($value) ? (int) $value : null;
               $header_raw = substr($header_raw, 0, $xbStart)
                  . substr($header_raw, $xbLineEnd);
            }
         }
      }

      // @ Single linear scan over the header block.
      //   Produces simultaneously: the application `$fields` map (lowercased
      //   keys) and every framing decision needed by `Request::decode()`.
      $fields = [];
      $contentLength = null;
      $contentLengthSeen = false;
      $chunked = false;
      $transferEncodingSeen = false;
      $expectContinue = false;
      $contentType = '';
      $hostCount = 0;
      $hostValue = '';
      $closeConnection = false;
      $keepAliveSeen = false;

      foreach (explode("\r\n", $header_raw) as $line) {
         // ! Match Header::build() admission: require `: ` (colon + space)
         //   AND no space in the field name.
         $sepPos = strpos($line, ': ');
         if ($sepPos === false) {
            continue;
         }
         $rawKey = substr($line, 0, $sepPos);
         if (strpos($rawKey, ' ') !== false) {
            continue;
         }
         $rawValue = substr($line, $sepPos + 2);
         $key = strtolower($rawKey);

         // # fields map (RFC 9110 §5.1: case-insensitive).
         if ( isSet($fields[$key]) ) {
            if ( is_string($fields[$key]) ) {
               $fields[$key] = [$fields[$key]];
            }
            $fields[$key][] = $rawValue;
         }
         else {
            $fields[$key] = $rawValue;
         }

         // # Framing-relevant headers (RFC 9112 §6.1, §6.3, RFC 9110 §10.1.1).
         switch ($key) {
            case 'content-length':
               // Reject duplicates (smuggling guard).
               if ($contentLengthSeen) {
                  $Package->reject("HTTP/1.1 400 Bad Request\r\n\r\n");
                  return null;
               }
               $contentLengthSeen = true;
               $clValue = trim($rawValue, " \t");
               if (
                  $clValue === ''
                  || strlen($clValue) > 19
                  || ! ctype_digit($clValue)
               ) {
                  $Package->reject("HTTP/1.1 400 Bad Request\r\n\r\n");
                  return null;
               }
               $contentLength = (int) $clValue;
               break;

            case 'transfer-encoding':
               // Reject duplicates (smuggling guard).
               if ($transferEncodingSeen) {
                  $Package->reject("HTTP/1.1 400 Bad Request\r\n\r\n");
                  return null;
               }
               $transferEncodingSeen = true;
               $teValue = trim($rawValue, " \t");
               // Request-side TE MUST be exactly "chunked" — list / variant
               // forms are smuggling vectors.
               if (strcasecmp($teValue, 'chunked') !== 0) {
                  $Package->reject("HTTP/1.1 501 Not Implemented\r\n\r\n");
                  return null;
               }
               $chunked = true;
               break;

            case 'expect':
               $exValue = trim($rawValue, " \t");
               if (strcasecmp($exValue, '100-continue') !== 0) {
                  $Package->reject("HTTP/1.1 417 Expectation Failed\r\n\r\n");
                  return null;
               }
               $expectContinue = true;
               break;

            case 'content-type':
               $contentType = trim($rawValue, " \t");
               break;

            case 'host':
               $hostCount++;
               if ($hostCount > 1) {
                  $Package->reject("HTTP/1.1 400 Bad Request\r\n\r\n");
                  return null;
               }
               $hostValue = trim($rawValue, " \t");
               break;

            case 'connection':
               $cValue = trim($rawValue, " \t");
               if (strcasecmp($cValue, 'close') === 0) {
                  $closeConnection = true;
               }
               else if (strcasecmp($cValue, 'keep-alive') === 0) {
                  $keepAliveSeen = true;
               }
               break;
         }
      }

      // @ TE+CL conflict (RFC 9112 §6.1 — must be rejected).
      if ($chunked && $contentLength !== null) {
         $Package->reject("HTTP/1.1 400 Bad Request\r\n\r\n");
         return null;
      }

      // @ Expect: 100-continue interlocks (audit finding #9).
      if ($expectContinue) {
         // Refuse Expect + chunked: app has no chance to veto the body.
         if ($chunked) {
            $Package->reject("HTTP/1.1 417 Expectation Failed\r\n\r\n");
            return null;
         }
         // Refuse Expect + oversized CL up front (no 100→413 sequence).
         if ($contentLength !== null && $contentLength > Request::$maxFileSize) {
            $Package->reject("HTTP/1.1 413 Request Entity Too Large\r\n\r\n");
            return null;
         }
      }

      // @ Host header validation (RFC 9112 §3.2 — required for HTTP/1.1).
      if ($protocol === 'HTTP/1.1' && $hostValue === '') {
         $Package->reject("HTTP/1.1 400 Bad Request\r\n\r\n");
         return null;
      }

      // @ Multipart detection + body-size cap (only when CL is set).
      $multipartBoundary = '';
      if ($contentLength !== null) {
         $isMultipart = $contentType !== ''
            && stripos($contentType, 'multipart/form-data') === 0;

         if ($isMultipart) {
            if ( preg_match('/boundary="?(\S+)"?/', $contentType, $bMatch) ) {
               $b = $bMatch[1];
               $bl = strlen($b);
               // Strip a trailing `"` left by the greedy `\S+` capture
               // when the value was quoted (`boundary="foo"`).
               if ($b[$bl - 1] === '"') {
                  $b = substr($b, 0, -1);
                  $bl--;
               }
               // RFC 7578 §4.1 / RFC 2046 §5.1.1: bchars + length 1..70.
               static $boundaryChars =
                  "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz"
                  . "0123456789'()+_,-./:=?";
               if ($bl < 1 || $bl > 70 || strspn($b, $boundaryChars) !== $bl) {
                  $Package->reject("HTTP/1.1 400 Bad Request\r\n\r\n");
                  return null;
               }
               $multipartBoundary = "--$b";
            }
            else {
               // multipart/form-data without a boundary is malformed.
               $Package->reject("HTTP/1.1 400 Bad Request\r\n\r\n");
               return null;
            }
         }

         $maxSize = $isMultipart
            ? Request::$maxFileSize
            : Request::$maxBodySize;
         if ($contentLength > $maxSize) {
            $Package->reject("HTTP/1.1 413 Request Entity Too Large\r\n\r\n");
            return null;
         }
      }

      // @ Connection management (RFC 9112 §9.3).
      //   HTTP/1.0 closes by default unless `Connection: keep-alive`.
      if ($protocol === 'HTTP/1.0' && ! $keepAliveSeen) {
         $closeConnection = true;
      }

      // @ Build and return the parsed frame.
      $Frame = new self;
      $Frame->method = $method;
      $Frame->URI = $URI;
      $Frame->protocol = $protocol;
      $Frame->headerRaw = $header_raw;
      $Frame->fields = $fields;
      $Frame->contentLength = $contentLength;
      $Frame->chunked = $chunked;
      $Frame->expectContinue = $expectContinue;
      $Frame->contentType = $contentType;
      $Frame->multipartBoundary = $multipartBoundary;
      $Frame->hostValue = $hostValue;
      $Frame->closeConnection = $closeConnection;
      $Frame->separatorPosition = $separator_position;
      $Frame->length = $separator_position + 4 + ($contentLength ?? 0);

      return $Frame;
   }
}
