<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources;


use function is_array;
use function str_ends_with;
use function str_starts_with;
use function substr;

use const Bootgly\WPI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resource;


/**
 * Built-in content negotiation: one payload, `Accept`-driven representation.
 *
 * Reads the client's q-sorted media types (`Request::negotiate()` via
 * `WPI->Request->types`) and matches them against the offered
 * representations — `application/json` and `application/xml` always, plus
 * `text/html` when a `view` is supplied. First client preference that an
 * offer satisfies wins (wildcards `*` / `*\/*` / `type/*` included). No
 * `Accept` header defaults to JSON; a present-but-unsatisfiable `Accept`
 * yields 406 Not Acceptable.
 */
class Negotiation extends Resource
{
   // * Config
   // ...

   // * Data
   protected Response $Response;

   // * Metadata
   // ...


   public function __construct (Response $Response)
   {
      parent::__construct(persistent: true);

      // * Data
      $this->Response = $Response;
   }

   /**
    * Send one payload in the representation the client prefers.
    *
    * @param mixed $payload The single payload rendered as JSON/XML/HTML.
    * @param null|string $view The view name used only when HTML is selected.
    */
   public function send (mixed $payload = null, null|string $view = null): Response
   {
      $Response = $this->Response;

      // ! The representation varies by Accept — shared caches must store each
      //   one separately (RFC 9110 §12.5.5); emitted on every branch, 406 too.
      $Response->Header->vary('Accept');

      // ! Offers — HTML only when a view is available to render it
      $offers = ['application/json', 'application/xml'];
      if ($view !== null) {
         $offers[] = 'text/html';
      }

      // @ Match the client's q-sorted preferences against the offers
      $accepted = WPI->Request->types;
      $chosen = self::choose($accepted, $offers);

      // ? Nothing matched
      if ($chosen === null) {
         // : No Accept header at all → serve the default representation.
         //   Presence is decided by the header, not the parsed list — an
         //   Accept refusing everything (q=0) must 406, not fall back.
         if ((string) WPI->Request->Header->get('Accept') === '') {
            return $Response->JSON->send($payload);
         }

         // : Accept present but unsatisfiable → 406 Not Acceptable
         return $Response->code(406)->send('');
      }

      // @ Dispatch to the selected representation
      return match ($chosen) {
         'application/xml' => $Response->XML->send($payload),
         'text/html' => $Response->View->render((string) $view, is_array($payload) ? $payload : [])->send(),
         default => $Response->JSON->send($payload),
      };
   }

   /**
    * Choose the first offered media type that satisfies the client's
    * preferences, honoring `*`, `*\/*` and `type/*` wildcards.
    *
    * @param array<string> $accepted Client media types, most preferred first.
    * @param array<string> $offers Server representations, most preferred first.
    */
   public static function choose (array $accepted, array $offers): null|string
   {
      foreach ($accepted as $type) {
         // # Full wildcard → the server's first offer
         if ($type === '*/*' || $type === '*') {
            return $offers[0] ?? null;
         }

         // # Type wildcard (e.g. application/*) → first offer of that type
         if (str_ends_with($type, '/*')) {
            $prefix = substr($type, 0, -1);

            foreach ($offers as $offer) {
               if (str_starts_with($offer, $prefix)) {
                  return $offer;
               }
            }

            continue;
         }

         // # Exact media type
         foreach ($offers as $offer) {
            if ($offer === $type) {
               return $offer;
            }
         }
      }

      // :
      return null;
   }
}
