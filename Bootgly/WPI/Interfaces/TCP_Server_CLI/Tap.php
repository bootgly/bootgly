<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\TCP_Server_CLI;


use const STREAM_SERVER_BIND;
use const STREAM_SERVER_LISTEN;
use function chmod;
use function count;
use function fclose;
use function feof;
use function fread;
use function fwrite;
use function stream_set_blocking;
use function stream_socket_accept;
use function stream_socket_server;
use function strlen;
use function substr;
use function umask;
use function unlink;
use Closure;

use Bootgly\ABI\IO\IPC\Pipe as IPCPipe;
use Bootgly\ACI\Logs\Handlers\Pipe as PipeHandler;
use Bootgly\CLI\UI\Components\Logs as LogsViewer;


/**
 * The live-log tap hub: a per-instance unix socket that external `bootgly logs -f`
 * sessions attach to, fanned out by the master from the worker log pipe.
 *
 * Zero cost when nobody is attached: the pipe is only drained (and workers are only
 * armed, via the attach/detach callbacks) while at least one session is connected.
 */
class Tap
{
   // Datagrams drained per pump — sized for the 0.5s master tick, not Monitor's 30ms.
   private const int DRAIN_FRAMES = 256;
   private const int MAX_CLIENTS = 8;
   // Per-client fan-out buffer cap: a stalled session drops frames, never blocks the master.
   private const int MAX_PENDING_BYTES = 262144;

   // * Config
   public readonly string $path;
   public IPCPipe $Pipe;
   /** Fired when the first session attaches (0 → 1). */
   public null|Closure $onAttach = null;
   /** Fired when the last session detaches (1 → 0). */
   public null|Closure $onDetach = null;

   // * Data
   /** @var resource|null */
   private mixed $Listener = null;
   /** @var array<int,array{stream:resource,pending:string}> */
   private array $Clients = [];

   // * Metadata
   public private(set) int $attached = 0;


   public function __construct (string $path, IPCPipe $Pipe)
   {
      // * Config
      $this->path = $path;
      $this->Pipe = $Pipe;
   }

   /**
    * Bind the tap socket (owner-only) — called by the master while it still holds
    * the launch privileges and the fresh instance lock (a stale pathname from a
    * crashed predecessor is safe to unlink under that lock).
    */
   public function open (): bool
   {
      // ? Reclaim a stale pathname (kill -9 leaves the socket inode behind)
      @unlink($this->path);

      // ! Owner-only FROM CREATION: bind() honors the umask, so the inode is never
      //   group/other-connectable — not even before the explicit chmod below
      $mask = umask(0177);
      try {
         $Listener = @stream_socket_server(
            "unix://{$this->path}",
            $code,
            $message,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
         );
      }
      finally {
         umask($mask);
      }
      if ($Listener === false) {
         return false;
      }

      // ! Owner-only: attaching requires the same trust as reading storage/logs
      @chmod($this->path, 0600);
      stream_set_blocking($Listener, false);

      $this->Listener = $Listener;

      // :
      return true;
   }

   /**
    * One bounded service pass, run from the master loops: accept new sessions,
    * reap disconnections, and — only while someone is watching — drain the log
    * pipe into the viewer and every attached session.
    *
    * @param null|LogsViewer $Viewer Monitor-mode viewer fed from the same drain.
    * @return int Number of complete frames delivered this pass.
    */
   public function pump (null|LogsViewer $Viewer = null): int
   {
      if ($this->Listener === null) {
         // ?: Hub closed — nothing to serve
         return 0;
      }

      // @ Reap FIRST: sessions never send payload — readable EOF means detach.
      //   Reaping before accepting frees cap slots held by sessions that died
      //   since the last pump, so a fresh session is never refused by ghosts.
      foreach ($this->Clients as $id => $Client) {
         $bytes = @fread($Client['stream'], 1024);
         if (($bytes === '' || $bytes === false) && feof($Client['stream']) === true) {
            fclose($Client['stream']);
            unset($this->Clients[$id]);
         }
      }

      // @ Accept (bounded) — extras beyond the cap get connect-then-close
      for ($accepted = 0; $accepted < self::MAX_CLIENTS; $accepted++) {
         $Stream = @stream_socket_accept($this->Listener, 0);
         if ($Stream === false) {
            break;
         }
         if (count($this->Clients) >= self::MAX_CLIENTS) {
            fclose($Stream);
            continue;
         }
         stream_set_blocking($Stream, false);
         $this->Clients[(int) $Stream] = ['stream' => $Stream, 'pending' => ''];
      }

      // @ Edge-triggered arm/disarm on the 0↔1 transitions
      $count = count($this->Clients);
      if ($this->attached === 0 && $count > 0) {
         ($this->onAttach)?->__invoke();
      }
      else if ($this->attached > 0 && $count === 0) {
         ($this->onDetach)?->__invoke();
      }
      $this->attached = $count;

      // ? Nobody watching: skip the drain entirely — the zero-cost invariant
      if ($count === 0 && $Viewer === null) {
         return 0;
      }

      // @@ Drain the worker log pipe — bounded so the loop always regains control
      $frames = 0;
      for (; $frames < self::DRAIN_FRAMES; $frames++) {
         $chunk = $this->Pipe->read(PipeHandler::MAX_FRAME_BYTES);
         if ($chunk === false || $chunk === '') {
            break;
         }
         $Viewer?->feed($chunk);
         $this->relay($chunk);
      }

      // @ Push out what backpressure left pending
      foreach ($this->Clients as $id => $Client) {
         if ($Client['pending'] !== '') {
            $this->write($id, '');
         }
      }

      // :
      return $frames;
   }

   /**
    * Fan one NDJSON frame out to every attached session — non-blocking, with a
    * bounded per-session buffer: a stalled session drops frames alone.
    */
   public function relay (string $frame): void
   {
      foreach ($this->Clients as $id => $Client) {
         // ? Backpressure: drop the whole frame for this session only
         if (strlen($Client['pending']) + strlen($frame) > self::MAX_PENDING_BYTES) {
            continue;
         }
         $this->write($id, $frame);
      }
   }

   /**
    * Close every inherited descriptor WITHOUT touching the pathname — forked
    * children (workers) must never serve nor unlink the master's socket.
    */
   public function drop (): void
   {
      foreach ($this->Clients as $Client) {
         fclose($Client['stream']);
      }
      $this->Clients = [];
      if ($this->Listener !== null) {
         fclose($this->Listener);
         $this->Listener = null;
      }
      $this->attached = 0;
   }

   /**
    * Master teardown: close every session and best-effort remove the pathname.
    */
   public function close (): void
   {
      $this->drop();
      @unlink($this->path);
   }

   /**
    * Non-blocking buffered write to one session (pass '' to flush its pending).
    */
   private function write (int $id, string $frame): void
   {
      $Client = &$this->Clients[$id];
      $buffer = $Client['pending'] . $frame;
      if ($buffer === '') {
         return;
      }

      $written = @fwrite($Client['stream'], $buffer);
      if ($written === false) {
         // ? Broken session — reap it now; the transition fires on the next pump
         fclose($Client['stream']);
         unset($this->Clients[$id]);
         return;
      }

      $Client['pending'] = substr($buffer, $written);
   }
}
