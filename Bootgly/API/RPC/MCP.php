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


use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function str_contains;
use Closure;
use stdClass;

use Bootgly\API\RPC\JSON\Fault;


/**
 * Model Context Protocol server core, transport-independent, over `RPC\JSON`:
 * `initialize` (revision negotiation, capabilities, server info),
 * `notifications/initialized`, `ping`, tools and — when the application gives
 * them — resources and prompts. Sessions, authentication and the transport
 * (Streamable HTTP, stdio) stay outside: the server keeps no per-client state.
 * Whatever belongs to one call — the client's session, its identity — travels
 * as that call's `$context` (`handle($payload, $context)`) and reaches every
 * application closure as its last argument, so one instance may serve every
 * client of a worker, Fibers included.
 *
 * The application's closures (`$context` last in each):
 *
 * - `Lister`: `fn (mixed $context): array` — the tools, each
 *   `['name' => …, 'description' => …, 'inputSchema' => …]`;
 * - `Caller`: `fn (string $name, array $arguments, mixed $context): mixed` —
 *   the tool result (`['content' => […], 'isError' => bool]`); a tool failure
 *   is a result with `isError: true`, never a protocol error;
 * - `Resources`: `fn (mixed $context): array` — the resources (`uri`, `name`, …);
 * - `Templates`: `fn (mixed $context): array` — the resource templates;
 * - `Reader`: `fn (string $URI, mixed $context): array` — the contents of a
 *   resource; raise `JSON\Fault(MCP::RESOURCE_NOT_FOUND, …)` for an unknown URI;
 * - `Prompts`: `fn (mixed $context): array` — the prompts (`name`, `arguments`, …);
 * - `Prompter`: `fn (string $name, array $arguments, mixed $context): mixed` —
 *   the `prompts/get` result (`['description' => …, 'messages' => […]]`);
 *   raise `JSON\Fault(JSON::INVALID_PARAMS, …)` for an unknown prompt;
 * - `Initializer`: `fn (string $revision, array $params, mixed $context): void`
 *   — told the revision agreed at `initialize`, so the transport stores it in
 *   that call's session.
 *
 * A method result of `[]` or `null` is sent as `{}`; any other value is sent
 * as it is. `initialize` inside a batch is refused: the whole batch answers
 * Invalid Request.
 */
final class MCP
{
   /**
    * Protocol revisions this server speaks, newest first. A client asking for
    * one of them gets it; any other request gets the newest.
    *
    * @var array<int,string>
    */
   public const array VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];
   /** MCP's error code for an unknown resource URI. */
   public const int RESOURCE_NOT_FOUND = -32002;

   // * Config
   /** The server name sent at `initialize` (`serverInfo.name`). */
   public private(set) string $name;
   /** The server version sent at `initialize` (`serverInfo.version`). */
   public private(set) string $version;
   /** Shown to the client's model at `initialize`: how to use these tools. */
   public private(set) null|string $instructions;

   // * Data
   private Closure $Caller;
   private null|Closure $Resources;
   private null|Closure $Reader;
   private null|Closure $Prompts;
   private null|Closure $Prompter;
   private null|Closure $Initializer;

   // * Metadata
   /**
    * The JSON-RPC dispatcher the protocol methods are registered on — for
    * introspection (its `batch` limit). Answer payloads through
    * `MCP::handle()`: it refuses what MCP forbids (`initialize` in a batch)
    * before they reach the dispatcher.
    */
   public private(set) JSON $JSON;


   /**
    * @param string $name The server name (`serverInfo.name`).
    * @param string $version The server version (`serverInfo.version`).
    * @param Closure $Lister `fn (mixed $context): array` — the tools.
    * @param Closure $Caller `fn (string $name, array $arguments, mixed $context): mixed` — call a tool.
    * @param null|string $instructions How the client's model should use the server.
    * @param null|Closure $Resources `fn (mixed $context): array` — the resources (with `Reader`).
    * @param null|Closure $Templates `fn (mixed $context): array` — the resource templates.
    * @param null|Closure $Reader `fn (string $URI, mixed $context): array` — read a resource (with `Resources`).
    * @param null|Closure $Prompts `fn (mixed $context): array` — the prompts (with `Prompter`).
    * @param null|Closure $Prompter `fn (string $name, array $arguments, mixed $context): mixed` — get a prompt.
    * @param null|Closure $Initializer `fn (string $revision, array $params, mixed $context): void` — told the agreed revision.
    * @param null|Closure $Reporter Internal failures, as `JSON` takes them.
    * @param int $batch The most requests one batch may carry, as `JSON` takes it.
    */
   public function __construct (
      string $name,
      string $version,
      Closure $Lister,
      Closure $Caller,
      null|string $instructions = null,
      null|Closure $Resources = null,
      null|Closure $Templates = null,
      null|Closure $Reader = null,
      null|Closure $Prompts = null,
      null|Closure $Prompter = null,
      null|Closure $Initializer = null,
      null|Closure $Reporter = null,
      int $batch = 100
   )
   {
      // * Config
      $this->name = $name;
      $this->version = $version;
      $this->instructions = $instructions;

      // * Data
      $this->Caller = $Caller;
      $this->Resources = $Resources;
      $this->Reader = $Reader;
      $this->Prompts = $Prompts;
      $this->Prompter = $Prompter;
      $this->Initializer = $Initializer;

      // * Metadata
      $Methods = [
         'initialize' => $this->initialize(...),
         'notifications/initialized' => static fn (array $params, mixed $context): null => null,
         'notifications/cancelled' => static fn (array $params, mixed $context): null => null,
         'ping' => static fn (array $params, mixed $context): null => null,
         'tools/list' => static fn (array $params, mixed $context): array => ['tools' => $Lister($context)],
         'tools/call' => $this->call(...)
      ];
      if ($Resources !== null && $Reader !== null) {
         $Methods['resources/list'] = static fn (array $params, mixed $context): array => [
            'resources' => $Resources($context)
         ];
         $Methods['resources/templates/list'] = static fn (array $params, mixed $context): array => [
            'resourceTemplates' => $Templates !== null ? $Templates($context) : []
         ];
         $Methods['resources/read'] = fn (array $params, mixed $context): array => $this->read($params, $context, $Reader);
      }
      if ($Prompts !== null && $Prompter !== null) {
         $Methods['prompts/list'] = static fn (array $params, mixed $context): array => [
            'prompts' => $Prompts($context)
         ];
         $Methods['prompts/get'] = fn (array $params, mixed $context): mixed => $this->prompt($params, $context, $Prompter);
      }
      // ! An empty result is an object on the wire
      foreach ($Methods as $method => $Method) {
         $Methods[$method] = static fn (array $params, mixed $context): mixed => self::shape($Method($params, $context));
      }
      $this->JSON = new JSON($Methods, $Reporter, $batch);
   }

   /**
    * Answer a raw MCP payload (JSON-RPC 2.0).
    *
    * @param string $payload The JSON text received.
    * @param mixed $context What belongs to this call (the client's session, its
    *        identity…), handed to every application closure and the Reporter.
    *
    * @return null|string The JSON response, or null when only notifications
    *         came in.
    */
   public function handle (string $payload, mixed $context = null): null|string
   {
      // ? `initialize` must not be part of a batch: the whole batch is refused
      if (self::screen($payload)) {
         return '{"jsonrpc":"2.0","id":null,"error":{"code":-32600,"message":"Invalid Request"}}';
      }

      // :
      return $this->JSON->handle($payload, $context);
   }

   /**
    * The revision to speak for a client's requested one: the same when this
    * server speaks it, the newest otherwise.
    *
    * @param mixed $requested The `protocolVersion` the client sent.
    *
    * @return string One of `MCP::VERSIONS`.
    */
   public static function negotiate (mixed $requested): string
   {
      return is_string($requested) && in_array($requested, self::VERSIONS, true)
         ? $requested
         : self::VERSIONS[0];
   }

   /**
    * Whether a payload is a batch carrying `initialize`.
    */
   private static function screen (string $payload): bool
   {
      // ? Only a batch that can name `initialize` — literally or through a
      //   `\u` escape — is decoded here; any other payload is decoded once
      if (
         JSON::detect($payload) === false
         || (str_contains($payload, 'initialize') === false && str_contains($payload, '\\u') === false)
      ) {
         return false;
      }

      // !
      $requests = json_decode($payload, associative: true);

      // ? Invalid JSON: the dispatcher answers the Parse error
      if (is_array($requests) === false) {
         return false;
      }

      // @@
      foreach ($requests as $request) {
         if (is_array($request) && ($request['method'] ?? null) === 'initialize') {
            return true;
         }
      }

      // :
      return false;
   }

   /**
    * An empty result (`[]` or `null`) becomes `{}`.
    */
   private static function shape (mixed $result): mixed
   {
      return $result === [] || $result === null ? new stdClass : $result;
   }

   /**
    * @param array<int|string,mixed> $params
    *
    * @return array<string,mixed>
    */
   private function initialize (array $params, mixed $context): array
   {
      // !
      $revision = self::negotiate($params['protocolVersion'] ?? null);

      // @ The transport stores the revision in this call's session
      if ($this->Initializer !== null) {
         ($this->Initializer)($revision, $params, $context);
      }

      $capabilities = ['tools' => ['listChanged' => false]];
      if ($this->Resources !== null && $this->Reader !== null) {
         $capabilities['resources'] = ['listChanged' => false, 'subscribe' => false];
      }
      if ($this->Prompts !== null && $this->Prompter !== null) {
         $capabilities['prompts'] = ['listChanged' => false];
      }

      $result = [
         'protocolVersion' => $revision,
         'capabilities' => $capabilities,
         'serverInfo' => ['name' => $this->name, 'version' => $this->version]
      ];
      if ($this->instructions !== null) {
         $result['instructions'] = $this->instructions;
      }

      // :
      return $result;
   }

   /**
    * @param array<int|string,mixed> $params
    * @param mixed $context This call's context.
    *
    * @return mixed
    *
    * @throws Fault
    */
   private function call (array $params, mixed $context): mixed
   {
      // !
      $name = $params['name'] ?? null;
      $arguments = $params['arguments'] ?? [];

      // ?
      if (is_string($name) === false || $name === '' || is_array($arguments) === false) {
         throw new Fault(JSON::INVALID_PARAMS, 'Invalid params: name (string) and arguments (object) are required');
      }

      // :
      return ($this->Caller)($name, $arguments, $context);
   }

   /**
    * @param array<int|string,mixed> $params
    * @param mixed $context This call's context.
    * @param Closure $Reader `fn (string $URI, mixed $context): array`.
    *
    * @return array<string,mixed>
    *
    * @throws Fault
    */
   private function read (array $params, mixed $context, Closure $Reader): array
   {
      // !
      $URI = $params['uri'] ?? null;

      // ?
      if (is_string($URI) === false || $URI === '') {
         throw new Fault(JSON::INVALID_PARAMS, 'Invalid params: uri (string) is required');
      }

      // :
      return ['contents' => $Reader($URI, $context)];
   }

   /**
    * @param array<int|string,mixed> $params
    * @param mixed $context This call's context.
    * @param Closure $Prompter `fn (string $name, array $arguments, mixed $context): mixed`.
    *
    * @return mixed
    *
    * @throws Fault
    */
   private function prompt (array $params, mixed $context, Closure $Prompter): mixed
   {
      // !
      $name = $params['name'] ?? null;
      $arguments = $params['arguments'] ?? [];

      // ?
      if (is_string($name) === false || $name === '' || is_array($arguments) === false) {
         throw new Fault(JSON::INVALID_PARAMS, 'Invalid params: name (string) and arguments (object) are required');
      }

      // :
      return $Prompter($name, $arguments, $context);
   }
}
