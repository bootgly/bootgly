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
use function strncmp;
use function strpos;
use function substr;

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
   public function decode (Packages $Package, string $buffer, int $size): States
   {
      /** @var array<string,Request> $inputs */
      static $inputs = []; // @ L1 cache (stable/hot keys)
      /** @var TCP_Packages $Package */

      // ? RFC 7230 section 3.5 robustness: ignore empty line(s) — CRLF —
      //   received prior to the request-line. Lenient peers emit stray
      //   CRLFs between pipelined requests; the skipped padding COUNTS AS
      //   CONSUMED so the transport's pipeline/carry offsets stay exact
      //   (retaining padding would poison the next request's reassembly).
      //   Hot path pays one first-byte compare.
      $skipped = 0;
      if ($buffer[0] === "\r") {
         while (substr($buffer, $skipped, 2) === "\r\n") {
            $skipped += 2;
         }

         // ? Only padding in this event: consume it all and wait.
         if ($skipped >= $size) {
            $Package->cache = false;
            $Package->consumed = $size;
            return States::Incomplete;
         }

         if ($skipped > 0) {
            $buffer = substr($buffer, $skipped);
            $size -= $skipped;
         }
      }

      // ? h2c prior-knowledge neutral sniffing. A one-byte `P` can be a
      //   segmented POST/PUT/PATCH, so an ambiguous short prefix returns
      //   Incomplete with nothing consumed — the transport carry retains it
      //   and the next event reassembles until the protocol can be
      //   distinguished (`Request::decode()` commits HTTP/2 at >= 14 bytes).
      if (
         Server::$enableHTTP2
         && $Package->Connection->writes === 0
         && $buffer[0] === 'P'
      ) {
         $signal = min($size, 14);
         if (strncmp($buffer, HTTP2::PREFACE, $signal) === 0 && $size < 14) {
            $Package->cache = false;
            $Package->consumed = $skipped;
            return States::Incomplete;
         }
      }

      // ! Reassembled events are never L1-cached: their keys are
      //   attacker-mutable at packet granularity (any request split into
      //   two packets would churn the LRU) with no benign hit-rate benefit.
      $cacheable = ($Package->carried === false && $size <= 2048);
      $cacheKey = null;
      if ($cacheable) {
         // ?! Exact-input lookup FIRST: stored keys can never carry a
         //    request-line query (the store below classifies before writing),
         //    so a hit both proves cacheability and skips the per-request '?'
         //    scan entirely. Only misses pay the classification.
         if (isSet($inputs[$buffer])) {
            $cacheKey = $buffer;
         }
         else {
            // @ Do not cache query-bearing targets. They are attacker-mutable
            //   and create one-shot key churn that evicts hot entries with no
            //   hit-rate benefit (DoS amplification vector).
            $queryMark = strpos($buffer, '?');
            if ($queryMark !== false) {
               $requestLineEnd = strpos($buffer, "\r\n");
               if ($requestLineEnd === false || $queryMark < $requestLineEnd) {
                  $cacheable = false;
               }
            }

            $cacheKey = (!$cacheable) ? null : $buffer;
         }
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
         //   See tests/Security/03.01-decoder_cache_shared_request_across_connections.test.php
         /** @var Request $Request */
         $Request = $Package->decoded ??= new Request;
         $Request->assume($cached, $Package->Connection);
         Server::$Request = $Request;

         $Package->consumed = $size + $skipped;
         return States::Complete;
      }

      // !
      $WPI = WPI;
      /** @var Request $Request */
      $Request = $WPI->Request;
      // ?! Handle Package cache — a fresh Request is also required when the
      //    worker cell is still claimed by a paused body decode of another
      //    connection (its `Body->waiting` stays true until that decoder
      //    completes): re-entering the claimed instance would corrupt its
      //    in-flight body state.
      if ($Package->changed || $Request->Body->waiting) {
         $WPI->Request = $Request = new Request;
      }

      // @
      $state = $Request->decode($Package, $buffer, $size);
      $length = $Package->consumed;

      // ! Account the skipped request-line padding as consumed (every
      //   `decode()` outcome writes `consumed` in trimmed-buffer space).
      if ($skipped > 0) {
         $Package->consumed += $skipped;
      }

      // @ Write to local cache
      // Skip caching when the read was not exactly one complete request
      // (a pipelined batch would store the whole batch as the template of
      // its first request and hits would then drop the tail), when Body is
      // waiting for more data (chunked/streaming) or when this read
      // installed a per-connection decoder (e.g. the HTTP/2 preface
      // switch) — protocol-switch bytes must never be served as an
      // HTTP/1.1 template.
      if ($state === States::Complete
         && $cacheKey !== null
         && $Package->Decoder === null
         && $length > 0
         && $length === $size
         && ! $Request->Body->waiting
      ) {
         // ! Intentional bare read — the URL property hook derives and
         //   memoizes the private `_URL` as its side effect, so the clone
         //   below carries a warmed routing target: every future L1
         //   hit's Router read then fast-returns instead of re-running the
         //   URL derivation per request. Same bytes, same base — same URL.
         $Request->URL; // @phpstan-ignore expr.resultUnused (hook side effect: memoizes the private _URL)
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
