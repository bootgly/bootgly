<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

/*
 * Drives the Redis cache driver against a hostile stub server and prints one JSON line
 * describing what came back. It runs in its own PHP process because the driver prefers
 * ext-redis whenever that extension is loaded; the spec starts it with the ini scan
 * directory cleared so a plain socket server is enough to answer.
 *
 * The reply is a bulk payload shaped exactly like Drivers\Redis::pack() output for an
 * object, so it exercises unpack() — the sink that used to hand the caller whatever
 * unserialize() built, with no allow-list and no type guard at all.
 *
 * Nothing here is a test; the assertions live in `7.3-security-redis-deserialization.Test.php`.
 */

// ! Its own process, so it boots the framework itself
require __DIR__ . '/../../../../../autoboot.php';

use Bootgly\ABI\Resources\Cache;


/**
 * A destructor that reaches unlink(), standing in for the in-tree gadget chain.
 * The static counter is what the spec reads: a record REJECTED after the class was
 * already constructed is not a defence, so the question is whether it was built at all.
 */
class RedisGadget
{
   public static int $fired = 0;

   public string $target = '';


   public function __destruct ()
   {
      self::$fired++;

      if (is_file($this->target) === true) {
         unlink($this->target);
      }
   }
}


$emit = function (array $payload): never {
   echo json_encode($payload), "\n";
   exit(0);
};

// ? A stub server can only answer the native RESP path
if (extension_loaded('redis') === true) {
   $emit(['skip' => 'ext-redis is loaded, a plain socket stub cannot answer it']);
}
if (function_exists('pcntl_fork') === false) {
   $emit(['skip' => 'pcntl_fork() is unavailable']);
}

// ! Mirrors Drivers\Redis::pack(), so the stub answers bytes the driver will unpack
$bulk = function (string $raw): string {
   return '$' . strlen($raw) . "\r\n{$raw}\r\n";
};

/**
 * Fork a stub server answering every command with the same reply.
 */
$scenario = function (string $reply, callable $drive) use ($bulk): mixed {
   $Listener = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

   if ($Listener === false) {
      return null;
   }

   $address = (string) stream_socket_get_name($Listener, false);
   $port = (int) substr($address, strrpos($address, ':') + 1);

   $PID = pcntl_fork();

   if ($PID === 0) {
      $Peer = @stream_socket_accept($Listener, 3.0);

      if ($Peer !== false) {
         stream_set_timeout($Peer, 3);

         for ($answered = 0; $answered < 8; $answered++) {
            $command = @fread($Peer, 16384);

            if ($command === false || $command === '') {
               break;
            }

            @fwrite($Peer, $bulk($reply));
         }

         @fclose($Peer);
      }

      @fclose($Listener);
      exit(0);
   }

   @fclose($Listener);

   $result = $drive($port);

   // ! The child ends on its own once its accept or read times out — ext-posix is
   //   absent in this lane, so there is no signal to send it
   pcntl_waitpid($PID, $status);

   return $result;
};

$victim = sys_get_temp_dir() . '/bootgly-redis-injection-' . uniqid('', true) . '.probe';

$Gadget = new RedisGadget;
$Gadget->target = $victim;
// ! The exact bytes pack() emits for an object
$hostile = "\x01" . serialize($Gadget);
// ! Disarm this instance so its own destructor is never the one measured
$Gadget->target = '';
unset($Gadget);

$config = function (int $port, array $extra = []): array {
   return [
      'driver' => 'redis',
      'host' => '127.0.0.1',
      'port' => $port,
      'prefix' => '',
      'timeout' => 2.0,
   ] + $extra;
};

// @ A hostile endpoint answering GET with a serialized object the app never declared
$refused = $scenario($hostile, function (int $port) use ($config, $victim): array {
   file_put_contents($victim, 'SECRET');
   RedisGadget::$fired = 0;

   $Cache = new Cache($config($port));

   try {
      $value = $Cache->fetch('anything');
   }
   catch (Throwable $Throwable) {
      $value = $Throwable::class;
   }

   // ! What fetch() HANDED BACK matters as much as what it built: a driver that
   //   refuses to construct the class but still answers with an inert
   //   placeholder has moved the problem, not closed it
   $returned = match (true) {
      $value === null => 'null',
      is_object($value) => 'object:' . $value::class,
      default => 'scalar:' . gettype($value),
   };

   unset($value);
   gc_collect_cycles();

   return [
      'constructed' => RedisGadget::$fired,
      'victim' => is_file($victim),
      'returned' => $returned,
   ];
});

@unlink($victim);

// @ The same class one level down inside an array — the shape a top-level-only
//   guard hands straight back to the application
$NestedGadget = new RedisGadget;
$nested = "\x01" . serialize(['held' => $NestedGadget]);
$NestedGadget->target = '';
unset($NestedGadget);

$deep = $scenario($nested, function (int $port) use ($config): array {
   $Cache = new Cache($config($port));

   try {
      $value = $Cache->fetch('anything');
   }
   catch (Throwable $Throwable) {
      return ['returned' => 'throw:' . $Throwable::class];
   }

   $inner = is_array($value) ? ($value['held'] ?? null) : null;

   return [
      'returned' => $value === null ? 'null' : gettype($value),
      'inner' => is_object($inner) ? $inner::class : gettype($inner),
   ];
});

// @ The same bytes, read by a cache that DID declare the class
$declared = $scenario($hostile, function (int $port) use ($config): array {
   $Cache = new Cache($config($port, ['classes' => [RedisGadget::class]]));

   try {
      $value = $Cache->fetch('anything');
   }
   catch (Throwable $Throwable) {
      return ['class' => $Throwable::class];
   }

   $class = is_object($value) ? $value::class : null;

   if (is_object($value) === true) {
      $value->target = '';
   }

   return ['class' => $class];
});

// @ Control — an honest server's scalar and a stored `false` still round-trip
$control = $scenario("\x01" . serialize('ALICE'), function (int $port) use ($config): mixed {
   return new Cache($config($port))->fetch('k');
});
$negative = $scenario("\x01" . serialize(false), function (int $port) use ($config): mixed {
   return new Cache($config($port))->fetch('k');
});

$emit([
   'refused' => $refused,
   'deep' => $deep,
   'declared' => $declared,
   'control' => $control,
   'negative' => $negative,
]);
