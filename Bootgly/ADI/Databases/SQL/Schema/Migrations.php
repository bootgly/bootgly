<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Schema;


use function basename;
use function date;
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
 * Migration file discovery, loading and creation.
 */
class Migrations
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
    * Discover migration files ordered by filename.
    *
    * @return array<string,string>
    */
   public function discover (): array
   {
      $files = glob($this->path . '/*.php') ?: [];
      sort($files);

      $migrations = [];
      foreach ($files as $file) {
         $migrations[$this->resolve($file)] = $file;
      }

      return $migrations;
   }

   /**
    * Load one migration file.
    */
   public function load (string $file): Migration
   {
      $Migration = require $file;

      if ($Migration instanceof Migration === false) {
         throw new InvalidArgumentException('Migration file must return a Migration object.');
      }

      if ($Migration->name === '') {
         $Migration->rename($this->resolve($file));
      }

      return $Migration;
   }

   /**
    * Create one migration file stub and return its path.
    */
   public function create (string $name): string
   {
      if (is_dir($this->path) === false) {
         mkdir($this->path, 0775, true);
      }

      $slug = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_') ?: 'migration';
      $file = $this->path . '/' . date('YmdHis') . '_' . $slug . '.php';

      file_put_contents($file, $this->render());

      return $file;
   }

   /**
    * Resolve one migration name from its file path.
    */
   public function resolve (string $file): string
   {
      return basename($file, '.php');
   }

   /**
    * Render the default migration stub.
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

use Bootgly\ADI\Databases\SQL\Schema\Auxiliaries\Defaults;
use Bootgly\ADI\Databases\SQL\Schema\Auxiliaries\Keys;
use Bootgly\ADI\Databases\SQL\Schema\Auxiliaries\References;
use Bootgly\ADI\Databases\SQL\Schema\Auxiliaries\Types;
use Bootgly\ADI\Databases\SQL\Schema\Blueprint;
use Bootgly\ADI\Databases\SQL\Schema\Migrating;
use Bootgly\ADI\Databases\SQL\Schema\Migration;


return new Migration(
   Up: function (Migrating $Schema) {
      return $Schema->create('example', function (Blueprint $Table): void {
         // @ Define columns here.
      });
   },
   Down: function (Migrating $Schema) {
      return $Schema->drop('example');
   }
);
PHP;
   }
}
