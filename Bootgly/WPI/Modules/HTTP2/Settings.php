<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Modules\HTTP2;


use const PHP_INT_MAX;
use function pack;
use function strlen;
use function unpack;

use Bootgly\WPI\Modules\HTTP2;


/**
 * One HTTP/2 settings set (RFC 9113 §6.5.2) — local (advertised) or remote
 * (applied from the peer's SETTINGS frames). Defaults are the RFC defaults.
 */
final class Settings
{
   // * Data
   // # SETTINGS_HEADER_TABLE_SIZE — HPACK dynamic table octet limit
   public int $table = 4096;
   // # SETTINGS_ENABLE_PUSH — whether the peer accepts PUSH_PROMISE
   public bool $push = true;
   // # SETTINGS_MAX_CONCURRENT_STREAMS — streams the sender allows the peer to open
   public int $streams = 2147483647;
   // # SETTINGS_INITIAL_WINDOW_SIZE — per-stream flow-control window
   public int $window = 65535;
   // # SETTINGS_MAX_FRAME_SIZE — largest frame payload the sender accepts
   /** @var int<16384, 16777215> `parse()` rejects values outside the RFC range */
   public int $frame = 16384;
   // # SETTINGS_MAX_HEADER_LIST_SIZE — decoded header list octet cap (advisory)
   public int $list = PHP_INT_MAX;


   /**
    * Apply a peer SETTINGS frame payload to this set.
    *
    * @return null|Errors `null` on success, or the connection error to raise.
    */
   public function parse (string $payload): null|Errors
   {
      // ? SETTINGS payload is a sequence of 6-octet {uint16 id, uint32 value} pairs
      $size = strlen($payload);
      if ($size % 6 !== 0) {
         return Errors::FrameSize;
      }

      // @@
      for ($offset = 0; $offset < $size; $offset += 6) {
         /** @var array{id: int, value: int} $pair */
         $pair = unpack('nid/Nvalue', $payload, $offset);
         $value = $pair['value'];

         switch ($pair['id']) {
            case HTTP2::SETTINGS_HEADER_TABLE_SIZE:
               $this->table = $value;
               break;
            case HTTP2::SETTINGS_ENABLE_PUSH:
               // ? Any value other than 0 or 1 is a protocol error
               if ($value > 1) {
                  return Errors::Protocol;
               }
               $this->push = ($value === 1);
               break;
            case HTTP2::SETTINGS_MAX_CONCURRENT_STREAMS:
               $this->streams = $value;
               break;
            case HTTP2::SETTINGS_INITIAL_WINDOW_SIZE:
               // ? Values above 2^31-1 are a flow-control error
               if ($value > 2147483647) {
                  return Errors::FlowControl;
               }
               $this->window = $value;
               break;
            case HTTP2::SETTINGS_MAX_FRAME_SIZE:
               // ? Valid range: 2^14 .. 2^24-1
               if ($value < 16384 || $value > 16777215) {
                  return Errors::Protocol;
               }
               $this->frame = $value;
               break;
            case HTTP2::SETTINGS_MAX_HEADER_LIST_SIZE:
               $this->list = $value;
               break;
            default:
               // @ Unknown identifiers must be ignored (RFC 9113 §6.5.2)
         }
      }

      // :
      return null;
   }

   /**
    * Serialize this set as a SETTINGS frame payload.
    *
    * Only values that differ from the RFC defaults are emitted — an omitted
    * setting means "default" to the peer, so the payload stays minimal.
    */
   public function pack (): string
   {
      // !
      $payload = '';

      // @
      if ($this->table !== 4096) {
         $payload .= pack('nN', HTTP2::SETTINGS_HEADER_TABLE_SIZE, $this->table);
      }
      if ($this->push !== true) {
         $payload .= pack('nN', HTTP2::SETTINGS_ENABLE_PUSH, 0);
      }
      if ($this->streams !== 2147483647) {
         $payload .= pack('nN', HTTP2::SETTINGS_MAX_CONCURRENT_STREAMS, $this->streams);
      }
      if ($this->window !== 65535) {
         $payload .= pack('nN', HTTP2::SETTINGS_INITIAL_WINDOW_SIZE, $this->window);
      }
      if ($this->frame !== 16384) {
         $payload .= pack('nN', HTTP2::SETTINGS_MAX_FRAME_SIZE, $this->frame);
      }
      if ($this->list !== PHP_INT_MAX) {
         $payload .= pack('nN', HTTP2::SETTINGS_MAX_HEADER_LIST_SIZE, $this->list);
      }

      // :
      return $payload;
   }
}
