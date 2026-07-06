<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares;


use const FILTER_VALIDATE_IP;
use function count;
use function explode;
use function filter_var;
use function in_array;
use function strtolower;
use function trim;
use Closure;

use Bootgly\ACI\Logs\Logger;
use Bootgly\API\Workables\Server\Middleware;


class TrustedProxy implements Middleware
{
   public Logger $Logger {
      get {
         if ( isSet($this->Logger) === false ) {
            $this->Logger = new Logger(channel: static::class);
         }

         return $this->Logger;
      }
   }


   // * Config
   /** @var array<string> */
   public private(set) array $proxies;

   // * Data
   // ...

   // * Metadata
   //   True when the constructor fell back to the localhost default trust list
   //   (the caller passed no explicit `$proxies`); drives a one-time hardening
   //   warning when such a default actually trusts a peer (audit F-3).
   protected bool $localhostDefault;


   /**
    * @param null|array<string> $proxies Trusted proxy IP addresses. When null,
    *   falls back to the localhost default (`127.0.0.1`, `::1`) and logs a
    *   one-time warning the first time it trusts a forwarded header — set an
    *   explicit list in production.
    */
   public function __construct (
      null|array $proxies = null
   )
   {
      // * Config
      if ($proxies === null) {
         $this->proxies = ['127.0.0.1', '::1'];
         $this->localhostDefault = true;
      }
      else {
         $this->proxies = $proxies;
         $this->localhostDefault = false;
      }
   }

   public function process (object $Request, object $Response, Closure $next): object
   {
      // ? Only resolve if request comes from trusted proxy.
      $clientIp = $Request->address; // @phpstan-ignore-line
      if (in_array($clientIp, $this->proxies, true) === false) {
         return $next($Request, $Response);
      }

      // ! One-time hardening warning (audit F-3): a peer is being trusted under
      //   the DEFAULT localhost list. In production the trust list MUST be set
      //   explicitly — otherwise anything able to reach the server from
      //   127.0.0.1/::1 (sidecar, SSRF pivot, dev port-forward) can spoof
      //   `$Request->address` via X-Forwarded-For.
      if ($this->localhostDefault) {
         static $warned = false;
         if ($warned === false) {
            $warned = true;
            $this->Logger->log(
               warning: 'TrustedProxy: trusting forwarded headers using the DEFAULT '
               . 'localhost proxy list (127.0.0.1, ::1). Set an explicit '
               . '`proxies` list in production to prevent X-Forwarded-For '
               . 'spoofing from co-located/localhost clients.'
            );
         }
      }

      // @ Try X-Forwarded-For first
      $forwarded = $Request->Header->get('X-Forwarded-For'); // @phpstan-ignore-line
      if ($forwarded !== null) {
         // ? RFC 7239 §5.2 — walk the chain from the right, skipping trusted
         //   hops; the first untrusted entry is the real client. Returning
         //   the left-most entry (old `$ips[0]`) trusts whatever an attacker
         //   wrote and is spoofable whenever there are ≥ 2 trusted hops.
         $ips = explode(',', $forwarded);
         for ($i = count($ips) - 1; $i >= 0; $i--) {
            $candidate = trim($ips[$i]);
            if ($candidate === '') {
               continue;
            }
            if (in_array($candidate, $this->proxies, true)) {
               continue;
            }
            // ! Validate extracted IP before trusting it
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
               $Request->address = $candidate; // @phpstan-ignore-line
            }
            break;
         }
      }
      else {
         // @ Try X-Real-IP
         $realIp = $Request->Header->get('X-Real-IP'); // @phpstan-ignore-line
         if ($realIp !== null) {
            $candidate = trim($realIp);
            // ! Validate extracted IP before trusting it
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
               $Request->address = $candidate; // @phpstan-ignore-line
            }
         }
      }

      // @ Try X-Forwarded-Proto
      $proto = $Request->Header->get('X-Forwarded-Proto'); // @phpstan-ignore-line
      if ($proto !== null) {
         $candidate = strtolower(trim($proto));
         // ! Only accept valid HTTP schemes
         if (in_array($candidate, ['http', 'https'], true)) {
            $Request->scheme = $candidate; // @phpstan-ignore-line
         }
      }

      // :
      return $next($Request, $Response);
   }
}
