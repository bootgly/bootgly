<?php

use function Bootgly\ABI\remove_recursively;
use Bootgly\ACI\Queues;
use Bootgly\ACI\Queues\Job;
use Bootgly\ACI\Queues\tests\Recorder;
use Bootgly\ACI\Tests\Doubles\Fake\Clock;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;

require_once __DIR__ . '/Recorder.php';


return new Test(
   description: 'File driver writes jobs through File->replace(): plain-write mode, lazy directories, link replaced, no temporary left',
   test: function () {
      // ! The permission bits of an entry
      $mode = static function (string $path): int {
         clearstatcache(true);

         return (int) fileperms($path) & 0777;
      };
      // ! Every entry of a directory but the dot entries
      $list = static function (string $directory): array {
         $entries = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
         sort($entries);

         return $entries;
      };
      $directory = Temporaries::reserve('queue-write-replace');
      $previous = umask(0027);

      try {
         $Queues = new Queues(['path' => "{$directory}/queues", 'clock' => new Clock(1_000_000_000)]);

         // @ The first enqueue creates `ready/` lazily and gets the plain-write mode
         $Queue = $Queues->fetch('default');
         $Job = new Job(Recorder::class, ['n' => 1]);
         $enqueued = $Queue->enqueue($Job);
         $ready = "{$directory}/queues/default/ready";
         $name = sprintf('%011d-%s.job', 1_000_000_000, $Job->id);
         yield assert(
            assertion: $enqueued === true && is_file("{$ready}/{$name}") && $mode("{$ready}/{$name}") === 0640,
            description: 'enqueue() creates ready/ lazily and writes the job with 0666 minus the umask (0640 under 0027), got: '
               . (is_file("{$ready}/{$name}") ? decoct($mode("{$ready}/{$name}")) : 'no file')
         );
         yield assert(
            assertion: $list($ready) === [$name],
            description: 'no temporary is left beside the job, got: ' . json_encode($list($ready))
         );

         // @ A link planted at the job's name is replaced, never written through
         $victim = "{$directory}/victim.txt";
         file_put_contents($victim, 'untouched');
         $Linked = new Job(Recorder::class, ['n' => 2]);
         $linked = sprintf('%011d-%s.job', 1_000_000_000, $Linked->id);
         symlink($victim, "{$ready}/{$linked}");
         $replaced = $Queue->enqueue($Linked);
         clearstatcache(true);
         yield assert(
            assertion: $replaced === true
               && is_link("{$ready}/{$linked}") === false
               && file_get_contents($victim) === 'untouched'
               && unserialize((string) file_get_contents("{$ready}/{$linked}"), ['allowed_classes' => [Job::class]]) instanceof Job,
            description: 'a symlink at the job path is replaced by the job, its target is left untouched'
         );

         // @ release() rewrites the job into ready/ through the same writer
         $Reserved = $Queue->reserve();
         $released = $Reserved instanceof Job && $Queue->release($Reserved, 60);
         $rewritten = $Reserved instanceof Job ? sprintf('%011d-%s.job', 1_000_000_060, $Reserved->id) : '';
         yield assert(
            assertion: $released === true && is_file("{$ready}/{$rewritten}") && $mode("{$ready}/{$rewritten}") === 0640,
            description: 'release() rewrites the job into ready/ with the plain-write mode'
         );

         // @ A queue whose root cannot hold directories refuses the write
         file_put_contents("{$directory}/blocked", '');
         $Blocked = new Queues(['path' => "{$directory}/blocked", 'clock' => new Clock(1_000_000_000)])->fetch('default');
         yield assert(
            assertion: $Blocked->enqueue(new Job(Recorder::class, ['n' => 3])) === false,
            description: 'enqueue() answers false when ready/ cannot be created'
         );
      }
      finally {
         umask($previous);
         remove_recursively($directory);
      }

      yield assert(
         assertion: is_dir($directory) === false,
         description: 'the temporary directory is cleaned up'
      );
   }
);
