<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\DNS_Client_CLI;


use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;
use function array_keys;
use function count;
use function fclose;
use function feof;
use function fread;
use function fwrite;
use function hrtime;
use function in_array;
use function is_array;
use function is_resource;
use function is_string;
use function max;
use function microtime;
use function min;
use function pack;
use function random_int;
use function rtrim;
use function str_contains;
use function str_ends_with;
use function strcasecmp;
use function stream_select;
use function stream_set_blocking;
use function stream_socket_pair;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function unpack;
use Closure;
use Fiber;
use RuntimeException;
use Throwable;

use Bootgly\ABI\Data\DNS;
use Bootgly\ABI\Data\DNS\Faults;
use Bootgly\ABI\Data\DNS\Message;
use Bootgly\ABI\Data\DNS\Message\Question;
use Bootgly\ABI\Data\DNS\Message\Record;
use Bootgly\ACI\Events\Loops;
use Bootgly\ACI\Events\Readiness;
use Bootgly\ACI\Events\Scheduler;
use Bootgly\WPI\Events;
use Bootgly\WPI\Interfaces\TCP_Client_CLI;
use Bootgly\WPI\Interfaces\TCP_Client_CLI\Configs as TCP_Configs;
use Bootgly\WPI\Interfaces\UDP_Client_CLI;
use Bootgly\WPI\Interfaces\UDP_Client_CLI\Configs as UDP_Configs;
use Bootgly\WPI\Interfaces\UDP_Client_CLI\Outcomes as Waits;
use Bootgly\WPI\Nodes\DNS_Client_CLI\Answer\Outcomes;


/**
 * One in-flight DNS question: a name and one or two types (A and AAAA ride
 * one UDP flow, two ids), asked to the nameservers in turn.
 *
 * Each try opens a fresh connected UDP flow (a fresh OS source port) and
 * waits `timeout` seconds; there are `attempts` rounds over the nameservers.
 * A datagram is read only when its length fits the size the query advertised,
 * its id is pending, it decodes, it is a response to a standard query and its
 * one question is ours. NOERROR and NXDOMAIN settle a type; SERVFAIL,
 * REFUSED and NOTIMP move it to the next nameserver; FORMERR to an EDNS query
 * retries once without EDNS. A truncated answer is asked again over TCP to
 * the same nameserver (once); truncated over TCP fails.
 *
 * On a reactor the exchange runs in a driver Fiber this query owns (`drive()`),
 * and lookups wait for it on a doorbell (`await()`): no waiter is needed to
 * complete it, and a cancelled waiter touches nothing but its own count. Only
 * the query's final outcome writes the cache.
 */
final class Query
{
   // * Config
   /** The absolute name asked (lowercased, no trailing dot). */
   public private(set) string $name;
   /** @var list<int> The record types asked. */
   public private(set) array $types;

   // * Data
   /** @var list<string> */
   protected array $nameservers;
   protected int $port;
   protected float $timeout;
   protected int $attempts;
   protected int $start;
   protected bool $EDNS;
   protected Cache $Cache;
   /** The prefix of this query's cache keys. */
   protected string $scope;

   // * Metadata
   /** Whether every type has its final answer. */
   public private(set) bool $settled = false;
   /** @var array<int,Answer> The answer of each type, once settled. */
   public private(set) array $Answers = [];
   /**
    * @var array<int,string> Why each failed type failed: `SERVFAIL` (that rcode), `refused`
    *    (`REFUSED`, `NOTIMP`, `FORMERR` or another error rcode), `timeout`,
    *    `unreachable` (every try refused by ICMP), `truncated` (over TCP), `broken` (a CNAME chain),
    *    `local` (this process: the reactor, a socket; never cached).
    */
   public private(set) array $faults = [];
   /** The monotonic time (`hrtime()`, in seconds) the whole query ends by. */
   public private(set) float $deadline;
   /** Whether the query's deadline passed. */
   public bool $expired {
      get => self::clock() >= $this->deadline;
   }
   /** Lookups waiting on the doorbell now. */
   public private(set) int $waiters = 0;
   /** @var null|resource The doorbell's read end. */
   private mixed $bell = null;
   /** @var null|resource The doorbell's write end (closed on settle). */
   private mixed $ring = null;

   /** The UDP payload size advertised with EDNS(0). */
   public const int SIZE = 1232;
   /** CNAME hops followed (re-queries included). */
   public const int HOPS = 8;
   /** Seconds a failure is cached. */
   public const int FAILURE = 5;


   /**
    * @param string $name The absolute name (lowercased, no trailing dot).
    * @param list<int> $types One or two record types.
    * @param list<string> $nameservers Nameserver IP literals.
    * @param int $port The nameservers' port.
    * @param float $timeout Seconds per try.
    * @param int $attempts Rounds over the nameservers.
    * @param int $start The nameserver the first try goes to.
    * @param bool $EDNS Whether to advertise EDNS(0).
    * @param Cache $Cache Where the final outcome is stored.
    * @param string $scope The prefix of its cache keys (the configuration's).
    */
   public function __construct (
      string $name,
      array $types,
      array $nameservers,
      int $port,
      float $timeout,
      int $attempts,
      int $start,
      bool $EDNS,
      Cache $Cache,
      string $scope = ''
   )
   {
      // * Config
      $this->name = $name;
      $this->types = $types;

      // * Data
      $this->nameservers = $nameservers;
      $this->port = $port;
      $this->timeout = $timeout;
      $this->attempts = $attempts;
      $this->start = $start;
      $this->EDNS = $EDNS;
      $this->Cache = $Cache;
      $this->scope = $scope;

      // * Metadata
      // ! Every try, plus one TCP leg
      $this->deadline = self::clock()
         + $timeout * ($attempts * count($nameservers) + 1);
   }

   /**
    * Run the exchange in a driver Fiber this query owns, on a reactor.
    *
    * The Fiber parks through its own bridge; whatever it throws settles the
    * query as a local failure.
    */
   public function drive (Events & Loops & Scheduler $Reactor): void
   {
      $Bridge = static fn (mixed $value = null): mixed => Fiber::suspend($value);
      $Fiber = new Fiber(function () use ($Reactor, $Bridge): void {
         try {
            $this->execute($Reactor, $Bridge);
         }
         catch (Throwable) {
            $this->settle();
         }
      });

      // @ Run to its first suspension, then hand it to the reactor
      $value = $Fiber->start();
      if ($Fiber->isTerminated() === false) {
         $Reactor->schedule($Fiber, $value);
      }
   }

   /**
    * Run the whole exchange here: on a reactor through `$Wait` (inside a
    * Fiber), or blocking when either is `null`.
    *
    * @param null|Closure(mixed): mixed $Wait
    */
   public function execute (null|(Events & Loops & Scheduler) $Reactor, null|Closure $Wait): void
   {
      try {
         $results = $this->exchange($this->name, $this->types, $Reactor, $Wait);
         foreach ($this->types as $type) {
            $this->Answers[$type] = $this->build($type, $results[$type] ?? 'local', $Reactor, $Wait);
         }
      }
      finally {
         $this->settle();
      }
   }

   /**
    * Wait on the doorbell until the query settles or its deadline passes.
    *
    * @param Closure $Wait The caller's wait bridge (it parks the calling Fiber on a `Readiness`).
    *
    * @return bool Whether the query settled; `false` past the deadline, when the bridge stopped
    *    suspending, or when the reactor refused the doorbell.
    */
   public function await (Closure $Wait): bool
   {
      $this->waiters++;
      try {
         $stalled = 0;
         $fast = false;

         // @@ Park until settled
         while (true) {
            // ?: Settled — checked first on every wake
            if ($this->settled) {
               return true;
            }

            // ? Non-suspending-wait tripwire: 8 fast wakes in a row with nothing settled
            $stalled = $fast ? $stalled + 1 : 0;
            if ($stalled >= 8) {
               return false;
            }

            // ? Past the query's deadline
            $now = self::clock();
            if ($now >= $this->deadline) {
               return false;
            }

            // ! The doorbell: the driver closes its write end on settle
            if ($this->bell === null) {
               $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
               if (is_array($pair) === false) {
                  return false;
               }
               [$this->bell, $this->ring] = $pair;
            }
            $bell = $this->bell;

            // @ Park on the doorbell, at most one second
            $before = hrtime(true);
            try {
               // ! The reactor's deadlines are wall-clock: the monotonic slice is converted at the call
               $Wait(Readiness::read($bell, microtime(true) + min($this->deadline - $now, 1.0)));
            }
            catch (RuntimeException $Rejection) {
               // ? Only the selector's admission refusal is ours to absorb
               if (strpos($Rejection->getMessage(), 'selector admission') === false) {
                  throw $Rejection;
               }

               return false;
            }
            $fast = hrtime(true) - $before < 100_000;
         }
      }
      finally {
         $this->waiters--;
         // ! The last waiter out of a settled query closes the read end
         $this->close();
      }
   }

   /**
    * The resolver's monotonic clock, in seconds: deadlines, cache lifetimes and check intervals
    * never move with a wall-clock step.
    */
   public static function clock (): float
   {
      return hrtime(true) / 1_000_000_000;
   }

   /**
    * Close the doorbell's read end once the query settled and nobody waits on it.
    */
   private function close (): void
   {
      if ($this->settled && $this->waiters === 0 && is_resource($this->bell)) {
         fclose($this->bell);
         $this->bell = null;
      }
   }

   /**
    * Settle: fill every missing answer, store the outcome, ring the doorbell.
    */
   private function settle (): void
   {
      // ? Once
      if ($this->settled) {
         return;
      }

      // @ Store each final outcome — never a local failure, nor a SERVFAIL (a cached failure
      //   carries no reason, and the search list moves on past a SERVFAIL but stops at the others)
      $now = self::clock();
      foreach ($this->types as $type) {
         if (isSet($this->Answers[$type]) === false) {
            $this->Answers[$type] = new Answer(Outcomes::Failed, $this->name, [], self::FAILURE);
            $this->faults[$type] ??= 'local';
         }

         if (in_array($this->faults[$type] ?? null, ['local', 'SERVFAIL'], true)) {
            continue;
         }

         $this->Cache->store("{$this->scope}{$type}:{$this->name}", $this->Answers[$type], $now);
      }
      $this->settled = true;

      // @ Ring: every parked waiter wakes on the read end's EOF
      if (is_resource($this->ring)) {
         fclose($this->ring);
      }
      $this->ring = null;
      $this->close();
   }

   /**
    * Ask the nameservers in turn for `$types` of `$name`.
    *
    * @param list<int> $types
    * @param null|Closure(mixed): mixed $Wait
    *
    * @return array<int,Message|string> Each type's answer, or why it failed.
    */
   private function exchange (string $name, array $types, null|(Events & Loops & Scheduler) $Reactor, null|Closure $Wait): array
   {
      $results = [];
      $pending = [];
      foreach ($types as $type) {
         $pending[$type] = 'timeout';
      }
      // ! Nameservers that answered FORMERR to EDNS: asked without it
      $plain = [];
      $count = count($this->nameservers);
      $tries = $this->attempts * $count;
      $refusals = 0;

      // @@ One try per nameserver turn
      for ($try = 0; $try < $tries && $pending !== []; $try++) {
         $now = self::clock();
         if ($now >= $this->deadline) {
            break;
         }
         $limit = min($this->deadline, $now + $this->timeout);
         $index = ($this->start + $try) % $count;
         $nameserver = $this->nameservers[$index];

         $Client = new UDP_Client_CLI(UDP_Client_CLI::MODE_EMBEDDED);
         try {
            if ($Reactor !== null && $Wait !== null) {
               $Client->react($Reactor);
               $Client->schedule($Wait);
            }
            $Client->configure(new UDP_Configs(host: $nameserver, port: $this->port));
            $Socket = $Client->connect();
            if ($Socket === false) {
               continue;
            }
            $Flow = $Client->Connections->Flows[(int) $Socket];

            // @ Send every pending type
            /** @var array<int,array{int,int}> $waiting id => [type, size] */
            $waiting = [];
            foreach (array_keys($pending) as $type) {
               $fault = $this->send($Flow, $name, $type, isSet($plain[$index]) === false && $this->EDNS, $waiting);
               // ? A question that cannot be encoded fails here, for every type: no try would help
               if ($fault === 'local') {
                  foreach (array_keys($pending) as $pended) {
                     $pending[$pended] = 'local';
                  }
                  break 2;
               }
               // ? A write the socket refused — on a connected UDP socket, the pending ICMP error of
               //   an earlier datagram (no one listens there): a refusal, then the next try
               if ($fault !== null) {
                  $refusals++;
                  continue 2;
               }
            }

            // @@ Read until every type of this try is done
            while ($waiting !== []) {
               $remaining = $limit - self::clock();
               if ($remaining <= 0.0) {
                  break;
               }

               $Outcome = $Flow->receive($remaining);
               if ($Outcome === Waits::Expired) {
                  break;
               }
               if ($Outcome === Waits::Refused) {
                  $refusals++;
                  break;
               }
               if ($Outcome !== Waits::Received) {
                  // ? Closed, Stalled, Rejected: this process cannot wait here
                  foreach (array_keys($pending) as $type) {
                     $pending[$type] = 'local';
                  }
                  break 2;
               }

               // ! Our datagram, or noise to ignore
               $Message = $this->accept($Flow->input, $name, $waiting);
               if ($Message === null) {
                  continue;
               }
               [$type, ] = $waiting[$Message->id];
               unset($waiting[$Message->id]);

               // ? Truncated: the same question over TCP, once
               if ($Message->truncated) {
                  $TCP = $this->transfer($nameserver, $name, $type, $Reactor, $Wait);
                  if ($TCP instanceof Message) {
                     $results[$type] = $TCP;
                     unset($pending[$type]);
                  }
                  else if ($TCP === 'truncated') {
                     $results[$type] = $TCP;
                     unset($pending[$type]);
                  }
                  else {
                     $pending[$type] = $TCP;
                  }
                  continue;
               }

               switch ($Message->rcode) {
                  case DNS::RCODE_NOERROR:
                  case DNS::RCODE_NXDOMAIN:
                     $results[$type] = $Message;
                     unset($pending[$type]);
                     break;
                  case DNS::RCODE_FORMERR:
                     // ? An EDNS query this server rejects: once more without EDNS
                     if (isSet($plain[$index]) === false && $this->EDNS) {
                        $plain[$index] = true;
                        if ($this->send($Flow, $name, $type, false, $waiting) !== null) {
                           $pending[$type] = 'local';
                        }
                        break;
                     }
                     $pending[$type] = 'refused';
                     break;
                  case DNS::RCODE_SERVFAIL:
                     $pending[$type] = 'SERVFAIL';
                     break;
                  default:
                     $pending[$type] = 'refused';
               }
            }
         }
         finally {
            // ! Every flow of the try closes here, whatever happened
            try {
               $Client->stop();
            }
            catch (Throwable) {
               // ...
            }
         }
      }

      // :
      foreach ($pending as $type => $fault) {
         // ? Every try refused by ICMP: no nameserver is listening
         if ($fault === 'timeout' && $refusals > 0 && $refusals >= $tries) {
            $fault = 'unreachable';
         }
         $results[$type] = $fault;
      }

      return $results;
   }

   /**
    * Send one question on the flow.
    *
    * @param array<int,array{int,int}> $waiting Ids in flight: id => [type, advertised size].
    *
    * @return null|string `null` once sent; `local` when the question cannot be encoded, `write`
    *    when the socket refused it.
    */
   private function send (UDP_Client_CLI\Connections\Connection $Flow, string $name, int $type, bool $EDNS, array &$waiting): null|string
   {
      // ! An id not already in flight
      do {
         $id = random_int(0, 0xFFFF);
      } while (isSet($waiting[$id]));

      $bytes = DNS::encode(new Message(
         id: $id,
         recursion: true,
         size: $EDNS ? self::SIZE : null,
         Questions: [new Question($name, $type)]
      ));
      if ($bytes instanceof Faults) {
         return 'local';
      }

      $Socket = $Flow->Socket;
      $Flow->output = $bytes;
      if ($Flow->writing($Socket) === false) {
         return 'write';
      }

      $waiting[$id] = [$type, $EDNS ? self::SIZE : DNS::SIZE];

      return null;
   }

   /**
    * Read a datagram as the answer to a question in flight, or `null` for noise.
    *
    * @param array<int,array{int,int}> $waiting
    */
   private function accept (string $bytes, string $name, array $waiting): null|Message
   {
      // ? A header, and an id in flight
      $length = strlen($bytes);
      if ($length < 12) {
         return null;
      }
      $header = unpack('nid', $bytes);
      $id = is_array($header) ? (int) $header['id'] : -1;
      if (isSet($waiting[$id]) === false) {
         return null;
      }
      [$type, $size] = $waiting[$id];

      // ? No larger than this query advertised
      if ($length > $size) {
         return null;
      }

      // :
      return $this->check($bytes, $id, $name, $type);
   }

   /**
    * Decode and check a message: a response to a standard query whose one question is ours.
    */
   private function check (string $bytes, int $id, string $name, int $type): null|Message
   {
      $Message = DNS::decode($bytes);
      if ($Message instanceof Faults) {
         return null;
      }

      $Question = $Message->Questions[0] ?? null;
      if (
         $Message->id !== $id
         || $Message->response === false
         || $Message->opcode !== DNS::OPCODE_QUERY
         || count($Message->Questions) !== 1
         || $Question === null
         || strcasecmp(rtrim($Question->name, '.'), $name) !== 0
         || $Question->type !== $type
         || $Question->class !== DNS::CLASS_IN
      ) {
         return null;
      }

      return $Message;
   }

   /**
    * Ask one question over TCP (after a truncated UDP answer).
    *
    * @param null|Closure(mixed): mixed $Wait
    *
    * @return Message|string The answer, or why it failed (`truncated` when the TCP answer is
    *    truncated too).
    */
   private function transfer (string $nameserver, string $name, int $type, null|(Events & Loops & Scheduler) $Reactor, null|Closure $Wait): Message|string
   {
      $now = self::clock();
      $limit = min($this->deadline, $now + $this->timeout);
      if ($limit <= $now) {
         return 'timeout';
      }

      $id = random_int(0, 0xFFFF);
      $bytes = DNS::encode(new Message(id: $id, recursion: true, Questions: [new Question($name, $type)]));
      if ($bytes instanceof Faults) {
         return 'local';
      }

      $parking = $Reactor !== null && $Wait !== null && Fiber::getCurrent() !== null;
      $Client = new TCP_Client_CLI(TCP_Client_CLI::MODE_EMBEDDED);
      $Socket = false;
      try {
         if ($parking) {
            $Client->react($Reactor);
            $Client->schedule($Wait);
         }
         $host = str_contains($nameserver, ':') ? "[{$nameserver}]" : $nameserver;
         $Client->configure(new TCP_Configs(host: $host, port: $this->port));
         $Client->connectTimeout = max(0.001, $limit - self::clock());
         $Socket = $Client->connect();
         if ($Socket === false || isSet($Client->Connections->Connections[(int) $Socket]) === false) {
            return 'timeout';
         }
         stream_set_blocking($Socket, false);

         // @@ Write the length-prefixed message
         $frame = pack('n', strlen($bytes)) . $bytes;
         $stalled = 0;
         while ($frame !== '') {
            $written = @fwrite($Socket, $frame);
            if ($written === false) {
               return 'timeout';
            }
            // ! A question (at most a few hundred bytes) fits a fresh connection's send buffer: a
            //   write wake here is one that moved nothing, so the tripwire counts it
            $frame = substr($frame, $written);
            if ($frame !== '' && $this->park($Socket, Scheduler::SCHEDULE_WRITE, $limit, $parking ? $Wait : null, $stalled) === false) {
               return 'timeout';
            }
         }

         // @@ Read exactly one length-prefixed message
         $buffer = '';
         $length = null;
         while ($length === null || strlen($buffer) < $length + 2) {
            $chunk = @fread($Socket, max(1, 65537 - strlen($buffer)));
            if ($chunk === false) {
               return 'timeout';
            }
            if ($chunk === '') {
               if (feof($Socket)) {
                  return 'timeout';
               }
               if ($this->park($Socket, Scheduler::SCHEDULE_READ, $limit, $parking ? $Wait : null, $stalled) === false) {
                  return 'timeout';
               }
               continue;
            }
            $buffer .= $chunk;
            $stalled = 0;

            if ($length === null && strlen($buffer) >= 2) {
               $prefix = unpack('nlength', $buffer);
               $length = is_array($prefix) ? (int) $prefix['length'] : 0;
               if ($length < 12) {
                  return 'SERVFAIL';
               }
            }
         }

         // ? The answer to our question, or a failure
         $Message = $this->check(substr($buffer, 2, $length), $id, $name, $type);
         if ($Message === null) {
            return 'SERVFAIL';
         }
         if ($Message->truncated) {
            return 'truncated';
         }

         // :
         return match ($Message->rcode) {
            DNS::RCODE_NOERROR, DNS::RCODE_NXDOMAIN => $Message,
            DNS::RCODE_SERVFAIL => 'SERVFAIL',
            default => 'refused',
         };
      }
      finally {
         // ! Closed through the client's connections (its stop() is not for embedded use)
         if (is_resource($Socket)) {
            if (isSet($Client->Connections->Connections[(int) $Socket])) {
               $Client->Connections->close($Socket);
            }
            else {
               @fclose($Socket);
            }
         }
      }
   }

   /**
    * Wait until a TCP socket is ready, through the bridge or blocking, bounded by `$limit`.
    *
    * @param resource $Socket
    * @param null|Closure(mixed): mixed $Wait
    */
   private function park ($Socket, int $flag, float $limit, null|Closure $Wait, int &$stalled): bool
   {
      $now = self::clock();
      if ($now >= $limit) {
         return false;
      }

      // ? Blocking: one select over what is left
      if ($Wait === null) {
         $read = $flag === Scheduler::SCHEDULE_READ ? [$Socket] : [];
         $write = $flag === Scheduler::SCHEDULE_WRITE ? [$Socket] : [];
         $except = null;
         $remaining = $limit - $now;
         $seconds = (int) $remaining;

         return @stream_select($read, $write, $except, $seconds, (int) (($remaining - $seconds) * 1_000_000)) === 1;
      }

      // @ Park through the bridge, in slices of at most one second
      $before = hrtime(true);
      $Wait(new Readiness($Socket, $flag, microtime(true) + min($limit - $now, 1.0)));

      // ? Non-suspending-wait tripwire
      if (hrtime(true) - $before < 100_000) {
         return ++$stalled < 8;
      }
      $stalled = 0;

      return true;
   }

   /**
    * Turn a type's result into its answer: follow the CNAME chain, read the TTL.
    *
    * @param null|Closure(mixed): mixed $Wait
    */
   private function build (int $type, Message|string $result, null|(Events & Loops & Scheduler) $Reactor, null|Closure $Wait): Answer
   {
      // ? A failure
      if (is_string($result)) {
         $this->faults[$type] = $result;

         return new Answer(Outcomes::Failed, $this->name, [], self::FAILURE);
      }

      $Message = $result;
      $canonical = $this->name;
      $TTL = 3600;
      $hops = 0;
      $seen = [$canonical => true];

      // @@ Follow the chain
      while (true) {
         $Records = [];
         $targets = [];
         foreach ($Message->Answers as $Record) {
            if ($Record->class !== DNS::CLASS_IN || strcasecmp(rtrim($Record->name, '.'), $canonical) !== 0) {
               continue;
            }
            if ($Record->type === $type) {
               $Records[] = $Record;
            }
            else if ($Record->type === DNS::TYPE_CNAME && is_string($Record->data)) {
               $targets[strtolower(rtrim($Record->data, '.'))] = $Record->TTL;
            }
         }

         // ?: Records of the type
         if ($Records !== []) {
            foreach ($Records as $Record) {
               $TTL = min($TTL, $Record->TTL);
            }

            return new Answer(Outcomes::Answered, $canonical, $Records, max(5, min($TTL, 3600)));
         }

         // ? One CNAME per owner, a bounded chain, no loop
         if (count($targets) > 1) {
            return $this->fail($type, 'broken');
         }
         if (count($targets) === 1) {
            $target = (string) array_keys($targets)[0];
            if (++$hops > self::HOPS || isSet($seen[$target])) {
               return $this->fail($type, 'broken');
            }
            $seen[$target] = true;
            $TTL = min($TTL, $targets[$target]);
            $canonical = $target;
            continue;
         }

         // ?: NXDOMAIN — the end of the chain does not exist
         if ($Message->rcode === DNS::RCODE_NXDOMAIN) {
            return new Answer(Outcomes::Absent, $canonical, [], $this->reckon($Message, $canonical));
         }

         // ? A chain that ended without data and without a negative proof: ask its end, DNS only
         if ($hops > 0 && $this->prove($Message, $canonical) === false) {
            if (++$hops > self::HOPS) {
               return $this->fail($type, 'broken');
            }
            $again = $this->exchange($canonical, [$type], $Reactor, $Wait)[$type] ?? 'local';
            if (is_string($again)) {
               return $this->fail($type, $again);
            }
            $Message = $again;
            continue;
         }

         // :
         return new Answer(Outcomes::Empty, $canonical, [], $this->reckon($Message, $canonical));
      }
   }

   /**
    * A failed answer, with its reason.
    */
   private function fail (int $type, string $fault): Answer
   {
      $this->faults[$type] = $fault;

      return new Answer(Outcomes::Failed, $this->name, [], self::FAILURE);
   }

   /**
    * The SOA record of the authority section that covers `$name`, if any.
    */
   private function find (Message $Message, string $name): null|Record
   {
      foreach ($Message->Authorities as $Record) {
         $owner = strtolower(rtrim($Record->name, '.'));
         if (
            $Record->type === DNS::TYPE_SOA
            && is_array($Record->data)
            && ($owner === '' || $owner === $name || str_ends_with($name, ".{$owner}"))
         ) {
            return $Record;
         }
      }

      return null;
   }

   /**
    * Whether the message proves the absence of data at `$name` (an SOA that covers it).
    */
   private function prove (Message $Message, string $name): bool
   {
      return $this->find($Message, $name) !== null;
   }

   /**
    * The negative-answer TTL (RFC 2308): the covering SOA's TTL and minimum, clamped 5–60 s.
    */
   private function reckon (Message $Message, string $name): int
   {
      $SOA = $this->find($Message, $name);
      if ($SOA === null || is_array($SOA->data) === false) {
         return self::FAILURE;
      }

      $minimum = (int) ($SOA->data['minimum'] ?? 0);

      return max(5, min($SOA->TTL, $minimum, 60));
   }
}
