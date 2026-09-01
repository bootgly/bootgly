<?php

namespace Bootgly\commands;


use const BOOTGLY_ROOT_BASE;
use const PHP_BINARY;
use function assert;
use function escapeshellarg;
use function file_put_contents;
use function glob;
use function mkdir;
use function rewind;
use function rmdir;
use function shell_exec;
use function str_contains;
use function stream_get_contents;
use function sys_get_temp_dir;
use function trim;
use function uniqid;
use function unlink;

use const Bootgly\CLI;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\CLI\Terminal\Output;


return new Test(
   description: '`project <Name> schedule` mounts the project and drives the schedule command — no server started',
   test: function () {
      // # Guards run in-process (they never reach the mount)
      $Command = new ProjectCommand;

      $render = static function (callable $call): string {
         $Host = new Output('php://memory');
         $Terminal = CLI->Terminal;
         $Restore = $Terminal->Output;
         $Terminal->Output = $Host;
         try {
            $call();
         }
         finally {
            $Terminal->Output = $Restore;
         }
         rewind($Host->stream);
         return (string) stream_get_contents($Host->stream);
      };

      $refused = null;
      $output = $render(static function () use ($Command, &$refused): void {
         $refused = $Command->schedule(['Demo/HTTP_Server_CLI', 'run'], ['dry-run' => true]);
      });
      yield assert(
         assertion: $refused === false && str_contains($output, 'Unknown option'),
         description: 'an option outside the schedule set is refused with the admit() alert'
      );

      $helped = null;
      $output = $render(static function () use ($Command, &$helped): void {
         $helped = $Command->schedule(['Demo/HTTP_Server_CLI', 'purge'], []);
      });
      yield assert(
         assertion: $helped === false && str_contains($output, 'schedule'),
         description: 'a missing name or unknown action falls back to the schedule help'
      );

      // # The real flow (mount + list) — BOOTGLY_PROJECT is once-per-process,
      //   so it runs in a child over a scratch working base
      $base = sys_get_temp_dir() . '/bootgly-schedule-' . uniqid();
      mkdir("$base/projects/Sched", 0o775, true);
      mkdir("$base/storage", 0o755, true);

      file_put_contents(
         "$base/projects/Bootgly.projects.php",
         "<?php return ['Sched' => ['interfaces' => ['CLI']]];"
      );
      file_put_contents("$base/projects/Sched/Sched.Project.php", <<<'PROJECT'
      <?php

      use Bootgly\API\Projects\Project;

      return new Project(
         boot: static function (): void {
            echo 'entry-ran;';
         },
         exportable: false,
         name: 'Sched'
      );
      PROJECT);
      file_put_contents("$base/projects/Sched/schedule.php", <<<'SCHEDULE'
      <?php

      use Bootgly\ACI\Schedule;

      return static function (Schedule $Schedule): void {
         $Schedule->add('sched.probe', static function (): void {})->repeat('*/5 * * * *');
      };
      SCHEDULE);

      $environment = 'BOOTGLY_SCHEDULE_ROOT=' . escapeshellarg(BOOTGLY_ROOT_BASE)
         . ' BOOTGLY_SCHEDULE_BASE=' . escapeshellarg($base);
      $fixture = escapeshellarg(__DIR__ . '/fixtures/schedule_probe.php');
      $output = (string) shell_exec("$environment " . escapeshellarg(PHP_BINARY) . " $fixture 2>/dev/null");

      yield assert(
         assertion: str_contains($output, 'sched.probe') && str_contains($output, '*/5 * * * *'),
         description: 'the project\'s schedule.php resolves and its jobs list — got: ' . trim($output)
      );

      yield assert(
         assertion: str_contains($output, 'mounted:Sched;') && str_contains($output, 'entry-ran;') === false,
         description: 'the project is mounted (constant defined) and its boot entry never runs'
      );

      yield assert(
         assertion: str_contains($output, 'stamp:;'),
         description: '`schedule list` mounts the project but never claims an instance: records stay unstamped'
      );

      yield assert(
         assertion: str_contains($output, 'enrolled:pid;'),
         description: 'enroll() (the `run` branch) stamps the worker PID as the record instance after the mount'
      );

      // # A from-scratch create scaffolds schedule.php (tokens filled, zero jobs → hint)
      $output = (string) shell_exec(
         "$environment BOOTGLY_SCHEDULE_CREATE=1 " . escapeshellarg(PHP_BINARY) . " $fixture 2>/dev/null"
      );

      yield assert(
         assertion: str_contains($output, 'scaffold:yes;') && str_contains($output, 'token:filled;'),
         description: 'project create materializes a schedule.php with its run instructions — got: '
            . trim($output)
      );

      yield assert(
         assertion: str_contains($output, 'No jobs registered'),
         description: 'the scaffolded (all-commented) schedule lists a hint instead of silence'
      );

      // @ Cleanup (the created project included)
      foreach ((array) glob("$base/storage/pids/*") as $file) {
         @unlink((string) $file);
      }
      foreach ((array) glob("$base/projects/Fresh/tests/example/*") as $file) {
         @unlink((string) $file);
      }
      foreach ((array) glob("$base/projects/Fresh/tests/*") as $file) {
         @unlink((string) $file);
      }
      foreach (['tests/example', 'tests'] as $dir) {
         @rmdir("$base/projects/Fresh/$dir");
      }
      foreach ((array) glob("$base/projects/Fresh/*") as $file) {
         @unlink((string) $file);
      }
      @rmdir("$base/projects/Fresh");
      @unlink("$base/projects/Sched/schedule.php");
      @unlink("$base/projects/Sched/Sched.Project.php");
      @unlink("$base/projects/Bootgly.projects.php");
      @rmdir("$base/projects/Sched");
      @rmdir("$base/projects");
      @rmdir("$base/storage/pids");
      @rmdir("$base/storage");
      @rmdir($base);
   }
);
