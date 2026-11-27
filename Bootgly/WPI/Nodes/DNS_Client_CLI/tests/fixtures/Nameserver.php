<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\DNS_Client_CLI\tests\fixtures;


use const FILE_APPEND;
use const LOCK_EX;
use const LOCK_UN;
use const SIG_DFL;
use const SIG_IGN;
use const SIGINT;
use const SIGKILL;
use const SIGPIPE;
use const SIGTERM;
use const STREAM_SERVER_BIND;
use const STREAM_SERVER_LISTEN;
use const WNOHANG;
use function array_shift;
use function ceil;
use function count;
use function explode;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function flock;
use function fopen;
use function fread;
use function ftruncate;
use function fwrite;
use function getmypid;
use function is_array;
use function is_resource;
use function json_decode;
use function json_encode;
use function max;
use function microtime;
use function min;
use function pack;
use function pcntl_fork;
use function pcntl_signal;
use function pcntl_waitpid;
use function posix_getppid;
use function posix_kill;
use function rewind;
use function rtrim;
use function str_repeat;
use function str_split;
use function stream_get_contents;
use function stream_select;
use function stream_set_blocking;
use function stream_socket_accept;
use function stream_socket_get_name;
use function stream_socket_recvfrom;
use function stream_socket_sendto;
use function stream_socket_server;
use function strlen;
use function strrpos;
use function strtolower;
use function substr;
use function touch;
use function unpack;
use function usleep;
use Closure;
use RuntimeException;
use stdClass;
use Throwable;

use Bootgly\ABI\Data\DNS;
use Bootgly\ABI\Data\DNS\Message;
use Bootgly\ABI\Data\DNS\Message\Question;
use Bootgly\ABI\Data\DNS\Message\Record;


/**
 * An in-test fake recursive nameserver on 127.0.0.1: UDP and TCP on one ephemeral port.
 *
 * The sockets are bound here, then a forked child serves them until `stop()` — or until the
 * process that started it is gone. The script and the query log live in a directory both
 * processes share, so a case can re-script the fake while it runs.
 *
 * A question (`name` + `type`, the name compared lowercase, without its trailing dot) is
 * answered by the first rule scripted for it, else by the rules scripted for `*` (any
 * question), else NXDOMAIN. A rule is an array of:
 * - `rcode` (int, default 0) · `answers` / `authorities` (lists of `[name, type, TTL, data]`)
 * - `delay` (seconds the answer is held back, without blocking other queries)
 * - `drop` (true: never answer) · `truncated` (true: answer with TC and the question only)
 * - `times` (the rule answers that many queries, then is removed; default: forever)
 * - `tcp` (a rule that replaces this one for the same question asked over TCP)
 * - `decoys` (datagrams sent before the answer: each `['spoof' => 'id'|'name'|'type'|'class'|'opcode'|'response'|'size'|'source', 'answers' => [...]]`,
 *   a wrong id / question name / question type / question class (CH), a non-QUERY opcode
 *   (STATUS), a query bit, a datagram larger than the advertised size, or the right answer sent
 *   from another port)
 * - `trickle` (over TCP: `[segments, gap]` — the framed answer written in that many pieces,
 *   `gap` seconds apart)
 */
final class Nameserver
{
   // * Config
   public private(set) string $host;
   public private(set) int $port;

   // * Metadata
   /** State directory, shared with the forked server. */
   private string $directory;
   private int $PID = 0;
   /** The process that started the server — the only one that stops it. */
   private int $owner;


   private function __construct (string $directory, string $host)
   {
      // * Config
      $this->host = $host;
      $this->port = 0;
      // * Metadata
      $this->directory = rtrim($directory, '/') . '/';
      $this->owner = (int) getmypid();
   }

   /**
    * Bind UDP + TCP on one ephemeral port of `$host`, fork the server and return once it listens.
    *
    * @param string $directory An existing, empty directory for the fake's state (the caller removes it).
    */
   public static function start (string $directory, string $host = '127.0.0.1'): self
   {
      $Nameserver = new self($directory, $host);

      file_put_contents("{$Nameserver->directory}rules.json", '{}');
      touch("{$Nameserver->directory}queries.log");

      // ! UDP first (port 0), then TCP on the same port — a few tries if that port is taken
      $UDP = false;
      $TCP = false;
      // @@
      for ($attempt = 0; $attempt < 8 && $TCP === false; $attempt++) {
         if (is_resource($UDP)) {
            fclose($UDP);
         }
         $UDP = @stream_socket_server("udp://{$host}:0", $errno, $error, STREAM_SERVER_BIND);
         if ($UDP === false) {
            throw new RuntimeException("Nameserver fixture: cannot bind UDP on {$host} ({$error})");
         }
         $name = (string) stream_socket_get_name($UDP, false);
         $port = (int) substr($name, (int) strrpos($name, ':') + 1);
         $TCP = @stream_socket_server(
            "tcp://{$host}:{$port}", $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
         );
         $Nameserver->port = $port;
      }
      if ($UDP === false || $TCP === false) {
         throw new RuntimeException("Nameserver fixture: cannot bind TCP beside UDP on {$host}");
      }

      $PID = pcntl_fork();
      // ? A failed fork must never hand back -1: stop() would signal every process we own
      if ($PID < 0) {
         fclose($UDP);
         fclose($TCP);

         throw new RuntimeException('Nameserver fixture: fork failed');
      }

      // @ Child: serve, then die without running the parent's shutdown functions or destructors
      if ($PID === 0) {
         pcntl_signal(SIGTERM, SIG_DFL);
         pcntl_signal(SIGINT, SIG_DFL);
         pcntl_signal(SIGPIPE, SIG_IGN);
         try {
            $Nameserver->serving($UDP, $TCP);
         }
         catch (Throwable) {
            // ! A crashed server just goes away: the case sees timeouts
         }
         posix_kill((int) getmypid(), SIGKILL);
         exit(0);
      }

      fclose($UDP);
      fclose($TCP);
      $Nameserver->PID = $PID;

      // :
      return $Nameserver;
   }

   /**
    * Kill and reap the server. Idempotent; a forked case never stops its parent's server.
    */
   public function stop (): void
   {
      // ?
      if ($this->PID <= 0 || getmypid() !== $this->owner) {
         return;
      }

      posix_kill($this->PID, SIGTERM);
      $deadline = microtime(true) + 2.0;
      // @@ Reap; SIGKILL after 2 s
      while (pcntl_waitpid($this->PID, $status, WNOHANG) === 0) {
         if (microtime(true) > $deadline) {
            posix_kill($this->PID, SIGKILL);
            pcntl_waitpid($this->PID, $status);

            break;
         }
         usleep(10_000);
      }
      $this->PID = 0;
   }

   public function __destruct ()
   {
      $this->stop();
   }

   /**
    * Queue one rule for a question (`$name` `*` = any question).
    *
    * @param array<string,mixed> $rule See the class description.
    */
   public function script (string $name, int $type, array $rule): void
   {
      $this->update(static function (array $rules) use ($name, $type, $rule): array {
         $rules[self::key($name, $type)][] = $rule;

         return $rules;
      });
   }

   /**
    * Drop every scripted rule and the query log.
    */
   public function reset (): void
   {
      $this->update(static fn (array $rules): array => []);
      file_put_contents("{$this->directory}queries.log", '', LOCK_EX);
   }

   /**
    * Every query received so far, in arrival order.
    *
    * @return list<array{transport:string,id:int,name:string,type:int,port:int,size:null|int,at:float}>
    */
   public function log (): array
   {
      $Queries = [];
      $lines = (string) @file_get_contents("{$this->directory}queries.log");
      // @@
      foreach (explode("\n", $lines) as $line) {
         if ($line === '') {
            continue;
         }
         $Query = json_decode($line, associative: true);
         if (is_array($Query)) {
            $Queries[] = $Query;
         }
      }

      // :
      return $Queries;
   }

   private static function key (string $name, int $type): string
   {
      $name = rtrim(strtolower($name), '.');

      return "{$name}|{$type}";
   }

   /**
    * @param Closure(array<string,list<array<string,mixed>>>): array<string,list<array<string,mixed>>> $Change
    */
   private function update (Closure $Change): void
   {
      $Handle = fopen("{$this->directory}rules.json", 'c+');
      if ($Handle === false) {
         throw new RuntimeException('Nameserver fixture: cannot open the rules file');
      }
      flock($Handle, LOCK_EX);
      $rules = json_decode((string) stream_get_contents($Handle), associative: true);
      $rules = is_array($rules) ? $rules : [];
      $rules = $Change($rules);
      ftruncate($Handle, 0);
      rewind($Handle);
      fwrite($Handle, (string) json_encode($rules === [] ? new stdClass : $rules));
      flock($Handle, LOCK_UN);
      fclose($Handle);
   }

   /**
    * Take the rule that answers this question now (and spend one of its `times`).
    *
    * @return null|array<string,mixed>
    */
   private function take (string $name, int $type, bool $TCP): null|array
   {
      $Taken = null;
      $this->update(static function (array $rules) use ($name, $type, &$Taken): array {
         // @@ The question's own rules, then the catch-all
         foreach ([self::key($name, $type), self::key('*', 0)] as $key) {
            if (isset($rules[$key]) === false || $rules[$key] === []) {
               continue;
            }
            $Taken = $rules[$key][0];
            if (isset($Taken['times'])) {
               $rules[$key][0]['times'] = (int) $Taken['times'] - 1;
               if ($rules[$key][0]['times'] <= 0) {
                  array_shift($rules[$key]);
               }
            }

            break;
         }

         return $rules;
      });

      if ($TCP && is_array($Taken) && isset($Taken['tcp']) && is_array($Taken['tcp'])) {
         return $Taken['tcp'];
      }

      // :
      return $Taken;
   }

   /**
    * @param list<array{0:string,1:int,2:int,3:mixed}> $rows
    *
    * @return list<Record>
    */
   private static function build (array $rows): array
   {
      $Records = [];
      // @@
      foreach ($rows as [$name, $type, $TTL, $data]) {
         $Records[] = new Record($name, $type, $TTL, $data);
      }

      return $Records;
   }

   /**
    * Build the answer datagrams for one query: decoys first, the answer last (`null` = drop).
    *
    * @return array{0:list<array{0:string,1:bool}>,1:float,2?:float} Datagrams ([bytes, from another
    *    port — or, trickled over TCP, already framed]), the delay, and the gap between trickled pieces.
    */
   private function answer (Message $Query, bool $TCP): array
   {
      $Question = $Query->Questions[0];
      $rule = $this->take($Question->name, $Question->type, $TCP) ?? ['rcode' => DNS::RCODE_NXDOMAIN];
      $size = $Query->size;
      $Datagrams = [];

      // @@ Decoys
      foreach ((array) ($rule['decoys'] ?? []) as $decoy) {
         $spoof = (string) ($decoy['spoof'] ?? 'id');
         $Answers = self::build((array) ($decoy['answers'] ?? []));
         $Fake = new Question(
            $spoof === 'name' ? "decoy.{$Question->name}" : $Question->name,
            $spoof === 'type' ? ($Question->type === DNS::TYPE_A ? DNS::TYPE_AAAA : DNS::TYPE_A) : $Question->type,
            $spoof === 'class' ? 3 : $Question->class
         );
         $Message = new Message(
            id: $spoof === 'id' ? ($Query->id + 1) & 0xFFFF : $Query->id,
            response: $spoof !== 'response',
            opcode: $spoof === 'opcode' ? 2 : 0,
            recursion: true,
            recursive: true,
            size: $size,
            Questions: [$Fake],
            Answers: $Answers
         );
         $bytes = DNS::encode($Message);
         if ($bytes instanceof DNS\Faults) {
            continue;
         }
         if ($spoof === 'size') {
            // ! A valid answer padded past the advertised size (trailing bytes after the message)
            $bytes .= str_repeat("\0", max(0, ($size ?? DNS::SIZE) + 1 - strlen($bytes)));
         }
         $Datagrams[] = [$bytes, $spoof === 'source'];
      }

      // ? Never answered
      if (($rule['drop'] ?? false) === true) {
         return [$Datagrams, (float) ($rule['delay'] ?? 0.0)];
      }

      $truncated = ($rule['truncated'] ?? false) === true;
      $Message = new Message(
         id: $Query->id,
         response: true,
         truncated: $truncated,
         recursion: $Query->recursion,
         recursive: true,
         rcode: (int) ($rule['rcode'] ?? DNS::RCODE_NOERROR),
         size: $truncated ? null : $size,
         Questions: [$Question],
         Answers: $truncated ? [] : self::build((array) ($rule['answers'] ?? [])),
         Authorities: $truncated ? [] : self::build((array) ($rule['authorities'] ?? []))
      );
      $bytes = DNS::encode($Message);
      if (is_string($bytes)) {
         $Datagrams[] = [$bytes, false];
      }

      // ? Over TCP, trickled: the framed answer split in pieces, each its own (already framed) item
      $trickle = $rule['trickle'] ?? null;
      if ($TCP && is_array($trickle) && $Datagrams !== []) {
         [$bytes] = $Datagrams[count($Datagrams) - 1];
         $frame = pack('n', strlen($bytes)) . $bytes;
         $segments = max(1, (int) ($trickle[0] ?? 1));
         $size = max(1, (int) ceil(strlen($frame) / $segments));
         $Datagrams = [];
         foreach (str_split($frame, $size) as $piece) {
            $Datagrams[] = [$piece, true];
         }

         return [$Datagrams, (float) ($rule['delay'] ?? 0.0), (float) ($trickle[1] ?? 0.0)];
      }

      // :
      return [$Datagrams, (float) ($rule['delay'] ?? 0.0)];
   }

   private function record (string $transport, Message $Query, int $port): void
   {
      $Question = $Query->Questions[0] ?? null;
      $line = json_encode([
         'transport' => $transport,
         'id' => $Query->id,
         'name' => $Question?->name ?? '',
         'type' => $Question?->type ?? 0,
         'port' => $port,
         'size' => $Query->size,
         'at' => microtime(true),
      ]);
      file_put_contents("{$this->directory}queries.log", "{$line}\n", FILE_APPEND | LOCK_EX);
   }

   // # Server (the forked child)
   /**
    * @param resource $UDP
    * @param resource $TCP
    */
   private function serving ($UDP, $TCP): void
   {
      $parent = posix_getppid();
      $Other = @stream_socket_server("udp://{$this->host}:0", $errno, $error, STREAM_SERVER_BIND);
      stream_set_blocking($TCP, false);
      /** @var array<int,resource> $Peers TCP peers */
      $Peers = [];
      /** @var array<int,string> $buffers */
      $buffers = [];
      /** @var list<array{0:float,1:string,2:null|resource,3:string,4:bool}> $Held [due, bytes, TCP peer, UDP address, from another port] */
      $Held = [];

      // @@ Serve until the parent is gone
      while (posix_getppid() === $parent) {
         $now = microtime(true);
         // @ Release what is due
         $Pending = [];
         foreach ($Held as $Item) {
            [$due, $bytes, $Peer, $address, $other] = $Item;
            if ($due > $now) {
               $Pending[] = $Item;
               continue;
            }
            if ($Peer !== null) {
               if (is_resource($Peer)) {
                  // ! A trickled piece is already framed
                  @fwrite($Peer, $other ? $bytes : pack('n', strlen($bytes)) . $bytes);
               }
               continue;
            }
            @stream_socket_sendto($other && is_resource($Other) ? $Other : $UDP, $bytes, 0, $address);
         }
         $Held = $Pending;

         $timeout = 0.2;
         foreach ($Held as [$due]) {
            $timeout = min($timeout, max(0.0, $due - $now));
         }

         $read = [$UDP, $TCP, ...$Peers];
         $write = null;
         $except = null;
         $selected = @stream_select($read, $write, $except, 0, (int) ($timeout * 1_000_000));
         if ($selected === false || $selected === 0) {
            continue;
         }

         // @@ Readable sockets
         foreach ($read as $Socket) {
            if ($Socket === $UDP) {
               $bytes = stream_socket_recvfrom($UDP, 65535, 0, $address);
               $Query = is_string($bytes) ? DNS::decode($bytes) : null;
               if ($Query instanceof Message === false || $Query->Questions === []) {
                  continue;
               }
               $port = (int) substr($address, (int) strrpos($address, ':') + 1);
               $this->record('udp', $Query, $port);
               [$Datagrams, $delay] = $this->answer($Query, false);
               foreach ($Datagrams as $index => [$datagram, $other]) {
                  // ! Decoys go first: the answer comes after them, held by the same delay
                  $Held[] = [microtime(true) + $delay + $index * 0.001, $datagram, null, $address, $other];
               }

               continue;
            }

            if ($Socket === $TCP) {
               $Peer = @stream_socket_accept($TCP, 0);
               if ($Peer !== false) {
                  stream_set_blocking($Peer, false);
                  $Peers[(int) $Peer] = $Peer;
                  $buffers[(int) $Peer] = '';
               }

               continue;
            }

            $id = (int) $Socket;
            $chunk = @fread($Socket, 65535);
            if ($chunk === false || $chunk === '') {
               fclose($Socket);
               unset($Peers[$id], $buffers[$id]);

               continue;
            }
            $buffers[$id] .= $chunk;
            // @@ Every complete length-prefixed message
            while (strlen($buffers[$id]) >= 2) {
               $length = (int) unpack('n', substr($buffers[$id], 0, 2))[1];
               if (strlen($buffers[$id]) < 2 + $length) {
                  break;
               }
               $bytes = substr($buffers[$id], 2, $length);
               $buffers[$id] = substr($buffers[$id], 2 + $length);
               $Query = DNS::decode($bytes);
               if ($Query instanceof Message === false || $Query->Questions === []) {
                  continue;
               }
               $name = (string) stream_socket_get_name($Socket, true);
               $this->record('tcp', $Query, (int) substr($name, (int) strrpos($name, ':') + 1));
               $Answer = $this->answer($Query, true);
               [$Datagrams, $delay] = $Answer;
               $gap = $Answer[2] ?? null;
               foreach ($Datagrams as $index => [$datagram, $framed]) {
                  $Held[] = [microtime(true) + $delay + $index * ($gap ?? 0.001), $datagram, $Socket, '', $gap !== null && $framed];
               }
            }
         }

         if (count($Peers) > 256) {
            break;
         }
      }
   }
}
