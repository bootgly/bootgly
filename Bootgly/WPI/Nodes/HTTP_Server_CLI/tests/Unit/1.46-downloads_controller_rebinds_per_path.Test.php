<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Downloading\Downloads;


/**
 * BG-23 — the aggregate-downloads controller follows the server that boots.
 *
 * `Downloads::init($path)` is idempotent for the path it is bound to. Given a
 * DIFFERENT path it must rebind: close the previous descriptor, drop the old
 * identity (device/inode, PID, tracking map) and create the new controller
 * inode. One process boots successive servers — the test runner does, one
 * Test-mode server per suite — and the previous server's counter must not be
 * inherited: `State::sweep()` reclaims that inode once the instance is gone,
 * after which every worker bound BY PATH (`bind()` → `open('r+b')`) fails
 * closed and multipart uploads land as empty temp files behind a 200.
 *
 * The scenario runs in a forked child so the runner process's statics are
 * never touched — no masking of the leak for the suites that follow (without
 * the fix the child's reservations do land on whatever counter the runner is
 * bound to: that IS the leak). The grandchild models a worker — a fresh PID
 * that `bind()`s by path. The child is unconditionally terminal: a Throwable
 * inside it must never unwind into the runner as a duplicated process.
 */
return new Test(
   description: 'Downloads::init() rebinds the controller when given a different path',
   test: new Assertions(Case: function (): Generator {
      $directory = BOOTGLY_STORAGE_DIR . 'tests/bg23-' . bin2hex(random_bytes(4)) . '/';
      $A = "{$directory}first.lock.downloads";
      $B = "{$directory}second.lock.downloads";
      // ! init() refuses a group/other-writable parent
      @mkdir($directory, 0755, true);
      @chmod($directory, 0755);

      $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      [$Reader, $Writer] = $pair !== false ? $pair : [null, null];

      $report = [];
      try {
         $pid = pcntl_fork();
         if ($pid === 0) {
            // # Child — the whole scenario on a private copy of the statics
            try {
               $Counterfile = new ReflectionProperty(Downloads::class, 'counterfile');
               $Counter = new ReflectionProperty(Downloads::class, 'counter');
               $Tracked = new ReflectionProperty(Downloads::class, 'tracked');
               $decode = static function (string $file): null|int {
                  clearstatcache(true, $file);
                  $record = @file_get_contents($file);
                  if (is_string($record) === false || strlen($record) < 8) {
                     return null;
                  }
                  $unpacked = unpack('P', substr($record, 0, 8));

                  return $unpacked === false ? null : (int) $unpacked[1];
               };
               $identity = static function (mixed $handle): array|false {
                  return is_resource($handle) ? (fstat($handle) ?: false) : false;
               };

               // @ First server binds A; a worker-side tracking entry rides along
               $report['a_init'] = Downloads::init(path: $A);
               $report['a_bound'] = $Counterfile->getValue() === $A;
               $handleA = $Counter->getValue();
               Downloads::track("{$directory}phantom", 5);

               // @ Second server binds B — a different controller identity
               $report['b_init'] = Downloads::init(path: $B);
               $report['b_bound'] = $Counterfile->getValue() === $B;
               $handleB = $Counter->getValue();
               $opened = $identity($handleB);
               clearstatcache(true, $B);
               $current = @lstat($B);
               $report['b_inode'] = is_array($opened) && is_array($current)
                  && $opened['dev'] === $current['dev']
                  && $opened['ino'] === $current['ino'];
               $report['a_closed'] = is_resource($handleA) === false;
               $report['tracked_cleared'] = $Tracked->getValue() === [];

               // @ Reservations land on B, A is left alone
               $report['peek'] = Downloads::peek();
               $report['reserve'] = Downloads::reserve(7);
               $report['b_value'] = $decode($B);
               $report['a_value'] = $decode($A);

               // @ Same path again — idempotent: same inode, value kept
               $report['b_again'] = Downloads::init(path: $B);
               $again = $identity($Counter->getValue());
               $report['b_same_inode'] = is_array($again) && is_array($current)
                  && $again['ino'] === $current['ino']
                  && (int) $again['nlink'] === 1;
               $report['b_value_again'] = $decode($B);

               // @ What State::sweep() does to a gone instance
               @unlink($A);
               clearstatcache();

               // @ Grandchild — a worker: fresh PID, bind() by path
               $inner = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
               [$InnerReader, $InnerWriter] = $inner !== false ? $inner : [null, null];
               $worker = 'setup-failed';
               if ($InnerReader !== null && $InnerWriter !== null) {
                  $grandchild = pcntl_fork();
                  if ($grandchild === 0) {
                     fclose($InnerReader);
                     fwrite($InnerWriter, Downloads::reserve(3) ? 'reserved' : 'refused');
                     fclose($InnerWriter);
                     posix_kill(posix_getpid(), SIGKILL);
                  }
                  fclose($InnerWriter);
                  stream_set_blocking($InnerReader, false);
                  $worker = '';
                  $deadline = time() + 8;
                  while (time() < $deadline) {
                     $chunk = fread($InnerReader, 16);
                     if (is_string($chunk) && $chunk !== '') {
                        $worker .= $chunk;
                     }
                     if (feof($InnerReader)) {
                        break;
                     }
                     usleep(20000);
                  }
                  fclose($InnerReader);
                  if ($grandchild > 0) {
                     pcntl_waitpid($grandchild, $status);
                  }
               }
               $report['worker'] = $worker;
               $report['b_after_worker'] = $decode($B);

               fwrite($Writer, json_encode($report) ?: '{}');
               fclose($Writer);
            }
            catch (Throwable) {
            }
            finally {
               // ! Hard exit — no shutdown handlers, no inherited output flush
               posix_kill(posix_getpid(), SIGKILL);
            }
         }

         // # Parent
         fclose($Writer);
         stream_set_blocking($Reader, false);
         $JSON = '';
         $deadline = time() + 15;
         while (time() < $deadline) {
            $chunk = fread($Reader, 4096);
            if (is_string($chunk) && $chunk !== '') {
               $JSON .= $chunk;
            }
            if (feof($Reader)) {
               break;
            }
            usleep(50000);
         }
         fclose($Reader);
         if ($pid > 0) {
            pcntl_waitpid($pid, $status);
         }

         $decoded = json_decode($JSON, true);
         $report = is_array($decoded) ? $decoded : [];
      }
      finally {
         @unlink($A);
         @unlink($B);
         @rmdir($directory);
      }

      $evidence = json_encode($report);

      yield new Assertion(
         description: 'init(B) after init(A) binds the controller to B',
         fallback: "The second path was ignored: {$evidence}"
      )
         ->expect([
            $report['a_init'] ?? null, $report['a_bound'] ?? null,
            $report['b_init'] ?? null, $report['b_bound'] ?? null
         ])
         ->to->be([true, true, true, true])
         ->assert();

      yield new Assertion(
         description: 'The bound descriptor is B\'s inode',
         fallback: "The handle does not point at B: {$evidence}"
      )
         ->expect($report['b_inode'] ?? null)
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'The descriptor bound to A is closed and its tracking map dropped by the rebind',
         fallback: "A's binding leaked into B: {$evidence}"
      )
         ->expect([$report['a_closed'] ?? null, $report['tracked_cleared'] ?? null])
         ->to->be([true, true])
         ->assert();

      yield new Assertion(
         description: 'Reservations land on B while A keeps its own count',
         fallback: "The reservation went to the wrong counter: {$evidence}"
      )
         ->expect([
            $report['peek'] ?? null, $report['reserve'] ?? null,
            $report['b_value'] ?? null, $report['a_value'] ?? null
         ])
         ->to->be([0, true, 7, 0])
         ->assert();

      yield new Assertion(
         description: 'init(B) again is idempotent: same inode, value kept',
         fallback: "The same path was re-created or reset: {$evidence}"
      )
         ->expect([
            $report['b_again'] ?? null, $report['b_same_inode'] ?? null,
            $report['b_value_again'] ?? null
         ])
         ->to->be([true, true, 7])
         ->assert();

      yield new Assertion(
         description: 'A worker binds B by path and reserves after A was reclaimed',
         fallback: "The worker bound the reclaimed A: {$evidence}"
      )
         ->expect([$report['worker'] ?? null, $report['b_after_worker'] ?? null])
         ->to->be(['reserved', 10])
         ->assert();
   })
);
