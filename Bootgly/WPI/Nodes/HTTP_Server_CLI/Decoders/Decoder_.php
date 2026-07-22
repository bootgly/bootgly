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
            $Package->consumed = $skipped;
            return States::Incomplete;
         }
      }

      // ? L0 — per-connection consecutive-repeat template. One exact-input
      //   compare fronts the shared L1: an identical CONSECUTIVE repeat
      //   (fixed keep-alive API clients, benchmark loads) adopts without
      //   query classification, key hashing or LRU work. Unlike the shared
      //   L1, the L0 key MAY carry a query and MAY come from a reassembled
      //   event — churn and memory stay confined to this connection
      //   (self-inflicted; key bounded at 2,048 bytes). The compare is in
      //   trimmed space, so a repeat with/without stray padding still hits.
      $repeat = ($buffer === $Package->known);
      if ($repeat) {
         /** @var null|Request $Template */
         $Template = $Package->Template;
      }
      else {
         // @ Churn: re-key to this event's bytes; drop the stale template.
         //   Unique-target traffic pays one refcount assign per request and
         //   never builds a template (see the second-sighting store below).
         $Template = null;
         $Package->known = ($size <= 2048) ? $buffer : '';
         $Package->Template = null;
      }

      // ! Reassembled events are never L1-cached: their keys are
      //   attacker-mutable at packet granularity (any request split into
      //   two packets would churn the LRU) with no benign hit-rate benefit.
      //   An L0 hit needs no classification at all.
      $cacheable = ($Template === null && $Package->carried === false && $size <= 2048);
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

      // ? Check the shared cache
      if ($cacheKey !== null && isSet($inputs[$cacheKey])) {
         /** @var Request $Template */
         $Template = $inputs[$cacheKey];
         // @ LRU touch on hit (move to tail) — skipped when the key is
         //   already the most recent (single-hot-key workloads).
         if (array_key_last($inputs) !== $cacheKey) {
            unset($inputs[$cacheKey]);
            $inputs[$cacheKey] = $Template;
         }

         // @ Alias the entry into the connection's L0 (pointer write, no
         //   clone — stored templates are only ever `assume()`-read): the
         //   next identical repeat short-circuits ahead of the
         //   classification and lookup above. `$Package->known` already
         //   holds these bytes (matched, or just churned to them).
         $Package->Template = $Template;
      }

      // ?: Unified hit path — L0 or L1 template adoption.
      if ($Template !== null) {
         // ! Security: never serve the cached template directly. Handler /
         //   middleware mutations (attributes, Header writes, auth decisions)
         //   would persist on it and leak to every future connection that
         //   sends byte-identical headers.
         //   Instead of `clone $Template` (allocates Request + Header + Body
         //   per hit), each connection owns one Request reused across
         //   keep-alive requests: `assume()` overwrites every decode-derived
         //   member from the template and scrubs all per-request state
         //   unconditionally — no state survives between requests on the
         //   same connection.
         //   See tests/Security/03.01-decoder_cache_shared_request_across_connections.test.php
         /** @var Request $Request */
         $Request = $Package->decoded ??= new Request;
         $Request->assume($Template, $Package->Connection);
         Server::$Request = $Request;

         $Package->consumed = $size + $skipped;
         return States::Complete;
      }

      // ! Decode the miss into the connection-owned Request — no per-request
      //   allocation. Ownership by connection retires the old claimed-cell
      //   guard structurally: while THIS connection's body decode is paused,
      //   its installed body decoder owns the dispatch and this decoder
      //   never runs; other connections decode into their own instances.
      //   `decoded` is polymorphic (Request | Decoder_HTTP2 | SSE | WS
      //   Session), but every non-Request occupant also installs
      //   `$Package->Decoder`, so this path only ever meets its own Request
      //   — except the h2c preface below, where `decode()` overwrites the
      //   slot with the HTTP/2 decoder deliberately (one spare Request per
      //   h2c connection).
      /** @var Request $Request */
      $Request = $Package->decoded ??= new Request;
      // ?! Unconditional straight-line scrub: `decode()` rewrites every
      //    decode-derived member itself, but not the per-request
      //    accumulators nor a body-less request's Body.
      $Request->reset();

      // @
      $state = $Request->decode($Package, $buffer, $size);
      $length = $Package->consumed;

      // ! Account the skipped request-line padding as consumed (every
      //   `decode()` outcome writes `consumed` in trimmed-buffer space).
      if ($skipped > 0) {
         $Package->consumed += $skipped;
      }

      // ! Publish the response-phase pointer: the encoder and the response
      //   cycle read the worker-global cell — on every Complete, including
      //   Complete-with-waiting-body (the encoder reads `Body->waiting`
      //   from it to defer the response). Never on Rejected (no encoder
      //   runs) nor Incomplete.
      if ($state === States::Complete) {
         Server::$Request = $Request;
      }

      // @ Write to local cache
      // Skip caching when the read was not exactly one complete request
      // (a pipelined batch would store the whole batch as the template of
      // its first request and hits would then drop the tail), when Body is
      // waiting for more data (chunked/streaming), when the request decoded
      // as streaming multipart (the inline full-body path completes with
      // `streaming === true` and its uploads live only on THIS instance —
      // a template clone scrubs `_files`, so adopting it would silently
      // drop the repeat's upload) or when this read installed a
      // per-connection decoder (e.g. the HTTP/2 preface switch) —
      // protocol-switch bytes must never be served as an HTTP/1.1 template.
      if ($state === States::Complete
         && $Package->Decoder === null
         && $length > 0
         && $length === $size
         && ! $Request->Body->waiting
         && ! $Request->Body->streaming
      ) {
         if ($cacheKey !== null) {
            // ! Intentional bare read — the URL property hook derives and
            //   memoizes the private `_URL` as its side effect, so the clone
            //   below carries a warmed routing target: every future L1
            //   hit's Router read then fast-returns instead of re-running the
            //   URL derivation per request. Same bytes, same base — same URL.
            $Request->URL; // @phpstan-ignore expr.resultUnused (hook side effect: memoizes the private _URL)
            $Clone = clone $Request;
            $inputs[$cacheKey] = $Clone;
            // @ Share the clone with the connection's L0 (`known` already
            //   holds these bytes): the next identical repeat adopts it
            //   without touching the shared cache.
            $Package->Template = $Clone;

            if (count($inputs) > 512) {
               // @ Cache is deliberately bounded (max 513 before eviction), so this
               //   eviction path is stable in practice. If capacity grows materially,
               //   replace this with a dedicated hash + linked-list LRU.
               $oldestKey = array_key_first($inputs);
               unset($inputs[$oldestKey]);
            }
         }
         else if ($repeat) {
            // @ L0-only store (query-bearing or reassembled keys the shared
            //   cache refuses): SECOND consecutive sighting of these bytes —
            //   first sightings never pay the clone, so unique-target churn
            //   costs no allocation. `$repeat` implies `known === $buffer`
            //   (bounded at store time by the churn write above).
            $Request->URL; // @phpstan-ignore expr.resultUnused (hook side effect: memoizes the private _URL)
            $Package->Template = clone $Request;
         }
      }

      return $state;
   }
}
