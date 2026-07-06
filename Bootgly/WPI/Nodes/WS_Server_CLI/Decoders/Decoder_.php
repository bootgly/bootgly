<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\WS_Server_CLI\Decoders;


use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Endpoints\Servers\Packages;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCP_Packages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Frame;
use Bootgly\WPI\Nodes\WS_Server_CLI\Decoders;
use Bootgly\WPI\Nodes\WS_Server_CLI\Handshake;
use Bootgly\WPI\Nodes\WS_Server_CLI\Session;


class Decoder_ extends Decoders
{
   public function decode (Packages $Package, string $buffer, int $size): States
   {
      /** @var TCP_Packages $Package */
      // ? Parse the HTTP/1.1 upgrade head — reuses the HTTP framing parser.
      //   `null` means the head is incomplete (wait) OR the parser already
      //   rejected it (it called `$Package->reject()`); disambiguate via
      //   `$Package->rejected`.
      $Frame = Frame::parse($Package, $buffer, $size);
      if ($Frame === null) {
         $Package->consumed = 0;
         return $Package->rejected
            ? States::Rejected
            : States::Incomplete;
      }

      // ? Validate the WebSocket upgrade.
      $code = Handshake::validate($Frame->method, $Frame->protocol, $Frame->fields);
      if ($code !== null) {
         $Package->reject(Handshake::deny($code));
         return States::Rejected;
      }

      // ? Authenticate the handshake against the configured guards, if any.
      $Authenticated = null;
      if (Handshake::$Guards !== []) {
         $Authenticated = Handshake::authenticate($Frame->fields, Handshake::$Guards);
         if ($Authenticated === null) {
            $challenge = Handshake::challenge(Handshake::$Guards);
            $Package->reject(Handshake::deny(401, $challenge));
            return States::Rejected;
         }
      }

      // ? Custom upgrade predicate (Events::HandshakeRequested), e.g. an Origin
      //   allowlist; a `false` return rejects the upgrade with 403.
      if (Handshake::permit($Frame->fields) === false) {
         $Package->reject(Handshake::deny(403));
         return States::Rejected;
      }

      // ! Successful handshake
      $key = Handshake::read($Frame->fields, 'sec-websocket-key');
      $accept = Handshake::accept($key);
      $subprotocol = Handshake::negotiate(
         Handshake::read($Frame->fields, 'sec-websocket-protocol'),
         Handshake::$subprotocols
      );
      // @ permessage-deflate negotiation (RFC 7692).
      $extensions = Handshake::resolve(
         Handshake::read($Frame->fields, 'sec-websocket-extensions')
      );
      $response = Handshake::build($accept, $subprotocol, Handshake::extend($extensions));

      // @ Build the per-connection session and arm the 101 response.
      $Session = new Session($Package->Connection);
      $Session->subprotocol = $subprotocol;
      if ($Authenticated !== null) {
         $Session->identity = $Authenticated->identity;
         $Session->claims = $Authenticated->claims;
         $Session->tokenHeaders = $Authenticated->tokenHeaders;
      }
      if ($extensions !== []) {
         $Session->compress($extensions);
      }
      $Session->handshake = $response;

      // @ Swap to the shared (stateless) frame decoder; persist the session;
      //   mark the head as consumed. The read engine's pipeline loop then
      //   re-decodes any trailing bytes with this decoder in the SAME read
      //   cycle. `Decoders::$Framing` is installed by the node so this entry
      //   decoder never references its later sibling directly.
      $Package->decoded = $Session;
      $Package->Decoder = self::$Framing;
      $Package->consumed = $Frame->separatorPosition + 4;

      // :
      return States::Complete;
   }
}
