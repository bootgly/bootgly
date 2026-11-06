<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating;


use function is_callable;
use function is_object;
use function method_exists;
use function preg_match;
use function property_exists;
use function str_replace;
use InvalidArgumentException;


/**
 * Shared helpers for HTTP authentication failure responses.
 */
class Challenge
{
   /**
    * Fallback bodies of the challenge statuses.
    *
    * @var array<int,string>
    */
   private const array REASONS = [
      401 => 'Unauthorized',
      403 => 'Forbidden',
   ];


   /**
    * Mark a response-like object with the challenge status when possible.
    *
    * @param 401|403 $code Challenge status (`401 Unauthorized` by default); `403` marks an
    *        `insufficient_scope` challenge (RFC 6750 §3.1).
    *
    * @throws InvalidArgumentException When `$code` is neither `401` nor `403`.
    */
   public static function mark (object $Response, int $code = 401): object
   {
      // ?
      self::check($code);

      if (method_exists($Response, 'code')) {
         $Result = $Response->code($code);
         return is_object($Result) ? $Result : $Response;
      }

      if (property_exists($Response, 'code')) {
         $Response->code = $code;
      }

      return $Response;
   }

   /**
    * Produce a generic unauthorized (or forbidden) response fallback.
    *
    * @param 401|403 $code Challenge status.
    *
    * @throws InvalidArgumentException When `$code` is neither `401` nor `403`.
    */
   public static function reject (object $Response, int $code = 401): object
   {
      // ?
      self::check($code);

      if (is_callable($Response)) {
         $Result = $Response($code, [], self::REASONS[$code]);
         return is_object($Result) ? $Result : $Response;
      }

      return self::mark($Response, $code);
   }

   /**
    * Write a `WWW-Authenticate` challenge on a response-like object.
    *
    * @param 401|403 $code Challenge status: `401` for a missing or invalid
    *        credential, `403` for a valid one that lacks the required scope.
    *
    * @throws InvalidArgumentException When `$code` is neither `401` nor `403`.
    */
   public static function announce (object $Response, string $challenge, int $code = 401): object
   {
      self::mark($Response, $code);

      $Header = $Response->Header ?? null;
      if (is_object($Header) && method_exists($Header, 'set')) {
         $Header->set('WWW-Authenticate', $challenge);
         return $Response;
      }

      if (is_callable($Response)) {
         $Result = $Response($code, ['WWW-Authenticate' => $challenge], self::REASONS[$code]);

         if (is_object($Result)) {
            return $Result;
         }
      }

      return self::reject($Response, $code);
   }

   /**
    * Quote a challenge parameter value as an RFC 9110 `quoted-string`.
    *
    * Backslashes and double quotes are escaped with a backslash (RFC 9110
    * §5.6.4). Control characters — CR, LF, NUL, HTAB and every other C0 byte,
    * plus DEL — are refused instead of escaped: no `quoted-pair` can carry
    * CR/LF/NUL, and a challenge must never split or truncate its header line.
    *
    * @throws InvalidArgumentException When the value holds a control character.
    */
   public static function quote (string $value): string
   {
      // ?
      if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
         throw new InvalidArgumentException(
            'Authentication challenge values must not contain control characters.'
         );
      }

      // ! RFC 9110 quoted-pair: escape the backslash first, then the quote.
      $value = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);

      // :
      return "\"{$value}\"";
   }

   /**
    * Refuse a challenge status other than `401` or `403`.
    *
    * @throws InvalidArgumentException When `$code` is neither `401` nor `403`.
    */
   private static function check (int $code): void
   {
      // ?
      if (isSet(self::REASONS[$code]) === false) {
         throw new InvalidArgumentException(
            "Authentication challenge status must be 401 or 403, {$code} given."
         );
      }
   }
}
