<?php

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Tests\RegistryLifecycle;


use function assert;
use RuntimeException;
use stdClass;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resource;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources;


final class TrackingResource extends Resource
{
   public int $cleans = 0;
   public bool $fail = false;

   public function clean (): void
   {
      $this->cleans++;

      if ($this->fail) {
         throw new RuntimeException('clean rejected');
      }
   }
}


return new Test(
   description: 'Response Resources releases displaced lifecycle ownership',
   test: function () {
      // # Same-name replacement retains only the currently mounted object.
      $Resources = new Resources;
      $First = new TrackingResource(persistent: true, scoped: true);
      $Second = new TrackingResource(persistent: true, scoped: true);
      $Resources->set('Scoped', $First);
      $Resources->set('Scoped', $Second);
      $Resources->reset();

      yield assert(
         assertion: $Resources->fetch('Scoped') === $Second
            && $First->cleans === 1
            && $Second->cleans === 1
            && $Resources->resettable,
         description: 'set() stops cleaning the displaced same-name scoped resource',
      );

      $Resources->set('Scoped', new TrackingResource(persistent: true));

      yield assert(
         assertion: $Resources->resettable === false,
         description: 'replacing the final scoped resource restores the idle reset gate',
      );

      // # One object mounted under aliases stays tracked until its final name
      //   is replaced and is cleaned only once per reset.
      $Aliases = new Resources;
      $Shared = new TrackingResource(persistent: true, scoped: true);
      $ReplacementA = new TrackingResource(persistent: true, scoped: true);
      $ReplacementB = new TrackingResource(persistent: true, scoped: true);
      $Aliases->set('A', $Shared);
      $Aliases->set('B', $Shared);
      $Aliases->set('A', $ReplacementA);
      $Aliases->reset();
      $aliasesPreserved = $Shared->cleans === 1
         && $ReplacementA->cleans === 1;
      $Aliases->set('B', $ReplacementB);
      $Aliases->reset();

      yield assert(
         assertion: $aliasesPreserved
            && $Shared->cleans === 2
            && $ReplacementA->cleans === 2
            && $ReplacementB->cleans === 1,
         description: 'replacing one alias preserves ownership until the last alias is removed',
      );

      $Unscoped = new TrackingResource(persistent: true);
      $Aliases->set('A', $Unscoped);
      $Aliases->set('B', $Unscoped);

      yield assert(
         assertion: $Aliases->resettable === false,
         description: 'removing all scoped aliases clears lifecycle metadata exactly once',
      );

      // # Re-defining a materialized name unmounts its old scoped instance.
      $Definitions = new Resources(Context: new stdClass);
      $Displaced = new TrackingResource(persistent: true, scoped: true);
      $Built = new TrackingResource(persistent: true, scoped: true);
      $Definitions->set('Dynamic', $Displaced);
      $Definitions->define(
         'Dynamic',
         static fn (object $Context): TrackingResource => $Built,
      );
      $idleAfterDefine = $Definitions->resettable === false;
      $Definitions->reset();
      $Fetched = $Definitions->fetch('Dynamic');
      $Definitions->reset();

      yield assert(
         assertion: $idleAfterDefine
            && $Displaced->cleans === 1
            && $Fetched === $Built
            && $Built->cleans === 1,
         description: 'define() releases a materialized resource before lazy replacement',
      );

      // # A fork drops materialized definition-backed instances and builds its
      //   own instance only when fetched in the new response context.
      /** @var array<int,TrackingResource> $Builds */
      $Builds = [];
      $Defined = new Resources(Context: new stdClass);
      $Defined->define(
         'Defined',
         static function (object $Context) use (&$Builds): TrackingResource {
            $Resource = new TrackingResource(persistent: true, scoped: true);
            $Builds[] = $Resource;

            return $Resource;
         },
      );
      $Original = $Defined->fetch('Defined');
      $Fork = $Defined->fork(
         static fn (Resource $Resource): Resource => $Resource,
         new stdClass,
      );
      $forkIdle = $Fork->resettable === false;
      $Fork->reset();
      $Forked = $Fork->fetch('Defined');
      $Fork->reset();

      yield assert(
         assertion: $forkIdle
            && count($Builds) === 2
            && $Original instanceof TrackingResource
            && $Original->cleans === 0
            && $Forked instanceof TrackingResource
            && $Forked !== $Original
            && $Forked->cleans === 1,
         description: 'fork() does not inherit scoped ownership from a materialized definition',
      );

      // # A fork attach hook may substitute another instance; the cloned
      //   registry must release the source object after publishing the result.
      $Source = new Resources;
      $SourceResource = new TrackingResource(persistent: true, scoped: true);
      $Attached = new TrackingResource(persistent: true, scoped: true);
      $Source->set('User', $SourceResource);
      $Hooked = $Source->fork(
         static fn (Resource $Resource): Resource => $Attached,
         new stdClass,
      );
      $Hooked->reset();

      yield assert(
         assertion: $Hooked->fetch('User') === $Attached
            && $SourceResource->cleans === 0
            && $Attached->cleans === 1,
         description: 'fork() tracks the attach-hook result instead of the displaced source',
      );

      // # Ephemeral scoped resources are cleaned once, unmounted, and removed
      //   from Scoped so subsequent resets stay idle.
      $Ephemerals = new Resources;
      $Ephemeral = new TrackingResource(scoped: true);
      $Ephemerals->set('Ephemeral', $Ephemeral);
      $Ephemerals->reset();
      $ephemeralReleased = $Ephemerals->fetch('Ephemeral') === null
         && $Ephemerals->resettable === false;
      $Ephemerals->reset();

      yield assert(
         assertion: $ephemeralReleased && $Ephemeral->cleans === 1,
         description: 'reset() releases ephemeral scoped ownership after one cleanup',
      );

      // # Attaching a replacement can fail before publication; the old mapping
      //   and ownership must remain intact.
      $Existing = new TrackingResource(persistent: true, scoped: true);
      $Rejected = new TrackingResource(persistent: true, scoped: true);
      $Atomic = new Resources(
         Attach: static function (Resource $Resource) use ($Rejected): Resource {
            if ($Resource === $Rejected) {
               throw new RuntimeException('attach rejected');
            }

            return $Resource;
         },
         Context: new stdClass,
      );
      $Atomic->set('Atomic', $Existing);
      $threw = false;

      try {
         $Atomic->set('Atomic', $Rejected);
      }
      catch (RuntimeException) {
         $threw = true;
      }

      $Atomic->reset();

      yield assert(
         assertion: $threw
            && $Atomic->fetch('Atomic') === $Existing
            && $Existing->cleans === 1
            && $Rejected->cleans === 0,
         description: 'set() preserves existing ownership when the attach hook rejects replacement',
      );

      // # A displaced cleanup may throw after publication. The replacement
      //   stays mounted and the released object cannot poison later resets.
      $Throwing = new Resources;
      $Released = new TrackingResource(persistent: true, scoped: true);
      $Published = new TrackingResource(persistent: true, scoped: true);
      $Released->fail = true;
      $Throwing->set('Throwing', $Released);
      $cleanupThrew = false;

      try {
         $Throwing->set('Throwing', $Published);
      }
      catch (RuntimeException $RuntimeException) {
         $cleanupThrew = $RuntimeException->getMessage() === 'clean rejected';
      }

      $Throwing->reset();

      yield assert(
         assertion: $cleanupThrew
            && $Throwing->fetch('Throwing') === $Published
            && $Released->cleans === 1
            && $Published->cleans === 1,
         description: 'throwing displacement cleanup keeps publication and lifecycle metadata coherent',
      );
   },
);
