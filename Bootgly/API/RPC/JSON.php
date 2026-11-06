<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\RPC;


use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use function array_is_list;
use function array_key_exists;
use function count;
use function implode;
use function is_array;
use function is_finite;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function strspn;
use Closure;
use InvalidArgumentException;
use JsonException;
use Throwable;

use Bootgly\API\RPC\JSON\Fault;


/**
 * JSON-RPC 2.0 dispatcher, transport-independent: single requests, batches and
 * notifications, with the standard error codes.
 *
 * Methods are closures `fn (array $params, mixed $context): mixed` registered
 * by name. Params arrive decoded as PHP arrays (a JSON object and a JSON array
 * both become an array); return a `stdClass` where the client must receive
 * `{}`. A method raises `JSON\Fault` for a designed error; anything else it
 * throws becomes `-32603 Internal error`, without its message, and is handed
 * to the Reporter.
 *
 * `handle()` takes the raw payload and returns the JSON to send, or null when
 * nothing is owed (only notifications came in) — an HTTP transport answers
 * that with 202, stdio writes nothing. The dispatcher keeps no per-request
 * state: whatever belongs to one call — the client's session, its identity —
 * travels as that call's `$context`, handed to every method and to the
 * Reporter, so one instance may serve concurrent requests (Fibers included).
 *
 * Ids come back as PHP decoded them: an integer past `PHP_INT_MAX` or a
 * fractional id is a float and may not round-trip digit for digit (the
 * specification advises against fractional ids).
 */
final class JSON
{
   /** Invalid JSON was received. */
   public const int PARSE_ERROR = -32700;
   /** The JSON sent is not a valid request object (or the batch is refused). */
   public const int INVALID_REQUEST = -32600;
   /** The method does not exist. */
   public const int METHOD_NOT_FOUND = -32601;
   /** Invalid method parameters. */
   public const int INVALID_PARAMS = -32602;
   /** Internal JSON-RPC error: a method failure the client does not see. */
   public const int INTERNAL_ERROR = -32603;

   // * Config
   /**
    * The most requests one batch may carry; a larger batch is refused as a
    * whole with a single Invalid Request. `0` refuses every batch.
    */
   public private(set) int $batch;

   // * Data
   /** @var array<string,Closure> name => fn (array $params, mixed $context): mixed */
   private array $Methods;
   /** fn (Throwable $Throwable, array $request, mixed $context): void — internal failures, for the caller's logs. */
   private null|Closure $Reporter;


   /**
    * @param array<string,Closure> $Methods The methods, by name:
    *        `fn (array $params, mixed $context): mixed`.
    * @param null|Closure $Reporter Called with every internal failure — a
    *        method's unexpected `Throwable`, or a result that cannot be encoded
    *        — the request object it came from and the call's context:
    *        `fn (Throwable $Throwable, array $request, mixed $context): void`.
    *        It must not throw: an exception it raises is dropped, so the
    *        response still goes out.
    * @param int $batch The most requests one batch may carry (`0`: no batches).
    *
    * @throws InvalidArgumentException When `$batch` is negative.
    */
   public function __construct (array $Methods, null|Closure $Reporter = null, int $batch = 100)
   {
      // ?
      if ($batch < 0) {
         throw new InvalidArgumentException("The batch limit cannot be negative ({$batch}).");
      }

      // * Config
      $this->batch = $batch;

      // * Data
      $this->Methods = $Methods;
      $this->Reporter = $Reporter;
   }

   /**
    * Answer a raw JSON-RPC payload: one request object or a batch of them.
    *
    * A payload is a batch only when its first non-whitespace character is
    * `[` — a JSON object is one request, whatever its keys.
    *
    * @param string $payload The JSON text received.
    * @param mixed $context What belongs to this call (the client's session, its
    *        identity…), handed to every method and to the Reporter.
    *
    * @return null|string The JSON response — an object, or an array for a
    *         batch — or null when only notifications came in.
    */
   public function handle (string $payload, mixed $context = null): null|string
   {
      // !
      try {
         $decoded = json_decode($payload, associative: true, flags: JSON_THROW_ON_ERROR);
      }
      catch (JsonException) {
         return $this->encode(self::fail(null, self::PARSE_ERROR, 'Parse error'), [], $context);
      }

      // ? One request object — a JSON object with keys "0", "1"… included
      if (self::detect($payload) === false || is_array($decoded) === false || $decoded === []) {
         $response = $this->execute($decoded, $context);

         // :
         return $response === null ? null : $this->encode($response, $decoded, $context);
      }

      // ? A batch past the limit is refused whole, before any method runs
      if (count($decoded) > $this->batch) {
         return $this->encode(self::fail(null, self::INVALID_REQUEST, 'Invalid Request'), [], $context);
      }

      // @@ Batch
      $responses = [];
      foreach ($decoded as $request) {
         $response = $this->execute($request, $context);

         if ($response !== null) {
            $responses[] = $this->encode($response, $request, $context);
         }
      }

      // ?: Only notifications
      if ($responses === []) {
         return null;
      }

      $list = implode(',', $responses);

      // :
      return "[{$list}]";
   }

   /**
    * Run one request object — already decoded, as `json_decode()` with
    * `associative: true` returns it.
    *
    * @param mixed $request The request object.
    * @param mixed $context What belongs to this call, handed to the method and
    *        to the Reporter.
    *
    * @return null|array<string,mixed> The response object, or null for a
    *         notification.
    */
   public function execute (mixed $request, mixed $context = null): null|array
   {
      // ? Shape: an object with `"jsonrpc": "2.0"` and a string method
      if (
         is_array($request) === false
         || $request === []
         || array_is_list($request) === true
         || ($request['jsonrpc'] ?? null) !== '2.0'
         || is_string($request['method'] ?? null) === false
      ) {
         $id = is_array($request) ? ($request['id'] ?? null) : null;

         return self::fail(self::check($id) ? $id : null, self::INVALID_REQUEST, 'Invalid Request');
      }

      // !
      $notification = array_key_exists('id', $request) === false;
      $id = $request['id'] ?? null;

      // ? An id is a string, a number or null
      if ($notification === false && self::check($id) === false) {
         return self::fail(null, self::INVALID_REQUEST, 'Invalid Request');
      }

      // ! Params may be omitted; when present, even as null, they are structured
      $params = array_key_exists('params', $request) ? $request['params'] : [];

      // ? An object or an array
      if (is_array($params) === false) {
         return $notification ? null : self::fail($id, self::INVALID_PARAMS, 'Invalid params');
      }

      $Method = $this->Methods[$request['method']] ?? null;

      // ?
      if ($Method === null) {
         return $notification ? null : self::fail($id, self::METHOD_NOT_FOUND, 'Method not found');
      }

      // @
      try {
         $result = $Method($params, $context);
      }
      catch (Fault $Fault) {
         return $notification ? null : self::fail($id, $Fault->getCode(), $Fault->getMessage(), $Fault->data);
      }
      catch (Throwable $Throwable) {
         $this->report($Throwable, $request, $context);

         return $notification ? null : self::fail($id, self::INTERNAL_ERROR, 'Internal error');
      }

      // :
      return $notification ? null : [
         'jsonrpc' => '2.0',
         'id' => $id,
         'result' => $result
      ];
   }

   /**
    * Whether a value is a valid request id: a string, a number or null — a
    * finite number, since a number past the float range (`1e999`) decodes to
    * `INF`, which cannot be sent back.
    */
   private static function check (mixed $id): bool
   {
      return $id === null || is_string($id) || is_int($id) || (is_float($id) && is_finite($id));
   }

   /**
    * Whether a payload is a batch: its first non-whitespace character (space,
    * tab, line feed, carriage return) is `[`. A JSON object is one request,
    * whatever its keys.
    *
    * @param string $payload The JSON text received.
    *
    * @return bool
    */
   public static function detect (string $payload): bool
   {
      // !
      $start = strspn($payload, " \t\n\r");

      // :
      return ($payload[$start] ?? '') === '[';
   }

   /**
    * Build an error response object.
    *
    * @return array<string,mixed>
    */
   private static function fail (mixed $id, int $code, string $message, mixed $data = null): array
   {
      $error = ['code' => $code, 'message' => $message];
      if ($data !== null) {
         $error['data'] = $data;
      }

      return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error];
   }

   /**
    * Encode one response object; a response that cannot be encoded — invalid
    * UTF-8, a resource, a recursion, or a `jsonSerialize()` or property hook
    * that throws — is reported and replaced by an Internal error for the same
    * id, so nothing of the failure reaches the client.
    *
    * @param array<string,mixed> $response
    */
   private function encode (array $response, mixed $request, mixed $context): string
   {
      try {
         return json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
      }
      catch (Throwable $Throwable) {
         $this->report($Throwable, $request, $context);

         return (string) json_encode(
            self::fail($response['id'] ?? null, self::INTERNAL_ERROR, 'Internal error'),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
         );
      }
   }

   /**
    * Hand an internal failure to the Reporter, when there is one; an exception
    * the Reporter itself raises is dropped, so the response still goes out.
    */
   private function report (Throwable $Throwable, mixed $request, mixed $context): void
   {
      // ?
      if ($this->Reporter === null) {
         return;
      }

      // @
      try {
         ($this->Reporter)($Throwable, is_array($request) ? $request : [], $context);
      }
      catch (Throwable) {
         // ! Dropped: the response still goes out
      }
   }
}
