<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Seed;


use function basename;
use function file_exists;
use function file_put_contents;
use function glob;
use function is_dir;
use function mkdir;
use function preg_replace;
use function sort;
use function strtolower;
use function trim;
use InvalidArgumentException;


/**
 * Seeder file discovery, loading and creation.
 */
class Seeders
{
   // * Config
   public private(set) string $path;

   // * Data
   // ...

   // * Metadata
   // ...


   public function __construct (string $path)
   {
      // * Config
      $this->path = $path;
   }

   /**
    * Discover seeder files ordered by filename.
    *
    * @return array<string,string>
    */
   public function discover (): array
   {
      $files = glob("{$this->path}/*.php") ?: [];
      sort($files);

      $seeders = [];
      foreach ($files as $file) {
         $seeders[$this->resolve($file)] = $file;
      }

      return $seeders;
   }

   /**
    * Load one seeder file.
    */
   public function load (string $file): Seeder
   {
      $Seeder = require $file;

      if ($Seeder instanceof Seeder === false) {
         throw new InvalidArgumentException('Seeder file must return a Seeder object.');
      }

      if ($Seeder->name === '') {
         $Seeder->name($this->resolve($file));
      }

      return $Seeder;
   }

   /**
    * Create one seeder file stub and return its path.
    */
   public function create (string $name): string
   {
      if (is_dir($this->path) === false) {
         mkdir($this->path, 0775, true);
      }

      $slug = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_') ?: 'seeder';
      $file = "{$this->path}/{$slug}.php";

      if (file_exists($file)) {
         throw new InvalidArgumentException("Seeder already exists: {$slug}.");
      }

      file_put_contents($file, $this->render());

      return $file;
   }

   /**
    * Resolve one seeder name from its file path.
    */
   public function resolve (string $file): string
   {
      return basename($file, '.php');
   }

   /**
    * Render the default seeder stub.
    */
   private function render (): string
   {
      return <<<'PHP'
<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Seed;
use Bootgly\ADI\Databases\SQL\Seed\Seeder;


// ? Keep seeder files return-only. Avoid top-level class/function declarations
// ? because seeder files may be required more than once in one process.
return new Seeder(
   Run: function (SQL $Database, Seed $Seed) {
      // @ Return one query, a list of queries, or null.
      return null;
   }
);
PHP;
   }
}
