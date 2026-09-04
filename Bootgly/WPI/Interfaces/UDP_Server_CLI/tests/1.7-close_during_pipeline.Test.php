<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Events\Timer;
use Bootgly\ACI\Events\Timer\Registry as TimerRegistry;
use Bootgly\ACI\Events\Timer\Reset as TimerReset;
use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Endpoints\Servers\Decoder as ServerDecoder;
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Endpoints\Servers\Encoder as ServerEncoder;
use Bootgly\WPI\Endpoints\Servers\Packages as ServerPackages;
use Bootgly\WPI\Interfaces\UDP_Server_CLI;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Configs;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections\Connection\Authority as ConnectionAuthority;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections\Connection\Lease;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Packages as UDP_Packages;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Router;


final class H7CloseDecoder implements ServerDecoder
{
   /** Number of decode calls observed. */
   public static int $calls = 0;
   /** Status observed through the public owner after close(). */
   public static int $status = 0;


   /** {@inheritDoc} */
   public function decode (
      ServerPackages $Package, string $buffer, int $size
   ): States
   {
      self::$calls++;
      if ($Package instanceof UDP_Packages) {
         $Connection = $Package->Connection;
         $Connection->close();
         self::$status = $Connection->status;
      }

      return States::Complete;
   }
}

final class H7GetterDecoder implements ServerDecoder
{
   /** Number of decode calls made after the owner getter closes its peer. */
   public static int $calls = 0;


   /** {@inheritDoc} */
   public function decode (
      ServerPackages $Package, string $buffer, int $size
   ): States
   {
      self::$calls++;

      return States::Complete;
   }
}

final class H7DecoderGetterConnection extends Connection
{
   // * Data
   private null|ServerDecoder $Endpoint = null;

   /** Close the concrete peer while Router resolves its virtual decoder. */
   public null|ServerDecoder $Decoder {
      get {
         $this->close();

         return $this->Endpoint;
      }
      set (null|ServerDecoder $Decoder) {
         $this->Endpoint = $Decoder;
      }
   }
}

final class H7RouterConnections extends Connections
{
   // * Data
   private Connection $Fixture;


   /** @param Connection $Fixture Exact Connection returned to the Router. */
   public function __construct (Connection $Fixture)
   {
      $this->Fixture = $Fixture;
   }

   /** Return the adversarial Router fixture without admitting another peer. */
   public function accept (string $peer): null|Connection
   {
      return $this->Fixture;
   }
}

final class H7CloseEncoder implements ServerEncoder
{
   /** Connection closed by the encoder fixture. */
   public static null|Connection $Target = null;
   /** Number of encode calls observed. */
   public static int $calls = 0;
   /** Restore the legacy public status after closing the target. */
   public static bool $resurrect = false;


   /** {@inheritDoc} */
   public static function encode (
      ServerPackages $Package, null|int &$length
   ): string
   {
      self::$calls++;
      self::$Target?->close();
      if (self::$resurrect && self::$Target instanceof Connection) {
         self::$Target->status = Connections::STATUS_ESTABLISHED;
      }
      $length = 4;

      return 'late';
   }
}

final class H7ThrowingDecoder implements ServerDecoder
{
   /** {@inheritDoc} */
   public function decode (
      ServerPackages $Package, string $buffer, int $size
   ): States
   {
      return States::Incomplete;
   }

   /** Throw when terminal cleanup releases this adversarial owner. */
   public function __destruct ()
   {
      throw new RuntimeException('adversarial decoder destructor');
   }
}

final class H7ReentrantOwner
{
   // * Data
   private Connection $Connection;


   /** @param Connection $Connection Peer mutated during owner destruction. */
   public function __construct (Connection $Connection)
   {
      $this->Connection = $Connection;
   }

   /** Attempt to resurrect heavy and terminal peer state. */
   public function __destruct ()
   {
      $this->Connection->input = str_repeat('resurrect', 8_000);
      $this->Connection->decoded = new H7ChainedOwner($this->Connection, 7);
      $this->Connection->status = Connections::STATUS_ESTABLISHED;
      Connections::$Connections[$this->Connection->id] = $this->Connection;
      $timer = Timer::add(30, static function (): void {});
      if ($timer !== false) {
         $this->Connection->timers[] = $timer;
      }

      throw new RuntimeException('adversarial re-entrant owner destructor');
   }
}

final class H7ChainedOwner
{
   /** Number of owner destructors completed. */
   public static int $destructions = 0;
   /** Clear the process timer wheel during each destructor when enabled. */
   public static bool $clearTimers = false;

   // * Data
   private Connection $Connection;
   private int $depth;
   private string $payload;


   /**
    * @param Connection $Connection Peer mutated during chained destruction.
    * @param int $depth Remaining destructor chain depth.
    */
   public function __construct (Connection $Connection, int $depth)
   {
      $this->Connection = $Connection;
      $this->depth = $depth;
      $this->payload = str_repeat('P', 65_536);
   }

   /** Repopulate core fields at each bounded cleanup pass. */
   public function __destruct ()
   {
      self::$destructions++;
      if (self::$clearTimers) {
         Timer::del();
      }
      $this->Connection->input = str_repeat('chain', 13_000);
      $this->Connection->output = str_repeat('chain', 13_000);
      $this->Connection->known = str_repeat('K', 2_048);
      if ($this->depth > 1) {
         $this->Connection->decoded = new self(
            $this->Connection,
            $this->depth - 1,
         );
      }
   }
}

final class H7CyclicOwner
{
   /** Number of deferred cyclic destructors observed. */
   public static int $destructions = 0;

   // * Data
   private Connection $Connection;
   private self $Cycle;


   /** @param Connection $Connection Peer targeted after deferred destruction. */
   public function __construct (Connection $Connection)
   {
      $this->Connection = $Connection;
      $this->Cycle = $this;
   }

   /** Attempt to restore retained state when cyclic GC releases this owner. */
   public function __destruct ()
   {
      self::$destructions++;
      $this->Connection->input = str_repeat('cyclic-owner', 6_000);
      $this->Connection->decoded = new stdClass;
      Connections::$Connections[$this->Connection->id] = $this->Connection;
      $timer = Timer::add(30, static function (): void {});
      if ($timer !== false) {
         $this->Connection->timers[] = $timer;
      }
   }
}

final class H7TerminalDestructor
{
   /** Results of terminal I/O attempted during destruction. */
   public static array $results = [];

   // * Data
   /** @var resource */
   private $Socket;
   private Connection $Connection;


   /**
    * @param resource $Socket Shared UDP socket used by the attempted write.
    * @param Connection $Connection Closed peer targeted during destruction.
    */
   public function __construct ($Socket, Connection $Connection)
   {
      $this->Socket = $Socket;
      $this->Connection = $Connection;
   }

   /** Attempt terminal I/O after application code restores public status. */
   public function __destruct ()
   {
      self::$results[] = $this->Connection->writing($this->Socket, 1, 'D');
   }
}

final class H7OwnerRebindConnection extends Connection
{
   // * Data
   private Connection $Owner;
   private bool $armed = false;

   /** Hooked compatibility owner used to close this object during resolution. */
   public Connection $Connection {
      get {
         $Owner = $this->Owner;
         if ($this->armed) {
            $this->armed = false;
            $this->close();
         }

         return $Owner;
      }
      set (Connection $Connection) {
         $this->Owner = $Connection;
      }
   }


   /** Return another owner after closing this concrete Connection. */
   public function arm (Connection $Replacement): void
   {
      $this->Owner = $Replacement;
      $this->armed = true;
   }
}

final class H7RejectingEncoder implements ServerEncoder
{
   /** Number of encode callbacks observed. */
   public static int $calls = 0;


   /** {@inheritDoc} */
   public static function encode (
      ServerPackages $Package, null|int &$length
   ): string
   {
      self::$calls++;
      if ($Package instanceof UDP_Packages) {
         $Package->reject('owner-rebind');
      }
      $length = 4;

      return 'late';
   }
}

final class H7EncoderOwnerRebindConnection extends Connection
{
   // * Data
   private null|ServerEncoder $Endpoint = null;
   private Connection $Replacement;

   /** Rebind the public owner while write() resolves its virtual encoder. */
   public null|ServerEncoder $Encoder {
      get {
         $this->Connection = $this->Replacement;

         return $this->Endpoint;
      }
      set (null|ServerEncoder $Encoder) {
         $this->Endpoint = $Encoder;
      }
   }


   /**
    * @param resource $Socket Shared UDP server socket.
    * @param string $peer Peer address used by both adversarial owners.
    * @param Connection $Replacement Owner installed during encoder resolution.
    */
   public function __construct ($Socket, string $peer, Connection $Replacement)
   {
      $this->Replacement = $Replacement;

      parent::__construct($Socket, $peer);
   }
}

final class H7SweepOwner
{
   /** Replacement admitted while the outer sweep still owns a stale snapshot. */
   public static null|Connection $Replacement = null;

   // * Data
   private Connections $Connections;
   private Connection $Target;
   private string $peer;


   /**
    * @param Connections $Connections Admission controller re-entered on destruction.
    * @param Connection $Target Peer replaced inside the outer sweep.
    * @param string $peer Same-key replacement address.
    */
   public function __construct (
      Connections $Connections, Connection $Target, string $peer
   )
   {
      $this->Connections = $Connections;
      $this->Target = $Target;
      $this->peer = $peer;
   }

   /** Close and replace another peer while sweep() holds its old generation. */
   public function __destruct ()
   {
      $this->Target->close();
      self::$Replacement = $this->Connections->accept($this->peer);
   }
}


return new Test(
   description: 'UDP pipeline callbacks may close a peer without a late write',
   test: new Assertions(Case: function (): Generator {
      $segments = Display::$segments;
      $PreviousDecoder = UDP_Server_CLI::$Decoder;
      $PreviousEncoder = UDP_Server_CLI::$Encoder;
      $PreviousAlarm = pcntl_signal_get_handler(SIGALRM);
      Timer::init(static function (): void {});
      $ServerSocket = null;
      $SourceSocket = null;
      Display::show(Display::NONE);
      Timer::del();

      try {
         $Server = new UDP_Server_CLI(Modes::Test);
         $Server->configure(new Configs(
            host: '127.0.0.1',
            port: 19993,
            workers: 1,
         ));

         $ServerSocket = stream_socket_server(
            'udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND
         );
         yield new Assertion(description: 'pipeline UDP server socket is bound')
            ->expect($ServerSocket !== false)
            ->to->be(true)
            ->assert();
         if ($ServerSocket === false) {
            return;
         }
         stream_set_blocking($ServerSocket, false);

         $target = stream_socket_get_name($ServerSocket, false);
         if (is_string($target) === false) {
            throw new RuntimeException('Could not identify the pipeline UDP target.');
         }
         $peer = '';
         for ($attempt = 0; $attempt < 256; $attempt++) {
            $SourceSocket = stream_socket_server(
               'udp://127.0.0.1:0',
               $sourceCode,
               $sourceMessage,
               STREAM_SERVER_BIND,
            );
            if ($SourceSocket === false) {
               break;
            }
            $peer = (string) stream_socket_get_name($SourceSocket, false);
            if ($peer !== '' && $peer !== $target) {
               break;
            }
            fclose($SourceSocket);
            $SourceSocket = null;
         }
         yield new Assertion(description: 'pipeline UDP source is distinct from target')
            ->expect(is_resource($SourceSocket) && $peer !== '' && $peer !== $target)
            ->to->be(true)
            ->assert();
         if (is_resource($SourceSocket) === false || $peer === '' || $peer === $target) {
            return;
         }
         stream_set_blocking($SourceSocket, false);

         $SocketProperty = new ReflectionProperty($Server, 'Socket');
         $SocketProperty->setValue($Server, $ServerSocket);
         $Connections = $Server->Connections;
         $Peers = new ReflectionProperty(Connections::class, 'Peers');
         $IPs = new ReflectionProperty(Connections::class, 'IPConnections');

         H7CloseDecoder::$calls = 0;
         H7CloseDecoder::$status = 0;
         H7CloseEncoder::$calls = 0;
         UDP_Server_CLI::$Decoder = new H7CloseDecoder;
         UDP_Server_CLI::$Encoder = new H7CloseEncoder;
         $payload = 'decode-close';
         $sent = stream_socket_sendto($SourceSocket, $payload, 0, $target);
         $read = [$ServerSocket];
         $write = null;
         $except = null;
         $selected = stream_select($read, $write, $except, 0, 200_000);

         $decoderThrown = '';
         if ($selected === 1) {
            try {
               $Connections->Router->reading($ServerSocket);
            }
            catch (Throwable $Throwable) {
               $class = $Throwable::class;
               $message = $Throwable->getMessage();
               $decoderThrown = "{$class}: {$message}";
            }
         }

         yield new Assertion(description: 'decoder close suppresses the completed late write')
            ->expect(
               [
                  $sent,
                  $selected,
                  H7CloseDecoder::$calls,
                  H7CloseDecoder::$status,
                  $decoderThrown,
                  H7CloseEncoder::$calls,
                  Connections::$Connections,
               ],
               Op::Identical,
               [
                  strlen($payload),
                  1,
                  1,
                  Connections::STATUS_CLOSED,
                  '',
                  0,
                  [],
               ],
            )
            ->assert();

         H7GetterDecoder::$calls = 0;
         H7CloseEncoder::$calls = 0;
         $GetterConnection = new H7DecoderGetterConnection($ServerSocket, $peer);
         $GetterConnection->Decoder = new H7GetterDecoder;
         $GetterConnections = new H7RouterConnections($GetterConnection);
         $GetterRouter = new Router(
            $Server,
            $GetterConnections,
            1,
         );
         UDP_Server_CLI::$Decoder = new H7GetterDecoder;
         $getterPayload = 'decoder-getter-close';
         $getterSent = stream_socket_sendto(
            $SourceSocket,
            $getterPayload,
            0,
            $target,
         );
         $read = [$ServerSocket];
         $write = null;
         $except = null;
         $getterSelected = stream_select($read, $write, $except, 0, 200_000);
         $getterThrown = '';
         if ($getterSelected === 1) {
            try {
               $GetterRouter->reading($ServerSocket);
            }
            catch (Throwable $Throwable) {
               $class = $Throwable::class;
               $message = $Throwable->getMessage();
               $getterThrown = "{$class}: {$message}";
            }
         }
         yield new Assertion(description: 'decoder getter close is revalidated before decode')
            ->expect(
               [
                  $getterSent,
                  $getterSelected,
                  $GetterConnection->status,
                  ConnectionAuthority::check($GetterConnection),
                  H7GetterDecoder::$calls,
                  H7CloseEncoder::$calls,
                  $getterThrown,
               ],
               Op::Identical,
               [
                  strlen($getterPayload),
                  1,
                  Connections::STATUS_CLOSED,
                  false,
                  0,
                  0,
                  '',
               ],
            )
            ->assert();
         unset($GetterRouter, $GetterConnections, $GetterConnection);

         UDP_Server_CLI::$Decoder = null;
         UDP_Server_CLI::$Encoder = null;
         $Connection = $Connections->accept($peer);
         yield new Assertion(description: 'encoder-close control peer is admitted')
            ->expect($Connection instanceof Connection)
            ->to->be(true)
            ->assert();
         if ($Connection instanceof Connection === false) {
            return;
         }

         $Weak = WeakReference::create($Connection);
         $Connection->Encoder = new H7CloseEncoder;
         H7CloseEncoder::$calls = 0;
         H7CloseEncoder::$Target = $Connection;
         H7CloseEncoder::$resurrect = true;
         $encoderThrown = '';
         $written = null;
         try {
            $written = $Connection->write($ServerSocket);
         }
         catch (Throwable $Throwable) {
            $class = $Throwable::class;
            $message = $Throwable->getMessage();
            $encoderThrown = "{$class}: {$message}";
         }
         H7CloseEncoder::$Target = null;
         H7CloseEncoder::$resurrect = false;
         $encoderCalls = H7CloseEncoder::$calls;
         H7CloseEncoder::$calls = 0;
         $Wrapper = new class($Connection) extends UDP_Packages {};
         $Wrapper->Encoder = new H7CloseEncoder;
         $wrapperLate = $Wrapper->write($ServerSocket);
         $wrapperCalls = H7CloseEncoder::$calls;
         unset($Wrapper);
         $Connection->Encoder = new H7CloseEncoder;
         H7CloseEncoder::$calls = 0;
         $lateThrown = '';
         $late = [];
         try {
            $late = [
               $Connection->write($ServerSocket),
               $Connection->writing($ServerSocket, 1, 'x'),
            ];
            $Connection->reject('late');
         }
         catch (Throwable $Throwable) {
            $class = $Throwable::class;
            $message = $Throwable->getMessage();
            $lateThrown = "{$class}: {$message}";
         }
         $lateEncoderCalls = H7CloseEncoder::$calls;
         H7TerminalDestructor::$results = [];
         $TerminalDestructor = new H7TerminalDestructor($ServerSocket, $Connection);
         unset($TerminalDestructor);
         $terminalDestructorResults = H7TerminalDestructor::$results;

         $read = [$SourceSocket];
         $write = null;
         $except = null;
         $terminalSelected = stream_select($read, $write, $except, 0, 200_000);
         $terminalDatagrams = [];
         if ($terminalSelected === 1) {
            while (true) {
               $terminalDatagram = @stream_socket_recvfrom($SourceSocket, 65_535);
               if ($terminalDatagram === false || $terminalDatagram === '') {
                  break;
               }
               $terminalDatagrams[] = $terminalDatagram;
            }
         }

         $Tasks = new ReflectionProperty(Timer::class, 'tasks');
         $terminalTimer = Timer::add(30, static function (): void {});
         if ($terminalTimer !== false) {
            $Connection->timers[] = $terminalTimer;
         }
         $Connection->input = 'terminal-input';
         $Connection->output = 'terminal-output';
         $Connection->known = 'terminal-known';
         Connections::$Connections[$peer] = $Connection;
         $terminalReclosed = $Connection->close();
         $terminalTaskCount = 0;
         foreach ($Tasks->getValue() as $tasks) {
            $terminalTaskCount += count($tasks);
         }
         $terminalPeerCount = count($Peers->getValue());
         $terminalIPs = $IPs->getValue();
         unset($Connection);
         gc_collect_cycles();
         Lease::drain();
         $terminalReleasedTaskCount = 0;
         foreach ($Tasks->getValue() as $tasks) {
            $terminalReleasedTaskCount += count($tasks);
         }

         yield new Assertion(description: 'encoder close releases peer without writing')
            ->expect(
               [
                  $encoderThrown,
                  $written,
                  $encoderCalls,
                  $wrapperLate,
                  $wrapperCalls,
                  $lateThrown,
                  $late,
                  $lateEncoderCalls,
                  $terminalDestructorResults,
                  $terminalSelected,
                  $terminalDatagrams,
                  $terminalTimer !== false,
                  $terminalReclosed,
                  $terminalTaskCount,
                  $terminalPeerCount,
                  $terminalIPs,
                  $terminalReleasedTaskCount,
                  Connections::$Connections,
                  $Weak->get(),
               ],
               Op::Identical,
               [
                  '',
                  false,
                  1,
                  false,
                  0,
                  '',
                  [false, false],
                  0,
                  [false],
                  0,
                  [],
                  true,
                  true,
                  1,
                  1,
                  ['127.0.0.1' => 1],
                  0,
                  [],
                  null,
               ],
            )
            ->assert();

         $PeerHook = new class($ServerSocket, $peer) extends Connection {
            // * Data
            public int $gets = 0;
            private string $target = '';

            /** Close during destination resolution, then return the old target. */
            public string $peer {
               get {
                  $this->gets++;
                  $this->close();

                  return $this->target;
               }
               set (string $peer) {
                  $this->target = $peer;
               }
            }
         };
         $peerHookWrite = $PeerHook->writing($ServerSocket, 1, 'P');
         $read = [$SourceSocket];
         $write = null;
         $except = null;
         $peerHookSelected = stream_select($read, $write, $except, 0, 200_000);
         $peerHookDatagram = $peerHookSelected === 1
            ? stream_socket_recvfrom($SourceSocket, 1)
            : false;
         yield new Assertion(description: 'immutable destination bypasses mutable peer hook')
            ->expect(
               [
                  $peerHookWrite,
                  $PeerHook->gets,
                  $PeerHook->status,
                  $peerHookSelected,
                  $peerHookDatagram,
               ],
               Op::Identical,
               [true, 0, Connections::STATUS_ESTABLISHED, 1, 'P'],
            )
            ->assert();
         $PeerHook->close();
         unset($PeerHook);
         gc_collect_cycles();

         $RebindOwner = new Connection($ServerSocket, $peer);
         $WritingRebind = new H7OwnerRebindConnection($ServerSocket, $peer);
         $WritingRebind->arm($RebindOwner);
         $rebindWriting = $WritingRebind->writing($ServerSocket, 1, 'W');
         $RejectRebind = new H7OwnerRebindConnection($ServerSocket, $peer);
         $RejectRebind->arm($RebindOwner);
         $RejectRebind->reject('R');
         $FailRebind = new H7OwnerRebindConnection($ServerSocket, $peer);
         $FailRebind->arm($RebindOwner);
         $errorsBeforeRebind = Connections::$errors;
         $rebindFail = $FailRebind->fail(null, 'read');
         $read = [$SourceSocket];
         $write = null;
         $except = null;
         $rebindSelected = stream_select($read, $write, $except, 0, 200_000);
         $rebindDatagrams = [];
         if ($rebindSelected === 1) {
            while (true) {
               $rebindDatagram = @stream_socket_recvfrom($SourceSocket, 65_535);
               if ($rebindDatagram === false || $rebindDatagram === '') {
                  break;
               }
               $rebindDatagrams[] = $rebindDatagram;
            }
         }
         sort($rebindDatagrams);
         $rebindState = [
            $rebindWriting,
            $rebindFail,
            $WritingRebind->status,
            $RejectRebind->status,
            $FailRebind->status,
            $RebindOwner->status,
            Connections::$errors === $errorsBeforeRebind,
            $rebindSelected,
            $rebindDatagrams,
         ];
         $WritingRebind->close();
         $RejectRebind->close();
         $FailRebind->close();
         $RebindOwner->close();
         unset($WritingRebind, $RejectRebind, $FailRebind, $RebindOwner);
         gc_collect_cycles();
         yield new Assertion(description: 'concrete connection authority ignores owner rebind')
            ->expect(
               $rebindState,
               Op::Identical,
               [
                  true,
                  true,
                  Connections::STATUS_ESTABLISHED,
                  Connections::STATUS_CLOSED,
                  Connections::STATUS_CLOSED,
                  Connections::STATUS_ESTABLISHED,
                  false,
                  1,
                  ['R', 'W'],
               ],
            )
            ->assert();

         H7RejectingEncoder::$calls = 0;
         $EncoderOwnerB = new Connection($ServerSocket, $peer);
         $EncoderOwnerA = new H7EncoderOwnerRebindConnection(
            $ServerSocket,
            $peer,
            $EncoderOwnerB,
         );
         $EncoderOwnerA->Encoder = new H7RejectingEncoder;
         $encoderRebindThrown = '';
         $encoderRebindWrite = null;
         try {
            $encoderRebindWrite = $EncoderOwnerA->write($ServerSocket);
         }
         catch (Throwable $Throwable) {
            $class = $Throwable::class;
            $message = $Throwable->getMessage();
            $encoderRebindThrown = "{$class}: {$message}";
         }
         $read = [$SourceSocket];
         $write = null;
         $except = null;
         $encoderRebindSelected = stream_select(
            $read,
            $write,
            $except,
            0,
            200_000,
         );
         $encoderRebindDatagrams = [];
         if ($encoderRebindSelected === 1) {
            while (true) {
               $encoderRebindDatagram = @stream_socket_recvfrom($SourceSocket, 65_535);
               if (
                  $encoderRebindDatagram === false
                  || $encoderRebindDatagram === ''
               ) {
                  break;
               }
               $encoderRebindDatagrams[] = $encoderRebindDatagram;
            }
         }
         $OwnerProperty = new ReflectionProperty($EncoderOwnerA, 'Connection');
         $encoderRebindState = [
            $encoderRebindThrown,
            $encoderRebindWrite,
            H7RejectingEncoder::$calls,
            $EncoderOwnerA->status,
            $EncoderOwnerB->status,
            ConnectionAuthority::check($EncoderOwnerA),
            ConnectionAuthority::check($EncoderOwnerB),
            $OwnerProperty->isInitialized($EncoderOwnerA),
            $encoderRebindSelected,
            $encoderRebindDatagrams,
         ];
         $encoderCleanupThrown = [];
         try {
            $EncoderOwnerB->close();
         }
         catch (Throwable $Throwable) {
            $class = $Throwable::class;
            $message = $Throwable->getMessage();
            $encoderCleanupThrown[] = "B: {$class}: {$message}";
         }
         try {
            $EncoderOwnerA->close();
         }
         catch (Throwable $Throwable) {
            $class = $Throwable::class;
            $message = $Throwable->getMessage();
            $encoderCleanupThrown[] = "A: {$class}: {$message}";
         }
         $encoderRebindState[] = $encoderCleanupThrown;
         unset($EncoderOwnerA, $EncoderOwnerB);
         gc_collect_cycles();
         yield new Assertion(description: 'encoder callback remains bound to concrete connection')
            ->expect(
               $encoderRebindState,
               Op::Identical,
               [
                  '',
                  false,
                  1,
                  Connections::STATUS_CLOSED,
                  Connections::STATUS_ESTABLISHED,
                  false,
                  true,
                  false,
                  1,
                  ['owner-rebind'],
                  [],
               ],
            )
            ->assert();

         $MissingSocket = new Connection($ServerSocket, '127.0.0.8:53007');
         unset($MissingSocket->Socket);
         $MissingSocket->reject('missing-socket');
         $MissingPeer = new Connection($ServerSocket, '127.0.0.8:53008');
         unset($MissingPeer->peer);
         $MissingPeer->reject('missing-peer');
         yield new Assertion(description: 'reject closes after destination resolution failure')
            ->expect(
               [$MissingSocket->status, $MissingPeer->status],
               Op::Identical,
               [Connections::STATUS_CLOSED, Connections::STATUS_CLOSED],
            )
            ->assert();
         unset($MissingSocket, $MissingPeer);
         gc_collect_cycles();

         $Adversarial = $Connections->accept('127.0.0.2:53006');
         yield new Assertion(description: 'adversarial-owner control peer is admitted')
            ->expect($Adversarial instanceof Connection)
            ->to->be(true)
            ->assert();
         if ($Adversarial instanceof Connection === false) {
            return;
         }
         $Adversarial->Decoder = new H7ThrowingDecoder;
         $Adversarial->decoded = new H7ReentrantOwner($Adversarial);
         $AdversarialWeak = WeakReference::create($Adversarial);
         $Tasks = new ReflectionProperty(Timer::class, 'tasks');
         $adversarialThrown = '';
         $adversarialClosed = false;
         try {
            $adversarialClosed = $Adversarial->close();
         }
         catch (Throwable $Throwable) {
            $class = $Throwable::class;
            $message = $Throwable->getMessage();
            $adversarialThrown = "{$class}: {$message}";
         }
         $adversarialState = [
            $Adversarial->status,
            $Adversarial->input,
            $Adversarial->decoded,
            $Adversarial->timers,
            Connections::$Connections,
            count(TimerRegistry::snapshot()),
            count($Peers->getValue()),
            $IPs->getValue(),
         ];
         unset($Adversarial);
         gc_collect_cycles();
         Lease::drain();
         $adversarialReleased = [
            $AdversarialWeak->get(),
            count($Peers->getValue()),
            $IPs->getValue(),
            count(TimerRegistry::snapshot()),
         ];

         yield new Assertion(description: 'throwing owner destructor cannot abort close')
            ->expect(
               [
                  $adversarialThrown,
                  $adversarialClosed,
                  $adversarialState,
                  $adversarialReleased,
               ],
               Op::Identical,
               [
                  '',
                  true,
                  [
                     Connections::STATUS_CLOSED,
                     '',
                     null,
                     [],
                     [],
                     1,
                     1,
                     ['127.0.0.2' => 1],
                  ],
                  [null, 0, [], 0],
               ],
            )
            ->assert();

         H7CyclicOwner::$destructions = 0;
         $Cyclic = $Connections->accept('127.0.0.3:53100');
         yield new Assertion(description: 'cyclic-owner control peer is admitted')
            ->expect($Cyclic instanceof Connection)
            ->to->be(true)
            ->assert();
         if ($Cyclic instanceof Connection === false) {
            return;
         }
         $CyclicWeak = WeakReference::create($Cyclic);
         $Cyclic->decoded = new H7CyclicOwner($Cyclic);
         $cyclicClosed = $Cyclic->close();
         $CyclicFollower = $Connections->accept('127.0.0.4:53100');
         $cyclicFollowerAdmitted = $CyclicFollower instanceof Connection;
         $CyclicFollower?->close();
         $cyclicTaskCount = 0;
         foreach ($Tasks->getValue() as $tasks) {
            $cyclicTaskCount += count($tasks);
         }
         $cyclicState = [
            $cyclicClosed,
            H7CyclicOwner::$destructions,
            $Cyclic->input,
            $Cyclic->decoded,
            isSet(Connections::$Connections[$Cyclic->id]),
            $cyclicFollowerAdmitted,
            count($Peers->getValue()),
            $IPs->getValue(),
            $cyclicTaskCount,
         ];
         unset($Cyclic, $CyclicFollower);
         gc_collect_cycles();
         Lease::drain();
         $cyclicReleased = [
            $CyclicWeak->get(),
            count($Peers->getValue()),
            $IPs->getValue(),
            count(TimerRegistry::snapshot()),
         ];
         yield new Assertion(description: 'cyclic owner destructor runs before admission release')
            ->expect(
               [$cyclicState, $cyclicReleased],
               Op::Identical,
               [
                  [
                     true,
                     1,
                     '',
                     null,
                     false,
                     true,
                     2,
                     ['127.0.0.3' => 1, '127.0.0.4' => 1],
                     1,
                  ],
                  [null, 0, [], 0],
               ],
            )
            ->assert();

         H7ChainedOwner::$destructions = 0;
         H7ChainedOwner::$clearTimers = false;
         $Bounded = $Connections->accept('127.0.0.3:53101');
         yield new Assertion(description: 'bounded-owner control peer is admitted')
            ->expect($Bounded instanceof Connection)
            ->to->be(true)
            ->assert();
         if ($Bounded instanceof Connection === false) {
            return;
         }
         $BoundedWeak = WeakReference::create($Bounded);
         $Bounded->decoded = new H7ChainedOwner($Bounded, 20);
         $boundedClosed = $Bounded->close();
         $Bounded->status = Connections::STATUS_ESTABLISHED;
         $boundedWrite = $Bounded->writing($ServerSocket, 1, 'B');
         $boundedReclosed = $Bounded->close();
         $boundedTaskCount = 0;
         foreach ($Tasks->getValue() as $tasks) {
            $boundedTaskCount += count($tasks);
         }
         $boundedState = [
            $boundedClosed,
            $boundedWrite,
            $boundedReclosed,
            H7ChainedOwner::$destructions,
            $Bounded->decoded,
            $Bounded->input,
            $Bounded->status,
            count($Peers->getValue()),
            $IPs->getValue(),
            $boundedTaskCount,
         ];
         unset($Bounded);
         gc_collect_cycles();
         Lease::drain();
         yield new Assertion(description: 'finite owner chain stabilizes before slot release')
            ->expect(
               [
                  $boundedState,
                  $BoundedWeak->get(),
                  count($Peers->getValue()),
                  $IPs->getValue(),
                  count(TimerRegistry::snapshot()),
               ],
               Op::Identical,
               [
                  [
                     true,
                     false,
                     true,
                     20,
                     null,
                     '',
                     Connections::STATUS_CLOSED,
                     1,
                     ['127.0.0.3' => 1],
                     1,
                  ],
                  null,
                  0,
                  [],
                  0,
               ],
            )
            ->assert();

         H7ChainedOwner::$destructions = 0;
         H7ChainedOwner::$clearTimers = true;
         $PeerCeiling = new ReflectionProperty(Connections::class, 'peerCeiling');
         $originalPeerCeiling = $PeerCeiling->getValue($Connections);
         $PeerCeiling->setValue($Connections, 1);
         $Quarantines = new ReflectionProperty(Connection::class, 'Quarantines');
         $Quarantined = $Connections->accept('127.0.0.4:53102');
         yield new Assertion(description: 'quarantine control peer is admitted')
            ->expect($Quarantined instanceof Connection)
            ->to->be(true)
            ->assert();
         if ($Quarantined instanceof Connection === false) {
            return;
         }
         $QuarantinedWeak = WeakReference::create($Quarantined);
         $Quarantined->decoded = new H7ChainedOwner($Quarantined, 70);
         $quarantineClosed = $Quarantined->close();
         $quarantineWrite = $Quarantined->writing($ServerSocket, 1, 'Q');
         unset($Quarantined);
         gc_collect_cycles();
         $quarantineTaskCount = 0;
         foreach ($Tasks->getValue() as $tasks) {
            $quarantineTaskCount += count($tasks);
         }
         $quarantineState = [
            $quarantineClosed,
            $quarantineWrite,
            H7ChainedOwner::$destructions,
            $QuarantinedWeak->get() instanceof Connection,
            $QuarantinedWeak->get()?->decoded instanceof H7ChainedOwner,
            $QuarantinedWeak->get()?->input,
            $QuarantinedWeak->get()?->output,
            $QuarantinedWeak->get()?->known,
            $QuarantinedWeak->get()?->status,
            isSet(Connections::$Connections['127.0.0.4:53102']),
            count($Peers->getValue()),
            $IPs->getValue(),
            $quarantineTaskCount,
            count($Quarantines->getValue()),
         ];
         $BlockedByQuarantine = $Connections->accept('127.0.0.5:53103');

         // ! Construction replaces the manager while the quarantine is live.
         //   The replacement remains intentionally inert until that carried
         //   lifecycle settles and canonical Configs can commit atomically.
         $ReplacementServer = new UDP_Server_CLI(Modes::Test);
         $SocketProperty->setValue($ReplacementServer, $ServerSocket);
         $Connections = $ReplacementServer->Connections;
         $replacementTaskCount = 0;
         foreach ($Tasks->getValue() as $tasks) {
            $replacementTaskCount += count($tasks);
         }
         $replacementState = [
            H7ChainedOwner::$destructions,
            $QuarantinedWeak->get() instanceof Connection,
            count($Peers->getValue()),
            $IPs->getValue(),
            $replacementTaskCount,
            count($Quarantines->getValue()),
         ];
         $BlockedAfterReplacement = $Connections->accept('127.0.0.5:53103');
         $due = [];
         foreach ($Tasks->getValue() as $tasks) {
            foreach ($tasks as $id => $task) {
               $due[$id] = $task;
            }
         }
         $Tasks->setValue(null, [time() - 1 => $due]);
         Timer::tick();
         $quarantineFinalTaskCount = 0;
         foreach ($Tasks->getValue() as $tasks) {
            $quarantineFinalTaskCount += count($tasks);
         }
         $Stabilized = $QuarantinedWeak->get();
         $ReplacementServer->configure(new Configs(
            host: '127.0.0.1',
            port: 19993,
            workers: 1,
            maxConnections: $originalPeerCeiling,
            connectionIdleTimeout: 30,
         ));
         $replacementConfigured = $Connections->connect();
         $Readmitted = $Connections->accept('127.0.0.5:53103');
         $readmitted = $Readmitted instanceof Connection;
         $Readmitted?->close();
         H7ChainedOwner::$clearTimers = false;
         yield new Assertion(description: 'quarantine survives inert manager replacement and activation')
            ->expect(
               [
                  $quarantineState,
                  $BlockedByQuarantine,
                  $replacementState,
                  $BlockedAfterReplacement,
                  H7ChainedOwner::$destructions,
                  $Stabilized instanceof Connection,
                  $Stabilized?->decoded,
                  $Stabilized?->status,
                  count($Peers->getValue()),
                  $IPs->getValue(),
                  $quarantineFinalTaskCount,
                  count($Quarantines->getValue()),
                  $replacementConfigured,
                  $readmitted,
               ],
               Op::Identical,
               [
                  [
                     true,
                     false,
                     32,
                     true,
                     true,
                     '',
                     '',
                     '',
                     Connections::STATUS_CLOSED,
                     false,
                     1,
                     ['127.0.0.4' => 1],
                     1,
                     1,
                  ],
                  null,
                  [64, true, 1, ['127.0.0.4' => 1], 1, 1],
                  null,
                  70,
                  false,
                  null,
                  null,
                  1,
                  ['127.0.0.5' => 1],
                  0,
                  0,
                  true,
                  true,
               ],
            )
            ->assert();
         unset($BlockedByQuarantine, $BlockedAfterReplacement, $Readmitted, $Stabilized);
         gc_collect_cycles();
         Lease::drain();
         yield new Assertion(description: 'stabilized quarantine shell is collectable')
            ->expect(
               [
                  $QuarantinedWeak->get(),
                  count($Peers->getValue()),
                  $IPs->getValue(),
                  count(TimerRegistry::snapshot()),
               ],
               Op::Identical,
               [null, 0, [], 0],
            )
            ->assert();

         H7ChainedOwner::$destructions = 0;
         H7ChainedOwner::$clearTimers = true;
         $DirectQuarantines = new ReflectionProperty(Connection::class, 'DirectQuarantines');
         $Direct = new Connection($ServerSocket, '127.0.0.6:53104');
         $DirectWeak = WeakReference::create($Direct);
         $Direct->decoded = new H7ChainedOwner($Direct, 70);
         $directClosed = $Direct->close();
         unset($Direct);
         gc_collect_cycles();
         $directInitialTasks = 0;
         foreach ($Tasks->getValue() as $tasks) {
            $directInitialTasks += count($tasks);
         }
         $ResetCursor = new ReflectionProperty(TimerReset::class, 'cursor');
         $previousResetCursor = $ResetCursor->getValue();
         $ResetCursor->setValue(null, 0);
         $fairObservers = [];
         for ($index = 0; $index < 256; $index++) {
            $fairObservers[] = TimerReset::add(static function (): void {});
         }
         Timer::del();
         $directRecoveredFirst = count(TimerRegistry::snapshot());
         Timer::del();
         $directRecoveredSecond = count(TimerRegistry::snapshot());
         foreach ($fairObservers as $observer) {
            TimerReset::del($observer);
         }
         $fairObservers = [];
         $ResetCursor->setValue(null, $previousResetCursor);
         $due = [];
         foreach ($Tasks->getValue() as $tasks) {
            foreach ($tasks as $id => $task) {
               $due[$id] = $task;
            }
         }
         $Tasks->setValue(null, [time() - 1 => $due]);
         Timer::tick();
         $directMiddleTasks = 0;
         foreach ($Tasks->getValue() as $tasks) {
            $directMiddleTasks += count($tasks);
         }
         $directMiddleDestructions = H7ChainedOwner::$destructions;
         $due = [];
         foreach ($Tasks->getValue() as $tasks) {
            foreach ($tasks as $id => $task) {
               $due[$id] = $task;
            }
         }
         $Tasks->setValue(null, [time() - 1 => $due]);
         Timer::tick();
         $directFinalTasks = 0;
         foreach ($Tasks->getValue() as $tasks) {
            $directFinalTasks += count($tasks);
         }
         yield new Assertion(description: 'direct quarantine retries without an admission manager')
            ->expect(
               [
                  $directClosed,
                  $DirectWeak->get() instanceof Connection,
                  $directInitialTasks,
                  $directRecoveredFirst,
                  $directRecoveredSecond,
                  $directMiddleDestructions,
                  $directMiddleTasks,
                  H7ChainedOwner::$destructions,
                  count($DirectQuarantines->getValue()),
                  $directFinalTasks,
               ],
               Op::Identical,
               [true, false, 1, 1, 1, 64, 1, 70, 0, 0],
            )
            ->assert();
         H7ChainedOwner::$clearTimers = false;
         gc_collect_cycles();
         yield new Assertion(description: 'stabilized direct quarantine is collectable')
            ->expect($DirectWeak->get())
            ->to->be(null)
            ->assert();

         $ManagedAfterDirect = $Connections->accept('127.0.0.7:53105');
         yield new Assertion(description: 'cross-supervisor control peer is admitted')
            ->expect($ManagedAfterDirect instanceof Connection)
            ->to->be(true)
            ->assert();
         if ($ManagedAfterDirect instanceof Connection === false) {
            return;
         }
         $ManagedAfterDirectWeak = WeakReference::create($ManagedAfterDirect);
         $ManagedAfterDirect->used = time() - 31;
         H7ChainedOwner::$destructions = 0;
         H7ChainedOwner::$clearTimers = true;
         $DirectReset = new Connection($ServerSocket, '127.0.0.8:53106');
         $DirectReset->decoded = new H7ChainedOwner($DirectReset, 1);
         $directResetClosed = $DirectReset->close();
         H7ChainedOwner::$clearTimers = false;
         $crossTaskCount = 0;
         foreach ($Tasks->getValue() as $tasks) {
            $crossTaskCount += count($tasks);
         }
         $due = [];
         foreach ($Tasks->getValue() as $tasks) {
            foreach ($tasks as $id => $task) {
               $due[$id] = $task;
            }
         }
         $Tasks->setValue(null, [time() - 1 => $due]);
         Timer::tick();
         $crossFinalTasks = 0;
         foreach ($Tasks->getValue() as $tasks) {
            $crossFinalTasks += count($tasks);
         }
         $crossRetained = [
            $directResetClosed,
            $DirectReset->status,
            $crossTaskCount,
            $ManagedAfterDirect->status,
            count($Peers->getValue()),
            $IPs->getValue(),
            $crossFinalTasks,
         ];
         unset($DirectReset, $ManagedAfterDirect);
         gc_collect_cycles();
         Lease::drain();
         yield new Assertion(description: 'direct owner timer reset preserves managed supervisor')
            ->expect(
               [
                  $crossRetained,
                  $ManagedAfterDirectWeak->get(),
                  count($Peers->getValue()),
                  $IPs->getValue(),
                  count(TimerRegistry::snapshot()),
               ],
               Op::Identical,
               [
                  [
                     true,
                     Connections::STATUS_CLOSED,
                     1,
                     Connections::STATUS_CLOSED,
                     1,
                     ['127.0.0.7' => 1],
                     1,
                  ],
                  null,
                  0,
                  [],
                  0,
               ],
            )
            ->assert();

         H7SweepOwner::$Replacement = null;
         $aPeer = '127.0.0.10:53201';
         $bPeer = '127.0.0.11:53202';
         $A = $Connections->accept($aPeer);
         $B = $Connections->accept($bPeer);
         yield new Assertion(description: 'reentrant-sweep control peers are admitted')
            ->expect([$A instanceof Connection, $B instanceof Connection])
            ->to->be([true, true])
            ->assert();
         if ($A instanceof Connection === false || $B instanceof Connection === false) {
            return;
         }
         $AWeak = WeakReference::create($A);
         $BWeak = WeakReference::create($B);
         $A->decoded = new H7SweepOwner($Connections, $B, $bPeer);
         $A->used = time() - 31;
         $B->used = time() - 31;
         $Tasks = new ReflectionProperty(Timer::class, 'tasks');
         $due = [];
         foreach ($Tasks->getValue() as $tasks) {
            foreach ($tasks as $id => $task) {
               $due[$id] = $task;
            }
         }
         $Tasks->setValue(null, [time() - 1 => $due]);
         Timer::tick();
         $Replacement = H7SweepOwner::$Replacement;
         $Peers = new ReflectionProperty(Connections::class, 'Peers');
         $IPs = new ReflectionProperty(Connections::class, 'IPConnections');
         $taskCount = 0;
         foreach ($Tasks->getValue() as $tasks) {
            $taskCount += count($tasks);
         }
         $sweepRetained = [
            $A->status,
            $B->status,
            $Replacement instanceof Connection,
            isSet(Connections::$Connections[$bPeer]),
            count($Peers->getValue()),
            $IPs->getValue(),
            $taskCount,
         ];
         H7SweepOwner::$Replacement = null;
         unset($A, $B, $Replacement);
         gc_collect_cycles();
         Lease::drain();
         $RecoveredReplacement = $Connections->accept($bPeer);
         yield new Assertion(description: 'stale sweep defers same-key replacement until real death')
            ->expect(
               [
                  $sweepRetained,
                  $AWeak->get(),
                  $BWeak->get(),
                  $RecoveredReplacement instanceof Connection,
                  (Connections::$Connections[$bPeer] ?? null)
                     === $RecoveredReplacement,
                  count($Peers->getValue()),
                  $IPs->getValue(),
                  count(TimerRegistry::snapshot()),
               ],
               Op::Identical,
               [
                  [
                     Connections::STATUS_CLOSED,
                     Connections::STATUS_CLOSED,
                     false,
                     false,
                     2,
                     ['127.0.0.10' => 1, '127.0.0.11' => 1],
                     1,
                  ],
                  null,
                  null,
                  true,
                  true,
                  1,
                  ['127.0.0.11' => 1],
                  1,
               ],
            )
            ->assert();
         $RecoveredReplacement?->close();
         unset($RecoveredReplacement);
         gc_collect_cycles();
         Lease::drain();

         $Poison = $Connections->accept('127.0.0.12:53203');
         $Follower = $Connections->accept('127.0.0.13:53204');
         yield new Assertion(description: 'isolated-sweep control peers are admitted')
            ->expect(
               [$Poison instanceof Connection, $Follower instanceof Connection],
               Op::Identical,
               [true, true],
            )
            ->assert();
         if ($Poison instanceof Connection === false || $Follower instanceof Connection === false) {
            return;
         }
         $PoisonWeak = WeakReference::create($Poison);
         $FollowerWeak = WeakReference::create($Follower);
         unset($Poison->used, $Poison->expiration, $Poison->timers);
         $Follower->used = time() - 31;
         $due = [];
         foreach ($Tasks->getValue() as $tasks) {
            foreach ($tasks as $id => $task) {
               $due[$id] = $task;
            }
         }
         $Tasks->setValue(null, [time() - 1 => $due]);
         Timer::tick();
         $isolatedTaskCount = 0;
         foreach ($Tasks->getValue() as $tasks) {
            $isolatedTaskCount += count($tasks);
         }
         $isolatedState = [
            $Poison->status,
            (new ReflectionProperty($Poison, 'used'))->isInitialized($Poison),
            is_int($Poison->used),
            (new ReflectionProperty($Poison, 'expiration'))->isInitialized($Poison),
            $Poison->expiration,
            (new ReflectionProperty($Poison, 'timers'))->isInitialized($Poison),
            $Poison->timers,
            $Follower->status,
            Connections::$Connections,
            count($Peers->getValue()),
            $IPs->getValue(),
            $isolatedTaskCount,
         ];
         unset($Poison, $Follower);
         gc_collect_cycles();
         Lease::drain();
         yield new Assertion(description: 'one malformed peer cannot abort the remaining sweep')
            ->expect(
               [
                  $isolatedState,
                  $PoisonWeak->get(),
                  $FollowerWeak->get(),
                  count($Peers->getValue()),
                  $IPs->getValue(),
                  count(TimerRegistry::snapshot()),
               ],
               Op::Identical,
               [
                  [
                     Connections::STATUS_CLOSED,
                     true,
                     true,
                     true,
                     0,
                     true,
                     [],
                     Connections::STATUS_CLOSED,
                     [],
                     2,
                     ['127.0.0.12' => 1, '127.0.0.13' => 1],
                     1,
                  ],
                  null,
                  null,
                  0,
                  [],
                  0,
               ],
            )
            ->assert();
      }
      finally {
         H7SweepOwner::$Replacement = null;
         H7CloseDecoder::$calls = 0;
         H7CloseDecoder::$status = 0;
         H7GetterDecoder::$calls = 0;
         H7CloseEncoder::$Target = null;
         H7CloseEncoder::$calls = 0;
         H7CloseEncoder::$resurrect = false;
         H7RejectingEncoder::$calls = 0;
         H7ChainedOwner::$destructions = 0;
         H7ChainedOwner::$clearTimers = false;
         H7CyclicOwner::$destructions = 0;
         H7TerminalDestructor::$results = [];
         UDP_Server_CLI::$Decoder = $PreviousDecoder;
         UDP_Server_CLI::$Encoder = $PreviousEncoder;
         foreach (array_values(Connections::$Connections) as $Connection) {
            $Connection->close();
         }
         Connections::$Connections = [];
         Connections::$blacklist = [];
         unset($Connection);
         gc_collect_cycles();
         Lease::drain();
         Timer::del();
         gc_collect_cycles();
         Lease::drain();
         $remainingAlarm = pcntl_alarm(0);

         $Peers = new ReflectionProperty(Connections::class, 'Peers');
         $IPs = new ReflectionProperty(Connections::class, 'IPConnections');
         $Pending = new ReflectionProperty(Lease::class, 'Pending');
         $Tasks = new ReflectionProperty(Timer::class, 'tasks');
         $Quarantines = new ReflectionProperty(Connection::class, 'Quarantines');
         $DirectQuarantines = new ReflectionProperty(
            Connection::class,
            'DirectQuarantines',
         );
         $GenerationBuckets = new ReflectionProperty(
            Connection::class,
            'GenerationBuckets',
         );
         $ManagerReset = new ReflectionProperty(Connections::class, 'resetObserver');
         $DirectReset = new ReflectionProperty(Connection::class, 'resetObserver');
         $ResetObservers = new ReflectionProperty(TimerReset::class, 'Observers');
         $ResetRecoveries = new ReflectionProperty(TimerReset::class, 'Recoveries');
         $cleanupState = [
            'peers' => count($Peers->getValue()),
            'IPs' => $IPs->getValue(),
            'pending' => count($Pending->getValue()),
            'tasks' => count(TimerRegistry::snapshot()),
            'quarantines' => count($Quarantines->getValue()),
            'direct_quarantines' => count($DirectQuarantines->getValue()),
            'generation_buckets' => count($GenerationBuckets->getValue()),
            'manager_reset' => $ManagerReset->getValue(),
            'direct_reset' => $DirectReset->getValue(),
            'reset_observers' => count($ResetObservers->getValue()),
            'reset_recoveries' => count($ResetRecoveries->getValue()),
            'alarm' => $remainingAlarm,
         ];
         $clean = $cleanupState === [
            'peers' => 0,
            'IPs' => [],
            'pending' => 0,
            'tasks' => 0,
            'quarantines' => 0,
            'direct_quarantines' => 0,
            'generation_buckets' => 0,
            'manager_reset' => 0,
            'direct_reset' => 0,
            'reset_observers' => 0,
            'reset_recoveries' => 0,
            'alarm' => 0,
         ];
         pcntl_signal(SIGALRM, $PreviousAlarm === false ? SIG_DFL : $PreviousAlarm);

         if (is_resource($SourceSocket)) {
            fclose($SourceSocket);
         }
         if (is_resource($ServerSocket)) {
            fclose($ServerSocket);
         }
         Display::show($segments);
         if ($clean === false) {
            $JSON = (string) json_encode($cleanupState);
            throw new RuntimeException("UDP pipeline test teardown left state: {$JSON}");
         }
      }
   })
);
