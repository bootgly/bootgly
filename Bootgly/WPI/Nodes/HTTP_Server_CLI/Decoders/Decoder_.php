<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders;


use function array_key_first;
use function array_key_last;
use function count;
use function min;
use function strlen;
use function strncmp;
use function strpos;

use const Bootgly\WPI;
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Endpoints\Servers\Packages;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCP_Packages;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;


class Decoder_ extends Decoders
{
   // @ Carried first-request bytes while h2c prior-knowledge sniffing is ambiguous.
   public string $buffer = '';


   public function decode (Packages $Package, string $buffer, int $size): States
   {
      /** @var array<string,Request> $inputs */
      static $inputs = []; // @ L1 cache (stable/hot keys)
      /** @var TCP_Packages $Package */

      $carried = 0;
      if ($this->buffer !== '') {
         $carried = strlen($this->buffer);
         $buffer = "{$this->buffer}{$buffer}";
         $size += $carried;
         $this->buffer = '';
      }

      // ? h2c prior-knowledge neutral sniffing. A one-byte `P` can be a
      //   segmented POST/PUT/PATCH, so short prefixes are carried by a
      //   per-connection Decoder_ until the protocol can be distinguished.
      if (
         Server::$enableHTTP2
         && $Package->Connection->writes === 0
         && $buffer[0] === 'P'
      ) {
         $signal = min($size, 14);
         if (strncmp($buffer, HTTP2::PREFACE, $signal) === 0 && $size < 14) {
            if ($Package->Decoder === null) {
               $Decoder = new self;
               $Decoder->buffer = $buffer;
               $Package->Decoder = $Decoder;
            }
            else {
               $this->buffer = $buffer;
            }

            $Package->cache = false;
            $Package->consumed = $size - $carried;
            return States::Incomplete;
         }
      }

      $cacheable = ($carried === 0 && $size <= 2048);
      $cacheKey = null;
      if ($cacheable) {
         // @ Do not cache query-bearing targets. They are attacker-mutable and
         //   create one-shot key churn that evicts hot entries with no hit-rate
         //   benefit (DoS amplification vector).
         // ?! Probe for '?' first: query-less requests (the hot case) cost a
         //   single memchr scan and skip the CRLF scan entirely.
         $queryMark = strpos($buffer, '?');
         if ($queryMark !== false) {
            $requestLineEnd = strpos($buffer, "\r\n");
            if ($requestLineEnd === false || $queryMark < $requestLineEnd) {
               $cacheable = false;
            }
         }

         $cacheKey = (!$cacheable) ? null : $buffer;
      }

      // ? Check local cache and return
      if ($cacheKey !== null && isSet($inputs[$cacheKey])) {
         $cached = $inputs[$cacheKey];
         // @ LRU touch on hit (move to tail) — skipped when the key is
         //   already the most recent (single-hot-key workloads).
         if (array_key_last($inputs) !== $cacheKey) {
            unset($inputs[$cacheKey]);
            $inputs[$cacheKey] = $cached;
         }

         // ! Security: never serve the cached template directly. Handler /
         //   middleware mutations (attributes, Header writes, auth decisions)
         //   would persist on it and leak to every future connection that
         //   sends byte-identical headers.
         //   Instead of `clone $cached` (allocates Request + Header + Body per
         //   hit), each connection owns one Request reused across keep-alive
         //   requests: `assume()` overwrites every decode-derived member from
         //   the template and scrubs all per-request state unconditionally —
         //   no state survives between requests on the same connection.
         //   See tests/Security/2.01-decoder_cache_shared_request_across_connections.test.php
         /** @var Request $Request */
         $Request = $Package->decoded ??= new Request;
         $Request->assume($cached, $Package->Connection);
         Server::$Request = $Request;

         $Package->consumed = $size;
         return States::Complete;
      }

      // !
      $WPI = WPI;
      // ?! Handle Package cache
      if ($Package->changed) {
         $WPI->Request = new Request;
      }
      // !
      /** @var Request $Request */
      $Request = $WPI->Request;

      // @
      $state = $Request->decode($Package, $buffer, $size);
      $length = $Package->consumed;

      if ($carried > 0 && $Package->consumed > 0) {
         $Package->consumed = $Package->consumed > $carried
            ? $Package->consumed - $carried
            : 0;
         $length = $Package->consumed;
      }

      if ($state === States::Incomplete && $Package->Decoder === $this) {
         $this->buffer = $buffer;
         $Package->consumed = $size - $carried;
      }

      // @ Write to local cache
      // Skip caching when Body is waiting for more data (chunked/streaming)
      // or when this read installed a per-connection decoder (e.g. the
      // HTTP/2 preface switch) — protocol-switch bytes must never be
      // served as an HTTP/1.1 template.
      if ($state === States::Complete
         && $cacheKey !== null
         && $Package->Decoder === null
         && $length > 0
         && $length <= 2048
         && ! $Request->Body->waiting
      ) {
         $inputs[$cacheKey] = clone $Request;

         if (count($inputs) > 512) {
            // @ Cache is deliberately bounded (max 513 before eviction), so this
            //   eviction path is stable in practice. If capacity grows materially,
            //   replace this with a dedicated hash + linked-list LRU.
            $oldestKey = array_key_first($inputs);
            unset($inputs[$oldestKey]);
         }
      }

      return $state;
   }
}
