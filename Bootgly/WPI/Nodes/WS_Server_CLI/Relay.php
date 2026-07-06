<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\WS_Server_CLI;


use function fclose;
use function fread;
use function fwrite;
use function max;
use function pack;
use function stream_set_blocking;
use function strlen;
use function substr;
use function unpack;

use Bootgly\WPI\Nodes\WS_Server_CLI\Channels;


/**
 * Cross-worker broadcast bus.
 *
 * Channels live per worker (each fork+SO_REUSEPORT process has its own
 * registry), so a same-worker `broadcast()` reaches only local members. The
 * Relay closes that gap: the node creates one datagram socketpair per worker
 * before forking, and each worker keeps its own receive mailbox plus the send
 * ends of every peer mailbox. Publishing a broadcast sends a framed envelope
 * to every peer; each peer delivers it to its local room members.
 *
 * Best-effort and event-loop-native: the mailbox is a non-blocking datagram
 * socket registered for reads, and a single broadcast is one datagram per peer.
 */
class Relay
{
   // * Config
   public static int $maxDatagram = 65536;     // 64 KiB — envelope cap per worker hop


   // * Metadata
   public static null|Relay $Instance = null;

   /** @var resource Per-worker receive mailbox (datagram socket). */
   public $Socket;
   /** @var array<int, resource> Send ends of every peer worker's mailbox. */
   public array $Peers = [];


   /**
    * @param array<int, array<int, resource>> $buses One datagram socketpair per worker.
    */
   public function __construct (array $buses, int $index, int $workers)
   {
      // ! Keep my own receive end (non-blocking) — the rest are peers'.
      $this->Socket = $buses[$index][0];
      stream_set_blocking($this->Socket, false);

      // @ Keep the send end of every peer mailbox; close the ends I do not own.
      for ($worker = 0; $worker < $workers; $worker++) {
         if ($worker === $index) {
            // ? My own send end is unused — local delivery is direct.
            @fclose($buses[$worker][1]);
            continue;
         }
         $this->Peers[] = $buses[$worker][1];
         // ? Peer receive ends belong to the peer worker's process.
         @fclose($buses[$worker][0]);
      }
   }

   /**
    * Forward a broadcast frame to every peer worker (no-op when single-worker).
    */
   public static function publish (string $channel, string $frame): void
   {
      self::$Instance?->relay($channel, $frame);
   }

   /**
    * Send the framed envelope to each peer mailbox (best-effort, non-blocking).
    */
   public function relay (string $channel, string $frame): void
   {
      // ! Envelope: 4-byte channel length + channel + pre-encoded WS frame.
      $envelope = pack('N', strlen($channel)) . $channel . $frame;

      // ? A single datagram must carry the whole envelope.
      if (strlen($envelope) > self::$maxDatagram) {
         return;
      }

      // @
      foreach ($this->Peers as $Peer) {
         @fwrite($Peer, $envelope);
      }
   }

   /**
    * Drain inbound envelopes and deliver each to local room members. Invoked by
    * the event loop when the mailbox socket is readable.
    *
    * @param resource $Socket
    */
   public function reading ($Socket): void
   {
      while (true) {
         $datagram = @fread($Socket, max(1, self::$maxDatagram));
         if ($datagram === '' || $datagram === false) {
            break;
         }
         if (strlen($datagram) < 4) {
            continue;
         }

         $header = unpack('N', substr($datagram, 0, 4));
         if ($header === false) {
            continue;
         }
         $length = $header[1];
         $channel = substr($datagram, 4, $length);
         $frame = substr($datagram, 4 + $length);

         // @ Local delivery only — never re-publish (avoids a cross-worker loop).
         Channels::find($channel)?->broadcast($frame, null);
      }
   }
}
