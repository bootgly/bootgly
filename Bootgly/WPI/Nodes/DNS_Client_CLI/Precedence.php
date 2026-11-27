<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\DNS_Client_CLI;


use const STREAM_CLIENT_CONNECT;
use function fclose;
use function is_string;
use function str_contains;
use function str_starts_with;
use function stream_socket_client;
use function stream_socket_get_name;
use function strrpos;
use function strtolower;
use function substr;
use function trim;
use function usort;
use Closure;
use Throwable;

use Bootgly\ABI\IO\Resolver;


/**
 * Destination address order — the subset of RFC 6724 a dialer needs.
 *
 * Rule 1: a destination whose family has no usable source address goes last
 * (loopback destinations are always usable). Rule 6: IPv6 (precedence 40,
 * `::1` 50) before IPv4 (35). Otherwise the answer order is kept.
 *
 * A family's source is probed with a connected UDP socket toward a
 * documentation address (`192.0.2.1`, `2001:db8::1`; no packet is sent) and
 * is usable when it has global scope. Each probe result is kept 60 seconds.
 */
final class Precedence
{
   // * Config
   /**
    * The probe: `Closure(int $family): null|string`, or `null` for the default.
    */
   public private(set) null|Closure $Probe;

   // * Data
   // ...

   // * Metadata
   /** @var array<int,array{usable: bool, expires: float}> */
   private array $Sources = [];


   public function __construct (null|Closure $Probe = null)
   {
      // * Config
      $this->Probe = $Probe;
   }

   /**
    * Order destination addresses for dialing.
    *
    * @param list<string> $addresses Canonical IP addresses, in answer order.
    * @param float $now The current time, on the caller's clock (the node's is monotonic: `Query::clock()`).
    *
    * @return list<string>
    */
   public function order (array $addresses, float $now): array
   {
      // ! Rank each address, keeping the answer order inside a rank
      $ranked = [];
      foreach ($addresses as $index => $address) {
         $IPv6 = str_contains($address, ':');
         $loopback = $address === '::1' || str_starts_with($address, '127.');
         $usable = $loopback || $this->probe($IPv6 ? 6 : 4, $now);

         // Rule 1 (usable first), then Rule 6 (IPv6 before IPv4)
         $rank = ($usable ? 0 : 2) + ($IPv6 ? 0 : 1);
         $ranked[] = [$rank, $index, $address];
      }

      usort($ranked, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

      // :
      $ordered = [];
      foreach ($ranked as [, , $address]) {
         $ordered[] = $address;
      }

      return $ordered;
   }

   /**
    * Whether a family has a usable (global-scope) source address — cached 60 seconds.
    */
   private function probe (int $family, float $now): bool
   {
      $Source = $this->Sources[$family] ?? null;
      if ($Source !== null && $Source['expires'] > $now) {
         return $Source['usable'];
      }

      // @ Ask the probe for the source address
      $Probe = $this->Probe ?? static function (int $family): null|string {
         $destination = $family === 6 ? '[2001:db8::1]:53' : '192.0.2.1:53';
         try {
            $Socket = @stream_socket_client("udp://{$destination}", $code, $message, 0, STREAM_CLIENT_CONNECT);
            if ($Socket === false) {
               return null;
            }
            $name = @stream_socket_get_name($Socket, false);
            fclose($Socket);
         }
         catch (Throwable) {
            return null;
         }
         if (is_string($name) === false) {
            return null;
         }

         // ! `address:port`, an IPv6 one possibly bracketed
         $colon = strrpos($name, ':');

         return $colon === false ? null : trim(substr($name, 0, $colon), '[]');
      };
      $source = $Probe($family);

      // ! Usable: a global-scope source of that family
      $usable = false;
      if (is_string($source)) {
         $address = Resolver::normalize($source);
         if ($address !== null) {
            $lower = strtolower($address);
            $usable = ($family === 6) === str_contains($address, ':')
               && $address !== '::1'
               && str_starts_with($address, '127.') === false
               && str_starts_with($lower, 'fe80:') === false
               && str_starts_with($address, '169.254.') === false
               && $address !== '0.0.0.0'
               && $address !== '::';
         }
      }

      $this->Sources[$family] = ['usable' => $usable, 'expires' => $now + 60.0];

      // :
      return $usable;
   }
}
