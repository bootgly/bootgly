<?php

use Bootgly\ABI\Events\Emitter;
use Bootgly\API\Workables\Server as SAPI;
use Bootgly\API\Workables\Server\Middlewares;
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Cache;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;


/**
 * Run an isolated production Encoder_ fixture and restore every worker-global
 * surface it touches. The supplied callback receives three closures:
 *
 * 1. decode and encode one raw HTTP/1 request through the real Encoder_;
 * 2. capture its returned wire or original Throwable without swallowing it;
 * 3. reset the emitter/middleware/Response/Router context for an isolated leg.
 *
 * @return Closure(Closure(Closure(string):string, Closure(Closure():string):array{
 *    wire:null|string,
 *    throwable_class:null|string,
 *    throwable_message:null|string
 * }, Closure(Emitter):void):mixed):mixed
 */
return static function (Closure $Work): mixed {
   $Socket = tmpfile();
   if (is_resource($Socket) === false) {
      throw new RuntimeException(
         'L4 Telemetry fixture could not allocate the Encoder_ stream surrogate.'
      );
   }

   $OldRequest = Server::$Request;
   $OldResponse = Server::$Response;
   $OldRouter = Server::$Router;
   $OldDecoder = Server::$Decoder;
   $handlerInitialized = isset(SAPI::$Handler);
   $middlewaresInitialized = isset(SAPI::$Middlewares);
   $OldHandler = SAPI::$Handler ?? null;
   $OldMiddlewares = SAPI::$Middlewares ?? null;
   $OldEmitter = Emitter::$Instance;
   $oldEntries = Cache::$entries;
   $oldBytes = Cache::$bytes;
   $oldURIs = Cache::$URIs;
   $oldGeneration = Cache::$generation;

   // @ Direct probes must not leak replay/admission state into the native
   //   harness request that follows them in this same process.
   $EncoderReflection = new ReflectionClass(Encoder_::class);
   $encoderProperties = [
      'wire',
      'Admitted',
      'admittedBody',
      'admittedFields',
      'admittedPrepared',
      'admittedQueued',
      'admittedMasked',
      'admittedType',
      'admittedPreset',
      'admittedCode',
      'admittedHints',
      'admittedStream',
      'admittedChunked',
      'admittedEncoded',
      'adopted',
      'handled',
      'mutated',
      'observed',
      'mediated',
      'guarded',
   ];
   $EncoderState = [];
   foreach ($encoderProperties as $name) {
      $Property = $EncoderReflection->getProperty($name);
      $EncoderState[$name] = $Property->getValue();
   }

   try {
      /** @var Connection $Connection */
      $Connection = (new ReflectionClass(Connection::class))->newInstanceWithoutConstructor();
      $Connection->Socket = $Socket;
      $Connection->timers = [];
      $Connection->ip = '127.0.0.1';
      $Connection->port = 12345;
      $Connection->encrypted = false;
      $Connection->writes = 0;

      $Encode = static function (string $raw) use ($Connection): string {
         $Package = new class($Connection) extends TCPPackages {
            public function __construct (Connection $Connection)
            {
               $this->Connection = $Connection;

               $this->cache = true;
               $this->changed = true;
               $this->input = '';
               $this->output = '';
               $this->callbacks = [&$this->input];
               $this->expired = false;
               $this->consumed = 0;
               $this->rejected = false;

               $this->downloading = [];
               $this->uploading = [];
               $this->closeAfterWrite = false;
            }
         };

         Server::$Request = new Request;
         Server::$Decoder = new Decoder_;

         $size = strlen($raw);
         $State = Server::$Request->decode($Package, $raw, $size);
         if (
            $State !== States::Complete
            || $Package->consumed !== $size
            || $Package->rejected
         ) {
            throw new RuntimeException(
               'L4 Telemetry fixture request was rejected before Encoder_.'
            );
         }

         $length = null;

         return Encoder_::encode($Package, $length);
      };

      $Run = static function (Closure $Work): array {
         $wire = null;
         $throwableClass = null;
         $throwableMessage = null;

         try {
            $wire = $Work();
         }
         catch (Throwable $Throwable) {
            $throwableClass = $Throwable::class;
            $throwableMessage = $Throwable->getMessage();
         }

         return [
            'wire' => $wire,
            'throwable_class' => $throwableClass,
            'throwable_message' => $throwableMessage,
         ];
      };

      $Configure = static function (Emitter $Emitter): void {
         Cache::flush();
         Emitter::$Instance = $Emitter;
         SAPI::$Middlewares = new Middlewares;
         Server::$Response = new Response;
         Server::$Router = new Router;
      };

      return $Work($Encode, $Run, $Configure);
   }
   finally {
      Server::$Request = $OldRequest;

      foreach ($EncoderState as $name => $value) {
         $Property = $EncoderReflection->getProperty($name);
         $Property->setValue(null, $value);
      }

      Cache::$entries = $oldEntries;
      Cache::$bytes = $oldBytes;
      Cache::$URIs = $oldURIs;
      Cache::$generation = $oldGeneration;
      Emitter::$Instance = $OldEmitter;
      Server::$Response = $OldResponse;
      Server::$Router = $OldRouter;
      Server::$Decoder = $OldDecoder;

      if ($handlerInitialized && $OldHandler !== null) {
         SAPI::$Handler = $OldHandler;
      }
      if ($middlewaresInitialized && $OldMiddlewares !== null) {
         SAPI::$Middlewares = $OldMiddlewares;
      }

      @fclose($Socket);
   }
};
