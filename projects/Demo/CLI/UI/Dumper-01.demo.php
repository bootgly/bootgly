<?php
namespace Bootgly\CLI;

use const Bootgly\CLI;
use Bootgly\CLI\UI\Atoms\Dumper;

$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI UI - Dumper component @;
 * @#yellow: @@: Demo 56 - Example #1 - structured value dumps in the terminal @;
 * {$location}
 */\n\n
OUTPUT);

enum DumperDemoStatus: string
{
   case Active = 'active';
}

class DumperDemoUser
{
   public string $name = 'Rodrigo';
   public readonly int $id;
   protected string $email = 'rodrigo@bootgly.com';
   private string $hash = 'c4ff33b00757a4a1b0075';
   public DumperDemoStatus $status = DumperDemoStatus::Active;
   public null|DumperDemoUser $manager = null;
   protected string $token;


   public function __construct ()
   {
      $this->id = 7;
   }
}

// @ Scalars and arrays — typed literals, counted headers
$Dumper = new Dumper($Output);
$Dumper->value = [
   'framework' => 'Bootgly',
   'version' => 1.0,
   'stable' => true,
   'previous' => null,
   'ports' => [80, 443]
];
$Dumper->render();

$Output->write("\n");

// @ Objects — visibility sigils, readonly, enums, closures, cycles
$User = new DumperDemoUser;
$User->manager = $User; // ← a true cycle marks *RECURSION*

$Dumper = new Dumper($Output);
$Dumper->value = [
   'user' => $User,
   'boot' => static function () {
      return true;
   }
];
$Dumper->render();

$Output->write("\n");

// @ Caps — depth, items and string truncation stay readable
$Dumper = new Dumper($Output);
$Dumper->items = 3;
$Dumper->strings = 12;
$Dumper->value = [
   'hash' => 'f00dfacefeedc0ffeebadd1e5',
   'fibonacci' => [1, 1, 2, 3, 5, 8, 13],
   'nested' => ['deep' => ['deeper' => ['deepest' => true]]]
];
$Dumper->depth = 2;
$Dumper->render();
