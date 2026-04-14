<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response\Decoders;


use function count;
use function explode;
use function end;
use function key;
use function stripos;
use function strlen;
use function strtolower;
use function strpos;
use function strstr;
use function substr;
use function trim;

use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response\Decoder;


class Decoder_ extends Decoder
{
   /**
    * @return null|array{protocol: string, code: int, status: string, headerRaw: string, bodyRaw: string, bodyLength: int, bodyDownloaded: int, bodyWaiting: bool, chunked: bool, closeConnection: bool, interim: bool, consumed: int}
    */
   public function decode (string $buffer, int $size, null|string $method = null): null|array
   {
      /** @var array<string, array{protocol: string, code: int, status: string, headerRaw: string, bodyRaw: string, bodyLength: int, bodyDownloaded: int, bodyWaiting: bool, chunked: bool, closeConnection: bool, interim: bool, consumed: int}> $cache */
      static $cache = [];

      // ? Check local cache (only for method-independent short responses)
      if ($method === null && $size <= 2048 && isSet($cache[$buffer])) {
         return $cache[$buffer];
      }

      // @ Find end of header section
      $separator = strpos($buffer, "\r\n\r\n");
      if ($separator === false) {
         return null; // @ Incomplete headers, wait for more data
      }

      // @ Minimum consumed bytes = header section + CRLFCRLF separator
      $headerSectionLength = $separator + 4;

      // # Parse status-line
      $statusLine = strstr($buffer, "\r\n", true);
      if ($statusLine === false) {
         return null;
      }

      $parts = explode(' ', $statusLine, 3);
      if (count($parts) < 2) {
         return null;
      }

      $protocol = $parts[0];
      $code     = (int) $parts[1];
      $status   = $parts[2] ?? '';

      // # Extract header section (between status-line CRLF and CRLFCRLF)
      $metaLength = strlen($statusLine);
      $headerRaw  = substr($buffer, $metaLength + 2, $separator - $metaLength - 2);

      // @ Connection management
      $closeConnection = stripos($headerRaw, 'Connection: close') !== false
         || ($protocol === 'HTTP/1.0'
            && stripos($headerRaw, 'Connection: keep-alive') === false);

      // @ RFC 9112 §6.3 — Determine message body length (normative order)

      // --- Rule 1: HEAD and responses with no defined body (1xx, 204, 304) ---
      $isInformational = ($code >= 100 && $code < 200);
      $noBody = ($method === 'HEAD')
         || $isInformational
         || ($code === 204)
         || ($code === 304);

      if ($noBody) {
         $parsed = [
            'protocol'        => $protocol,
            'code'            => $code,
            'status'          => $status,
            'headerRaw'       => $headerRaw,
            'bodyRaw'         => '',
            'bodyLength'      => 0,
            'bodyDownloaded'  => 0,
            'bodyWaiting'     => false,
            'chunked'         => false,
            'closeConnection' => $closeConnection,
            'interim'         => $isInformational,
            'consumed'        => $headerSectionLength,
         ];

         // @ Cache small, method-independent no-body responses
         if ($method === null && $headerSectionLength <= 2048) {
            $cache[$buffer] = $parsed;
            if (count($cache) > 512) {
               unset($cache[key($cache)]);
            }
         }

         return $parsed;
      }

      // --- Rule 3: Transfer-Encoding (takes precedence over Content-Length) ---
      $transferCodings = [];

      // @ Look for TE field: may be first header (no preceding CRLF) or subsequent
      $tePos = stripos($headerRaw, "\r\nTransfer-Encoding:");
      if ($tePos !== false) {
         $teStart = $tePos + 20; // len("\r\nTransfer-Encoding:") = 20
         $teEnd   = strpos($headerRaw, "\r\n", $teStart);
         $teLine  = $teEnd !== false
            ? substr($headerRaw, $teStart, $teEnd - $teStart)
            : substr($headerRaw, $teStart);
      }
      else if (stripos($headerRaw, "Transfer-Encoding:") === 0) {
         $teEnd   = strpos($headerRaw, "\r\n", 18);
         $teLine  = $teEnd !== false
            ? substr($headerRaw, 18, $teEnd - 18)
            : substr($headerRaw, 18);
      }
      else {
         $teLine = null;
      }

      if ($teLine !== null) {
         foreach (explode(',', $teLine) as $coding) {
            $c = strtolower(trim($coding));
            if ($c !== '') {
               $transferCodings[] = $c;
            }
         }
      }

      // chunked = Transfer-Encoding list is non-empty AND last coding is "chunked"
      $chunked = ! empty($transferCodings) && end($transferCodings) === 'chunked';

      // --- Rule 4: Content-Length (ignored when Transfer-Encoding is present) ---
      $contentLength = null;
      if (empty($transferCodings)) {
         $clRaw = null;

         $clPos = stripos($headerRaw, "\r\nContent-Length:");
         if ($clPos !== false) {
            $clStart = $clPos + 17; // len("\r\nContent-Length:") = 17
            $clEnd   = strpos($headerRaw, "\r\n", $clStart);
            $clRaw   = $clEnd !== false
               ? substr($headerRaw, $clStart, $clEnd - $clStart)
               : substr($headerRaw, $clStart);
         }
         else if (stripos($headerRaw, "Content-Length:") === 0) {
            $clEnd = strpos($headerRaw, "\r\n", 15);
            $clRaw = $clEnd !== false
               ? substr($headerRaw, 15, $clEnd - 15)
               : substr($headerRaw, 15);
         }

         if ($clRaw !== null) {
            $contentLength = (int) trim($clRaw);
         }
      }

      // @ Body handling
      $bodyRaw        = '';
      $bodyLength     = 0;
      $bodyDownloaded = 0;
      $bodyWaiting    = false;
      $consumed       = $headerSectionLength;

      if ($chunked) {
         // --- Rule 3a: chunked Transfer-Encoding ---
         // consumed = header section only; body ownership transferred to Decoder_Chunked
         $bodyWaiting  = true;
         $initialBody  = substr($buffer, $separator + 4);
         if ($initialBody !== '') {
            $bodyRaw        = $initialBody;
            $bodyDownloaded = strlen($initialBody);
         }
      }
      else if (! empty($transferCodings)) {
         // --- Rule 3b: non-chunked Transfer-Encoding (e.g. identity, gzip applied alone) ---
         // Body length determined by connection close; treat same as close-delimited
         $bodyData = substr($buffer, $separator + 4);
         if ($bodyData !== '') {
            $bodyRaw        = $bodyData;
            $bodyDownloaded = strlen($bodyData);
            $bodyLength     = $bodyDownloaded;
         }
         $consumed    = 0; // keep headers in $pendingBuffer for re-parse on next read
         $bodyWaiting = true;  // finalized by disconnect handler (Phase 5)
      }
      else if ($contentLength !== null) {
         // --- Rule 4: Content-Length ---
         $bodyLength = $contentLength;
         $consumed  += $contentLength;

         if ($contentLength > 0) {
            $bodyData = substr($buffer, $separator + 4, $contentLength);
            if ($bodyData !== '') {
               $bodyRaw        = $bodyData;
               $bodyDownloaded = strlen($bodyData);
            }

            if ($bodyDownloaded < $contentLength) {
               $bodyWaiting = true;
            }
         }
      }
      else {
         // --- Rule 5: close-delimited (HTTP/1.0 style, no framing metadata) ---
         // Accumulate in $pendingBuffer until connection closes (Phase 5 finalizes)
         $bodyData = substr($buffer, $separator + 4);
         if ($bodyData !== '') {
            $bodyRaw        = $bodyData;
            $bodyDownloaded = strlen($bodyData);
            $bodyLength     = $bodyDownloaded;
         }
         $consumed    = 0; // keep headers in $pendingBuffer for re-parse on next read
         $bodyWaiting = true;  // finalized by disconnect handler (Phase 5)
      }

      $parsed = [
         'protocol'        => $protocol,
         'code'            => $code,
         'status'          => $status,

         'headerRaw'       => $headerRaw,

         'bodyRaw'         => $bodyRaw,
         'bodyLength'      => $bodyLength,
         'bodyDownloaded'  => $bodyDownloaded,
         'bodyWaiting'     => $bodyWaiting,

         'chunked'         => $chunked,
         'closeConnection' => $closeConnection,
         'interim'         => false,
         'consumed'        => $consumed,
      ];

      // @ Cache small, complete, method-independent responses
      if ($method === null && $consumed > 0 && $consumed <= 2048 && ! $bodyWaiting) {
         $cache[$buffer] = $parsed;
         if (count($cache) > 512) {
            unset($cache[key($cache)]);
         }
      }

      return $parsed;
   }
}
