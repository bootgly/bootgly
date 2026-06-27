<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\WS_Client_CLI\Decoders;


use function array_shift;
use function explode;
use function in_array;
use function strpos;
use function strtolower;
use function substr;
use function trim;

use Bootgly\WPI\Nodes\WS_Client_CLI\Decoders;
use Bootgly\WPI\Nodes\WS_Client_CLI\Handshake;
use Bootgly\WPI\Nodes\WS_Client_CLI\Session;


/**
 * Handshake-response decoder: parse the server's `101 Switching Protocols`,
 * verify `Sec-WebSocket-Accept`, and record the negotiated subprotocol +
 * permessage-deflate parameters. A non-101 status or a bad accept hash fails
 * the upgrade (the node drops the TCP connection).
 */
class Decoder_ extends Decoders
{
   /**
    * @return null|array{consumed: int, established?: true, fail?: string}
    */
   public function decode (Session $Session, string $buffer): null|array
   {
      // ? Need the full response head.
      $position = strpos($buffer, "\r\n\r\n");
      if ($position === false) {
         return null;
      }
      $head = substr($buffer, 0, $position);
      $consumed = $position + 4;

      // @ Status line: require "HTTP/1.1 101 ...".
      $lines = explode("\r\n", $head);
      $status = (string) array_shift($lines);
      $parts = explode(' ', $status, 3);
      $code = isSet($parts[1]) ? (int) $parts[1] : 0;
      if ($code !== 101) {
         return ['consumed' => $consumed, 'fail' => "unexpected status {$code}"];
      }

      // @ Header map (lowercased names).
      $fields = [];
      foreach ($lines as $line) {
         $colon = strpos($line, ':');
         if ($colon === false) {
            continue;
         }
         $name = strtolower(trim(substr($line, 0, $colon)));
         $fields[$name] = trim(substr($line, $colon + 1));
      }

      // ? Upgrade + Connection tokens MUST be present (RFC 6455 §4.1) — a correct
      //   accept hash on a response that lacks the upgrade semantics is rejected.
      if (Handshake::check($fields['upgrade'] ?? '', 'websocket') === false) {
         return ['consumed' => $consumed, 'fail' => 'missing or invalid Upgrade header'];
      }
      if (Handshake::check($fields['connection'] ?? '', 'upgrade') === false) {
         return ['consumed' => $consumed, 'fail' => 'missing or invalid Connection header'];
      }

      // ? Verify the accept hash against the key we sent (§4.1).
      if (($fields['sec-websocket-accept'] ?? '') !== Handshake::accept($Session->key)) {
         return ['consumed' => $consumed, 'fail' => 'invalid Sec-WebSocket-Accept'];
      }

      // ? Subprotocol: the server's choice MUST be one we offered — and it MUST
      //   NOT return one when we offered none (§4.1).
      $subprotocol = $fields['sec-websocket-protocol'] ?? '';
      if ($subprotocol !== '' && in_array($subprotocol, $Session->offeredSubprotocols, true) === false) {
         return ['consumed' => $consumed, 'fail' => "server selected an unoffered subprotocol '{$subprotocol}'"];
      }
      $Session->subprotocol = $subprotocol;

      // ? permessage-deflate: accept it only if we offered it, and only if every
      //   negotiated parameter validates (RFC 7692 / §9.1).
      $extension = $fields['sec-websocket-extensions'] ?? '';
      if ($extension !== '') {
         if ($Session->offeredCompression === false) {
            return ['consumed' => $consumed, 'fail' => 'server negotiated an extension that was not offered'];
         }
         $extensions = Handshake::resolve($extension);
         if ($extensions === []) {
            return ['consumed' => $consumed, 'fail' => 'server returned an invalid or unsupported extension'];
         }
         $Session->compress($extensions);
      }

      // :
      return ['consumed' => $consumed, 'established' => true];
   }
}
