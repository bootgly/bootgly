<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders;


use function array_key_first;
use function count;
use function strpos;

use const Bootgly\WPI;
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Endpoints\Servers\Packages;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCP_Packages;
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

      $cacheable = ($size <= 2048);
      $cacheKey = null;
      if ($cacheable) {
         // @ Do not cache query-bearing targets. They are attacker-mutable and
         //   create one-shot key churn that evicts hot entries with no hit-rate
         //   benefit (DoS amplification vector).
         $requestLineEnd = strpos($buffer, "\r\n");
         if ($requestLineEnd !== false) {
            $queryMark = strpos($buffer, '?');
            if ($queryMark !== false && $queryMark < $requestLineEnd) {
               $cacheable = false;
            }
         }

         $cacheKey = (!$cacheable) ? null : $buffer;
      }

      // ? Check local cache and return
      if ($cacheKey !== null && isSet($inputs[$cacheKey])) {
         $cached = $inputs[$cacheKey];
         // @ LRU touch on hit (move to tail).
         unset($inputs[$cacheKey]);
         $inputs[$cacheKey] = $cached;

         // ! Security: clone on READ, not only on write. Otherwise handler /
         //   middleware mutations (dynamic properties, Header writes, auth
         //   decisions) persist on the cached Request and leak to every
         //   future connection that sends byte-identical headers.
         //   See tests/Security/2.01-decoder_cache_shared_request_across_connections.test.php
         Server::$Request = clone $cached;

         if ($Package->changed) {
            Server::$Request->reboot();

            Server::$Request->address = $Package->Connection->ip;
            Server::$Request->port = $Package->Connection->port;
            Server::$Request->scheme = $Package->Connection->encrypted ? 'https' : 'http';
         }

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

      // @ Write to local cache
      // Skip caching when Body is waiting for more data (chunked/streaming)
      if ($state === States::Complete
         && $cacheKey !== null
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
