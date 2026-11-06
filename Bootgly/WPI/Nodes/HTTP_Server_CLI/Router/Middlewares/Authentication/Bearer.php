<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication;


use function implode;
use function preg_match;
use Closure;
use InvalidArgumentException;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating\Challenge;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating\Guard;


/**
 * Opaque HTTP Bearer token authentication guard.
 *
 * The guard extracts a Bearer token from the request and lets application code
 * resolve it. It does not inspect token structure, making it suitable for API
 * keys, database-backed access tokens, and other opaque credential schemes.
 */
class Bearer extends Guard
{
   /**
    * RFC 6750 §3 `error` / `error_description` characters:
    * `%x20-21 / %x23-5B / %x5D-7E` (printable ASCII without `"` and `\`).
    */
   private const string TEXT = '/^[\x20\x21\x23-\x5B\x5D-\x7E]+$/D';
   /**
    * RFC 6750 §3 `%x21 / %x23-5B / %x5D-7E` (the `error` set without space):
    * one `scope-token`, and the characters an `error_uri` URI-reference may hold.
    */
   private const string TOKEN = '/^[\x21\x23-\x5B\x5D-\x7E]+$/D';
   /**
    * RFC 6750 §3 `scope`: `scope-token *( SP scope-token )`.
    */
   private const string SCOPE = '/^[\x21\x23-\x5B\x5D-\x7E]+(?: [\x21\x23-\x5B\x5D-\x7E]+)*$/D';

   // * Config
   /**
    * Application callback that resolves the opaque token.
    *
    * The callback receives `(string $token, Request $Request)` and should
    * return `false`/`null` for denial, `true` to expose the token, or a custom
    * identity value to expose to handlers.
    */
   public private(set) Closure $Resolver;
   /**
    * RFC 9728 protected-resource-metadata URL advertised by the challenges.
    *
    * Sent as `resource_metadata="…"` after the other challenge parameters, so
    * clients (e.g. MCP clients) can discover the authorization server of this
    * resource. Empty omits the parameter.
    */
   public private(set) string $metadata;

   // * Data
   /**
    * RFC 6750 error code emitted in failed Bearer challenges.
    */
   public private(set) string $error;
   /**
    * Human-readable Bearer challenge error description.
    */
   public private(set) string $description;
   /**
    * Bearer challenge documentation URI.
    */
   public private(set) string $URI;
   /**
    * Required authorization scope advertised by the challenge.
    */
   public private(set) string $scope;

   // * Metadata
   /**
    * `WWW-Authenticate` value of the `401` challenge, built (and validated)
    * once at configuration time.
    */
   protected string $challenge;


   /**
    * Configure opaque Bearer token resolution.
    *
    * Every challenge value (`realm`, `error`, `description`, `URI`, `scope`,
    * `metadata`) is written as an RFC 9110 quoted-string; a value holding a
    * control character is refused here, never at request time. The RFC 6750
    * §3 attributes are narrower: `error` and `description` (`error_description`)
    * hold only `%x20-21 / %x23-5B / %x5D-7E`, `URI` (`error_uri`) only
    * `%x21 / %x23-5B / %x5D-7E`, and `scope` is space-separated
    * `scope-token`s. `realm` and `metadata` (`resource_metadata`) keep the
    * general quoted-string rule. Empty values are omitted.
    *
    * @param Closure(string,Request):mixed $Resolver
    *
    * @throws InvalidArgumentException When a challenge value holds a control
    *         character, or an RFC 6750 attribute holds a character outside its set.
    */
   public function __construct (
      Closure $Resolver,
      string $realm = 'Protected area',
      string $error = 'invalid_token',
      string $description = '',
      string $URI = '',
      string $scope = '',
      string $metadata = ''
   )
   {
      // ? RFC 6750 §3 character sets (empty values are omitted, never checked).
      $rules = [
         'error' => [$error, self::TEXT, 'printable ASCII without `"` and `\`'],
         'description (error_description)' => [$description, self::TEXT, 'printable ASCII without `"` and `\`'],
         'URI (error_uri)' => [$URI, self::TOKEN, 'a URI-reference of printable ASCII without space, `"` and `\`'],
         'scope' => [$scope, self::SCOPE, 'scope-tokens (printable ASCII without space, `"` and `\`) separated by one space'],
      ];
      foreach ($rules as $parameter => [$value, $pattern, $set]) {
         if ($value !== '' && preg_match($pattern, $value) !== 1) {
            throw new InvalidArgumentException(
               "Bearer {$parameter} must hold only RFC 6750 characters: {$set}."
            );
         }
      }

      parent::__construct($realm);

      // * Config
      $this->Resolver = $Resolver;
      $this->metadata = $metadata;

      // * Data
      $this->error = $error;
      $this->description = $description;
      $this->URI = $URI;
      $this->scope = $scope;

      // * Metadata
      $this->challenge = $this->format('Bearer', [
         'realm' => $this->realm,
         'error' => $this->error,
         'error_description' => $this->description,
         'error_uri' => $this->URI,
         'scope' => $this->scope,
         'resource_metadata' => $this->metadata,
      ]);
   }

   /**
    * Authenticate a request with an opaque Bearer token.
    */
   public function authenticate (object $Request): bool
   {
      // ! Bearer token.
      $token = $this->extract($Request);
      if ($token === '') {
         return false;
      }

      // @ Resolve opaque token.
      $identity = ($this->Resolver)($token, $Request);
      if ($identity === null || $identity === false) {
         return false;
      }

      // @ Expose resolver result to handlers.
      $this->expose($Request, 'identity', $identity === true ? $token : $identity);

      return true;
   }

   /**
    * Challenge the client with `WWW-Authenticate: Bearer`.
    */
   public function challenge (object $Response): object
   {
      return $this->announce($Response, $this->challenge);
   }

   /**
    * Challenge an authenticated client that lacks the required scopes.
    *
    * Writes `403 Forbidden` plus `WWW-Authenticate: Bearer realm="…",
    * error="insufficient_scope", scope="…"` (RFC 6750 §3.1), followed by
    * `resource_metadata="…"` when configured — the step-up signal an MCP
    * client reads to request a token with more scopes.
    *
    * @param array<int,string> $scopes Scopes required by the resource,
    *        sent space-separated; each must be an RFC 6750 `scope-token`.
    *
    * @throws InvalidArgumentException When a scope is not an RFC 6750 `scope-token`.
    */
   public function forbid (object $Response, array $scopes): object
   {
      // ? Each required scope is one RFC 6750 §3 scope-token.
      foreach ($scopes as $scope) {
         if (preg_match(self::TOKEN, $scope) !== 1) {
            throw new InvalidArgumentException(
               'Bearer forbid() scopes must be RFC 6750 scope-tokens: printable ASCII without space, `"` and `\`.'
            );
         }
      }

      // !
      $challenge = $this->format('Bearer', [
         'realm' => $this->realm,
         'error' => 'insufficient_scope',
         'scope' => implode(' ', $scopes),
         'resource_metadata' => $this->metadata,
      ]);

      // :
      return Challenge::announce($Response, $challenge, 403);
   }
}