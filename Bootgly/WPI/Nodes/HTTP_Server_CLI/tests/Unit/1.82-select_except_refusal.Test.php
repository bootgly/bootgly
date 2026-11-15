<?php

use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Connections;
use Bootgly\WPI\Events\Select;


/**
 * `EVENT_EXCEPT` on the Select reactor: nothing dispatches an exceptional
 * condition, so its registration is refused instead of being waited on.
 *
 * `select()` is level-triggered: a descriptor with out-of-band data pending
 * stays "exceptional" until the urgent byte is consumed. Waiting on it with
 * no callback to consume it makes every wait return at once — the loop spins.
 * Section D runs the real `loop()` over such a descriptor, watched for reading
 * as a connection is: the shape a remote peer reaches with one urgent byte.
 * Section F pins, by tokens, that no wait of the reactor passes an except set.
 */
return new Test(
   description: 'Select should refuse an EVENT_EXCEPT registration: add() and del() answer false, nothing is watched, and out-of-band data cannot make the loop spin',
   test: new Assertions(Case: function (): Generator {
      $Reflection = new ReflectionClass(Select::class);
      $Reads = $Reflection->getProperty('reads');
      $Writes = $Reflection->getProperty('writes');
      $Connections = new class implements Connections {
         public function connect (): bool
         {
            return true;
         }
      };
      // ! A reactor that counts its passes (`loop()` reads `$loop` twice per
      //   pass that waits) and exposes the admission probe
      $reactor = static function () use (&$Connections): Select {
         return new class($Connections) extends Select {
            public int $looks = 0;

            public bool $loop = true {
               get {
                  $this->looks++;

                  return $this->loop;
               }
            }

            /** @param resource $Socket */
            public function probe ($Socket, int $flag): bool
            {
               return parent::check($Socket, $flag);
            }
         };
      };
      $watched = static fn (Select $Select): array => [
         count($Reads->getValue($Select)),
         count($Writes->getValue($Select)),
      ];
      // ! Every array the reactor keeps, searched for a socket (by value and
      //   by descriptor id): no table is named, so none can hide one
      $holders = static function (Select $Select, mixed $Socket) use ($Reflection): array {
         $names = [];
         foreach ($Reflection->getProperties() as $Property) {
            if ($Property->isStatic() || $Property->isInitialized($Select) === false) {
               continue;
            }

            $value = $Property->getValue($Select);
            if (
               is_array($value)
               && (in_array($Socket, $value, true) || isset($value[(int) $Socket]))
            ) {
               $names[] = $Property->getName();
            }
         }

         return $names;
      };
      // ! A read payload that counts its dispatches and consumes what made
      //   the descriptor ready
      $package = static fn () => new class {
         public int $dispatched = 0;

         /** @param resource $Socket */
         public function reading ($Socket): void
         {
            $this->dispatched++;

            fread($Socket, 16);
         }
      };
      $spent = static function (): int {
         $usage = getrusage();

         return $usage['ru_utime.tv_sec'] * 1_000_000 + $usage['ru_utime.tv_usec']
            + $usage['ru_stime.tv_sec'] * 1_000_000 + $usage['ru_stime.tv_usec'];
      };
      // ! The real loop for `$seconds`, stopped by one of its own timers
      $run = static function (Select $Select, float $seconds) use ($spent): array {
         $Select->loop = true;
         $Select->looks = 0; // @phpstan-ignore property.notFound
         $Select->defer(
            (int) hrtime(true) + (int) ($seconds * 1_000_000_000),
            static function () use ($Select): void {
               $Select->loop = false;
            }
         );

         $before = $spent();
         $Select->loop();

         return [
            'passes' => intdiv($Select->looks, 2), // @phpstan-ignore property.notFound
            'cpu_ms' => ($spent() - $before) / 1000,
         ];
      };
      $Sockets = [];

      try {
         // @@ A) The registration is refused and leaves nothing behind
         $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         if ($Pair === false) {
            throw new RuntimeException('Could not create a socket pair.');
         }
         $Sockets[] = $Pair[0];
         $Sockets[] = $Pair[1];
         $Select = $reactor();

         $added = $Select->add($Pair[0], Select::EVENT_EXCEPT, 'except');
         $after = $watched($Select);
         $held = $holders($Select, $Pair[0]);
         $removed = $Select->del($Pair[0], Select::EVENT_EXCEPT);
         $observed = json_encode(['add' => $added, 'del' => $removed, 'watched' => $after, 'held by' => $held]);

         yield assert(
            assertion: $added === false && $removed === false && $after === [0, 0] && $held === [],
            description: "add() and del() answer false for EVENT_EXCEPT and the reactor keeps the refused socket nowhere — observed: {$observed}"
         );

         // @@ B) The flags the loop dispatches are still admitted and removed
         $answers = [
            'connect' => $Select->add($Pair[0], Select::EVENT_CONNECT, true),
            'read' => $Select->add($Pair[1], Select::EVENT_READ, true),
            'write' => $Select->add($Pair[1], Select::EVENT_WRITE, true),
         ];
         $full = $watched($Select);
         // ! A descriptor already watched for reading is still refused for
         //   an exceptional condition, and stays watched
         $again = $Select->add($Pair[1], Select::EVENT_EXCEPT, 'except');
         $dropped = $Select->del($Pair[1], Select::EVENT_EXCEPT);
         $kept = $watched($Select);
         $removals = [
            'connect' => $Select->del($Pair[0], Select::EVENT_CONNECT),
            'read' => $Select->del($Pair[1], Select::EVENT_READ),
            'write' => $Select->del($Pair[1], Select::EVENT_WRITE),
         ];
         $empty = $watched($Select);
         // ! The refusal leaves the read registration whole — its payload
         //   too: the byte that arrives next is dispatched once, to it
         $Reader = $package();
         $Select->add($Pair[1], Select::EVENT_READ, $Reader);
         $Select->add($Pair[1], Select::EVENT_EXCEPT, 'except');
         $Select->del($Pair[1], Select::EVENT_EXCEPT);
         fwrite($Pair[0], '!');
         $Run = $run($Select, 0.2);
         $Select->del($Pair[1], Select::EVENT_READ);
         $survival = ['dispatched' => $Reader->dispatched, 'passes' => $Run['passes']];
         // ! A reactor that watches writes only still waits on them: the
         //   payload is dispatched once, and removes its own registration
         $Writer = new class {
            public int $dispatched = 0;
            public null|Select $Select = null;

            /** @param resource $Socket */
            public function writing ($Socket): void
            {
               $this->dispatched++;

               $this->Select?->del($Socket, Select::EVENT_WRITE);
            }
         };
         $Writer->Select = $Select;
         $Select->add($Pair[0], Select::EVENT_WRITE, $Writer);
         $Run = $run($Select, 0.2);
         $Writer->Select = null;
         $writes = ['dispatched' => $Writer->dispatched, 'passes' => $Run['passes'], 'watched' => $watched($Select)];
         $observed = json_encode([$answers, $full, $again, $dropped, $kept, $removals, $empty, $survival, $writes]);

         yield assert(
            assertion: $answers === ['connect' => true, 'read' => true, 'write' => true]
               && $full === [2, 1]
               && $again === false && $dropped === false && $kept === [2, 1]
               && $removals === ['connect' => true, 'read' => true, 'write' => true]
               && $empty === [0, 0]
               && $survival['dispatched'] === 1 && $survival['passes'] <= 50
               && $writes['dispatched'] === 1 && $writes['passes'] <= 50 && $writes['watched'] === [0, 0],
            description: "CONNECT, READ and WRITE are admitted and removed as before; a watched descriptor is refused for EVENT_EXCEPT, stays watched and keeps its payload; a write-only reactor still dispatches — observed: {$observed}"
         );

         // @@ C) The admission probe never vouches for a flag nobody dispatches
         $Select->add($Pair[1], Select::EVENT_READ, true);
         $probes = [
            'except, unwatched' => $Select->probe($Pair[0], Select::EVENT_EXCEPT), // @phpstan-ignore method.notFound
            'except, watched' => $Select->probe($Pair[1], Select::EVENT_EXCEPT), // @phpstan-ignore method.notFound
            'unknown, watched' => $Select->probe($Pair[1], 99), // @phpstan-ignore method.notFound
            'read, unwatched' => $Select->probe($Pair[0], Select::EVENT_READ), // @phpstan-ignore method.notFound
            'write, unwatched' => $Select->probe($Pair[0], Select::EVENT_WRITE), // @phpstan-ignore method.notFound
         ];
         $Select->del($Pair[1], Select::EVENT_READ);
         // ! A reactor built without its constructor refuses it too
         $Bare = $Reflection->newInstanceWithoutConstructor();
         $bare = [
            $Bare->add($Pair[0], Select::EVENT_EXCEPT, true),
            $Bare->del($Pair[0], Select::EVENT_EXCEPT),
            $Bare->add($Pair[0], Select::EVENT_READ, true),
         ];
         $observed = json_encode([$probes, $bare]);

         yield assert(
            assertion: $probes === [
               'except, unwatched' => false,
               'except, watched' => false,
               'unknown, watched' => false,
               'read, unwatched' => true,
               'write, unwatched' => true,
            ] && $bare === [false, false, true],
            description: "check() answers false for EVENT_EXCEPT and for an unknown flag, even on a watched descriptor, and true for READ and WRITE; a constructor-less reactor refuses alike — observed: {$observed}"
         );

         // @@ D) Out-of-band data cannot make the loop spin
         $Server = stream_socket_server('tcp://127.0.0.1:0');
         if ($Server === false) {
            throw new RuntimeException('Could not listen on the loopback.');
         }
         $Sockets[] = $Server;
         $address = stream_socket_get_name($Server, false);
         $Client = stream_socket_client("tcp://{$address}");
         $Accepted = $Client === false ? false : stream_socket_accept($Server, 5.0);
         if ($Client === false || $Accepted === false) {
            throw new RuntimeException('Could not connect over the loopback.');
         }
         $Sockets[] = $Client;
         $Sockets[] = $Accepted;
         stream_set_blocking($Accepted, false);
         stream_socket_sendto($Client, '!', STREAM_OOB);

         // ! The trigger is real: the system reports the descriptor as
         //   exceptional, and keeps reporting it
         $exceptional = 0;
         for ($attempt = 0; $attempt < 100 && $exceptional !== 1; $attempt++) {
            $read = null;
            $write = null;
            $except = [$Accepted];
            $exceptional = (int) @stream_select($read, $write, $except, 0, 20_000);
         }

         // ! The descriptor is watched for reading, as a connection is, and
         //   then offered with EVENT_EXCEPT. A spin measures tens of
         //   thousands of passes: the bound leaves room for interrupted waits
         $Select = $reactor();
         $Urgent = $package();
         $idle = $Select->add($Pair[0], Select::EVENT_READ, true);
         $read = $Select->add($Accepted, Select::EVENT_READ, $Urgent);
         $urgent = $Select->add($Accepted, Select::EVENT_EXCEPT, 'except');
         $Run = $run($Select, 0.2);
         $Select->destroy();
         $observed = json_encode(['exceptional' => $exceptional, 'read' => $read, 'except' => $urgent, 'dispatched' => $Urgent->dispatched, 'run' => $Run]);

         yield assert(
            assertion: $exceptional === 1 && $idle === true && $read === true && $urgent === false
               && $Run['passes'] <= 50 && $Run['cpu_ms'] < 50.0 && $Urgent->dispatched === 0,
            description: "with an urgent byte pending on a descriptor watched for reading and offered with EVENT_EXCEPT, 200 ms of the real loop take a handful of passes, not a spin, and dispatch nothing — observed: {$observed}"
         );

         // @@ E) The dead state is gone: no except table, no except payload map
         $leftovers = [
            'excepts' => $Reflection->hasProperty('excepts'),
            'excepting' => $Reflection->hasProperty('excepting'),
         ];
         $observed = json_encode($leftovers);

         yield assert(
            assertion: $leftovers === ['excepts' => false, 'excepting' => false],
            description: "Select keeps no table and no payload map for exceptional conditions — observed: {$observed}"
         );

         // @@ F) No wait of the reactor passes an except set: the third
         //       argument of every stream_select() call is a variable that
         //       the file only ever assigns `null`
         $tokens = array_values(array_filter(
            token_get_all((string) file_get_contents((string) $Reflection->getFileName())),
            static fn (array|string $token): bool => is_array($token) === false
               || in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true) === false
         ));
         $text = static fn (array|string $token): string => is_array($token) ? $token[1] : $token;
         $calls = 0;
         $sets = [];
         $strays = [];
         foreach ($tokens as $index => $token) {
            if (
               is_array($token) === false
               || in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true) === false
               || ltrim($token[1], '\\') !== 'stream_select'
               || $text($tokens[$index + 1] ?? '') !== '('
            ) {
               continue;
            }

            // ! The call's arguments, split on the commas of its own depth
            $arguments = [[]];
            $depth = 0;
            for ($next = $index + 2; isset($tokens[$next]); $next++) {
               $piece = $text($tokens[$next]);
               if ($piece === ')' && $depth === 0) {
                  break;
               }
               if ($piece === ',' && $depth === 0) {
                  $arguments[] = [];

                  continue;
               }
               if ($piece === '(' || $piece === '[') {
                  $depth++;
               }
               else if ($piece === ')' || $piece === ']') {
                  $depth--;
               }

               $arguments[count($arguments) - 1][] = $tokens[$next];
            }

            $calls++;
            $third = $arguments[2] ?? [];
            if (count($third) === 1 && is_array($third[0]) && $third[0][0] === T_VARIABLE) {
               $sets[$third[0][1]] = true;
            }
            else {
               $argument = implode('', array_map($text, $third));
               $strays[] = "argument: {$argument}";
            }
         }
         // ! Every other appearance of those variables is `<variable> = null;`
         foreach ($tokens as $index => $token) {
            if (
               is_array($token) === false
               || $token[0] !== T_VARIABLE
               || isset($sets[$token[1]]) === false
            ) {
               continue;
            }

            $before = $text($tokens[$index - 1] ?? '');
            $after = array_map($text, array_slice($tokens, $index + 1, 3));
            $passed = ($before === ',' || $before === '(') && in_array($after[0] ?? '', [',', ')'], true);
            if ($passed === false && $after !== ['=', 'null', ';']) {
               $found = implode(' ', [$token[1], ...$after]);
               $strays[] = "line {$token[2]}: {$found}";
            }
         }
         $observed = json_encode(['calls' => $calls, 'sets' => array_keys($sets), 'strays' => $strays]);

         yield assert(
            assertion: $calls >= 3 && array_keys($sets) === ['$except'] && $strays === [],
            description: "every stream_select() call of the reactor takes an except set that is only ever null — observed: {$observed}"
         );

         // @@ G) destroy() leaves no table and no payload behind
         $Fresh = $reactor();
         $Fresh->add($Pair[0], Select::EVENT_CONNECT, 'connect');
         $Fresh->add($Pair[1], Select::EVENT_READ, 'read');
         $Fresh->add($Pair[1], Select::EVENT_WRITE, 'write');
         $during = [$holders($Fresh, $Pair[0]), $holders($Fresh, $Pair[1])];
         $Fresh->destroy();
         $left = [$holders($Fresh, $Pair[0]), $holders($Fresh, $Pair[1])];
         $observed = json_encode(['during' => $during, 'after' => $left]);

         yield assert(
            assertion: count($during[0]) === 2 && count($during[1]) === 4 && $left === [[], []],
            description: "destroy() empties every table and payload map a registration filled — observed: {$observed}"
         );
      }
      finally {
         foreach ($Sockets as $Socket) {
            if (is_resource($Socket)) {
               fclose($Socket);
            }
         }
      }
   })
);
