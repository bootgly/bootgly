<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares;


use const JSON_UNESCAPED_SLASHES;
use function array_key_exists;
use function is_array;
use function json_encode;
use function strtolower;
use Closure;

use Bootgly\ADI\Validation;
use Bootgly\ADI\Validation\Condition;
use Bootgly\ADI\Validators\Confirmed;
use Bootgly\API\Workables\Server\Middleware;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Validator\Sources;


class Validator implements Middleware
{
   // * Config
   /**
    * @var array<string,Condition|array<int,Condition>>
    */
   public private(set) array $rules;
   public private(set) Sources $Source;
   public private(set) int $code;
   /**
    * @var null|Closure(Request,Response,Validation):object
    */
   public private(set) null|Closure $fallback;

   // * Data
   // ...

   // * Metadata
   // ...


   /**
    * @param array<string,Condition|array<int,Condition>> $rules
    * @param null|Closure(Request,Response,Validation):object $fallback
    */
   public function __construct (
      array $rules,
      Sources $Source,
      int $code = 422,
      null|Closure $fallback = null,
   )
   {
      // * Config
      $this->rules = $rules;
      $this->Source = $Source;
      $this->code = $code;
      $this->fallback = $fallback;
   }

   /**
    * @param Request $Request
    * @param Response $Response
    */
   public function process (object $Request, object $Response, Closure $next): object
   {
      // @ Validate before the route handler.
      $source = match ($this->Source) {
         Sources::Cookies => $this->flatten($Request->cookies),
         Sources::Fields => $Request->fields,
         Sources::Files => $Request->files,
         Sources::Headers => $this->fold($Request->headers),
         Sources::Queries => $Request->queries,
      };

      $Validation = new Validation($source, $this->rules);

      // ? Valid request — continue pipeline.
      if ($Validation->valid) {
         return $next($Request, $Response);
      }

      // ?: Custom fallback response.
      if ($this->fallback !== null) {
         return ($this->fallback)($Request, $Response, $Validation);
      }

      // @ Fail closed with deterministic JSON errors.
      $body = json_encode(
         value: ['errors' => $Validation->errors],
         flags: JSON_UNESCAPED_SLASHES
      );

      if ($body === false) {
         $body = '{"errors":[]}';
      }

      // :
      return $Response(
         code: $this->code,
         headers: ['Content-Type' => 'application/json'],
         body: $body
      );
   }

   /**
    * Flatten the per-line cookie maps into one name→value source.
    *
    * `Cookies::build()` parses each `Cookie` header line into its own
    * name→value map and appends it, so `$Request->cookies` is
    * `array<int, array<string,string>>` — a shape `Validation`'s exact
    * `array_key_exists` can never bind by cookie name. Unioning the lines
    * first-line-wins mirrors `Cookies::get()`'s scan order, keeps cookie
    * names case-sensitive (RFC 6265) and never invents an absent name,
    * so `Required` still fails missing cookies (MW-8).
    *
    * @param array<int,array<string,string>> $cookies The per-line cookie maps.
    *
    * @return array<string,string> One name→value map, first line wins.
    */
   private function flatten (array $cookies): array
   {
      // @ Union — the `+` operator keeps existing names, so earlier
      //   lines win, exactly like Cookies::get()'s list scan
      $flat = [];
      foreach ($cookies as $line) {
         $flat += $line;
      }

      // :
      return $flat;
   }

   /**
    * Case-fold canonically-cased rule keys into the header source.
    *
    * Header field names are case-insensitive (RFC 9110 §5.1) and the
    * request parser lowercases them at frame time, while rule keys keep
    * the user's casing — `Header::get()` folds on read, but `Validation`
    * resolves fields with an exact `array_key_exists`. Binding the rule
    * keys (and `Confirmed` companion fields) to their lowercased wire
    * fields keeps `errors` keyed by the user's original casing without
    * touching the case-sensitive sources (MW-7).
    *
    * @param array<string,mixed> $headers The lowercased wire field map.
    *
    * @return array<string,mixed> The map unioned with canonical rule keys.
    */
   private function fold (array $headers): array
   {
      foreach ($this->rules as $field => $conditions) {
         $field = (string) $field;
         /** @var mixed $raw */
         $raw = $conditions;

         if ($raw instanceof Condition) {
            $raw = [$raw];
         }

         // ? Malformed rule shapes are Validation's to diagnose
         if (is_array($raw) === false) {
            continue;
         }

         // ! Keys the rules will resolve in the source
         $keys = [$field];
         foreach ($raw as $Condition) {
            if ($Condition instanceof Confirmed) {
               $keys[] = $Condition->field ?? "{$field}_confirmation";
            }
         }

         // @ Union — each canonical key binds to its lowercased wire
         //   field; never clobber an existing key, never invent one
         foreach ($keys as $key) {
            $lower = strtolower($key);
            if ($lower === $key || array_key_exists($key, $headers)) {
               continue;
            }
            if (array_key_exists($lower, $headers)) {
               $headers[$key] = $headers[$lower];
            }
         }
      }

      // :
      return $headers;
   }
}
