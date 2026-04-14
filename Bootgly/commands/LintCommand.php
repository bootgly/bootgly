<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\commands;


use function array_slice;
use function count;
use function fclose;
use function file_put_contents;
use function fwrite;
use function implode;
use function is_array;
use function is_dir;
use function is_file;
use function json_encode;
use function proc_close;
use function proc_open;
use function rtrim;
use function sort;
use function str_contains;
use function str_ends_with;
use function str_pad;
use function str_replace;
use function str_starts_with;
use function stream_get_contents;
use function strlen;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const PHP_EOL;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use Bootgly\ABI\Data\Syntax\Builtins;
use Bootgly\ABI\Data\Syntax\Imports;
use Bootgly\API\Environment\Agent;
use const Bootgly\CLI;
use Bootgly\CLI\Command;
use Bootgly\CLI\UI\Components\Alert;
use Bootgly\CLI\UI\Components\Fieldset;


class LintCommand extends Command
{
   // * Config
   public int $group = 1;

   // * Data
   public string $name = 'lint';
   public string $description = 'Lint and fix import code style violations';

   /** @var array<string,array{description:string,arguments:array<string,string>}> */
   public array $arguments = [ // @phpstan-ignore property.phpDocType
      'imports' => [
         'description' => 'Lint import code style for use statements',
         'arguments'   => [
            '[path]' => 'File or directory path (default: Bootgly/)'
         ]
      ],
   ];

   /** @var array<string,array<string>> */
   public array $options = [
      'Increase the verbosity of the command' => ['-v', '-vv', '-vvv'],
      'Auto-fix violations' => ['--fix'],
      'Show changes without writing' => ['--dry-run'],
      'Show help information' => ['--help'],
   ];


   public function run (array $arguments = [], array $options = []): bool
   {
      // @ --help flag → show help
      if ( isset($options['help']) ) {
         return $this->help($arguments);
      }

      // @ Route subcommand
      $submodule = $arguments[0] ?? null;

      if ( $submodule === null || !isset($this->arguments[$submodule]) ) {
         return $this->help($arguments);
      }

      return $this->lint(
         $submodule,
         array_slice($arguments, 1),
         $options
      );
   }

   // # Lint
   /**
    * @param array<int,string> $arguments
    * @param array<string,mixed> $options
    */
   private function lint (string $submodule, array $arguments, array $options): bool
   {
      $Output = CLI->Terminal->Output;

      // ! Agent detection
      $Agent = Agent::detect();

      // ! Path
      $path = $arguments[0] ?? null;

      if ($path === null) {
         $path = BOOTGLY_WORKING_DIR . 'Bootgly/';
      }
      else if (!str_starts_with($path, '/')) {
         $path = BOOTGLY_WORKING_DIR . $path;
      }

      // ! Options
      $fix = isset($options['fix']);
      $dryRun = isset($options['dry-run']);

      // @ Collect PHP files
      $files = $this->collect($path);

      if (count($files) === 0) {
         $Output->render("@.;@#Yellow: No PHP files found in: {$path} @;@..;");
         return true;
      }

      // @ Section title (human output)
      $title = 'Lint > ' . \ucfirst($submodule);
      if (!$Agent->detected) {
         $Output->render("@.;@#Cyan: {$title} @;@.;");
      }

      // @ Analyze
      $totalIssues = 0;
      $totalFiles = 0;
      $fixedFiles = 0;

      /** @var array<int,array{file:string,issues:array<int,array{type:string,symbol:string,kind:string,line:int,message:string}>,fixed:bool}> */
      $report = [];

      // * imports
      if ($submodule === 'imports') {
         Builtins::load();
      }

      $Imports = new Imports;

      foreach ($files as $file) {
         $Result = $Imports->analyze($file);

         if (!$Result->failed()) {
            continue;
         }

         $totalFiles++;
         $issueCount = count($Result->issues);
         $totalIssues += $issueCount;

         $relativePath = str_replace(BOOTGLY_WORKING_DIR, '', $file);
         $fixed = false;

         // @ Display (human output)
         if (!$Agent->detected) {
            $Output->render("@.;@#White: {$relativePath} @;\n");

            foreach ($Result->issues as $Issue) {
               $Output->render("  @#Red: ✗ @; Line {$Issue->line}: {$Issue->message}\n");
            }
         }

         // @ Fix
         if ($fix || $dryRun) {
            $corrected = $Imports->format($Result);

            if ($dryRun) {
               if (!$Agent->detected) {
                  $Output->render("@#Cyan:   [dry-run] Would fix {$issueCount} issue(s) @;\n\n");
               }
            }
            else {
               // @ Validate syntax before writing
               if ($this->validate($corrected)) {
                  file_put_contents($file, $corrected);
                  $fixedFiles++;
                  $fixed = true;

                  if (!$Agent->detected) {
                     $Output->render("@#Green:   ✓ Fixed {$issueCount} issue(s) @;\n\n");
                  }
               }
               else {
                  if (!$Agent->detected) {
                     $Output->render("@#Red:   ✗ Fix produced invalid PHP — skipped @;\n\n");
                  }
               }
            }
         }

         // @ Collect report entry
         if ($Agent->detected) {
            $issueEntries = [];
            foreach ($Result->issues as $Issue) {
               $issueEntries[] = [
                  'type'    => $Issue->type,
                  'symbol'  => $Issue->symbol,
                  'kind'    => $Issue->kind,
                  'line'    => $Issue->line,
                  'message' => $Issue->message,
               ];
            }

            $report[] = [
               'file'   => $relativePath,
               'issues' => $issueEntries,
               'fixed'  => $fixed,
            ];
         }
      }

      $Output->render("\n");

      // @ Output
      if ($Agent->detected) {
         // @ JSON output for AI agents
         echo json_encode([
            'result'    => $totalIssues > 0 && !$fix ? 'failed' : 'passed',
            'submodule' => $submodule,
            'agent'     => $Agent->name,
            'mode'      => $fix ? 'fix' : ($dryRun ? 'dry-run' : 'check'),
            'files'     => [
               'scanned' => count($files),
               'failed'  => $totalFiles,
               'fixed'   => $fixedFiles,
            ],
            'issues' => [
               'total' => $totalIssues,
            ],
            'report' => $report,
         ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

         return $totalIssues === 0 || $fix;
      }

      // @ Summary (human output)
      if ($totalIssues === 0) {
         $Output->render("@.;@#Green: ✓ No import issues found. @;@.;\n");
         return true;
      }

      $Output->render("@#Yellow: Found {$totalIssues} issue(s) in {$totalFiles} file(s). @;@..;");

      if ($fixedFiles > 0) {
         $Output->render("@#Green: ✓ Fixed {$fixedFiles} file(s). @;@..;");
      }

      // @ Exit with failure in check mode
      if (!$fix && !$dryRun) {
         return false;
      }

      return true;
   }

   // # Help
   /**
    * @param array<int,string> $arguments
    */
   public function help (array $arguments): bool
   {
      $Output = CLI->Terminal->Output;

      // @
      $output = '';
      $status = true;

      if ( empty($arguments) ) {
         $Output->write(PHP_EOL);

         // # Arguments
         $content = '';
         foreach ($this->arguments as $name => $value) {
            /** @var array{description: string, arguments: array<string,string>}|string $value */
            $description = is_array($value) ? $value['description'] : $value;
            $label = $name;
            $content .= '@#Yellow:' . $name . '@;';
            $content .= str_pad('', 10 - strlen($label)) . '  ' . $description . PHP_EOL;
         }
         $content = rtrim($content);
         $Fieldset = new Fieldset($Output);
         $Fieldset->title = '@#Cyan: Lint arguments @;';
         $Fieldset->content = $content;
         $Fieldset->render();

         // # Options
         $optContent = '';
         foreach ($this->options as $desc => $flags) {
            $flagStr = implode(', ', $flags);
            $optContent .= '@#Yellow:' . $flagStr . '@;';
            $optContent .= str_pad('', 14 - strlen($flagStr)) . '  ' . $desc . PHP_EOL;
         }
         $optContent = rtrim($optContent);
         $Fieldset = new Fieldset($Output);
         $Fieldset->title = '@#Cyan: Lint options @;';
         $Fieldset->content = $optContent;
         $Fieldset->render();

         // # Usage
         $Fieldset = new Fieldset($Output);
         $Fieldset->title = '@#green: Lint usage @;';
         $Fieldset->content = 'bootgly lint @#Black: <submodule> @;@.;';
         $Fieldset->content .= 'bootgly lint @#Black: <submodule> [path] @;@.;';
         $Fieldset->content .= 'bootgly lint @#Black: <submodule> [path] --fix @;';
         $Fieldset->render();

         // # Examples
         $examples = '@#Black:bootgly lint imports@;' . PHP_EOL;
         $examples .= '@#Black:bootgly lint imports Bootgly/ABI/@;' . PHP_EOL;
         $examples .= '@#Black:bootgly lint imports --fix@;' . PHP_EOL;
         $examples .= '@#Black:bootgly lint imports --dry-run@;' . PHP_EOL;
         $examples .= '@#Black:bootgly lint imports Bootgly/ABI/ --fix@;';
         $Fieldset = new Fieldset($Output);
         $Fieldset->title = '@#green: Lint examples @;';
         $Fieldset->content = $examples;
         $Fieldset->render();
      }
      else if ( isSet($this->arguments[$arguments[0]]) ) {
         // @ Show usage for a valid submodule
         $submodule = $arguments[0];
         /** @var array{description: string, arguments: array<string,string>} $meta */
         $meta = $this->arguments[$submodule];

         $Output->write(PHP_EOL);
         $Output->render("@#Black: {$meta['description']}@;@.;");

         // @ Show arguments if any
         if ( !empty($meta['arguments']) ) {
            $argLines = '';
            foreach ($meta['arguments'] as $arg => $argDesc) {
               $argLines .= '@#cyan:' . str_pad($arg, 9) . '@; ' . $argDesc . PHP_EOL;
            }
            $argLines = rtrim($argLines);

            $Fieldset = new Fieldset($Output);
            $Fieldset->title = '@#Cyan: Lint ' . $submodule . ' arguments @;';
            $Fieldset->content = $argLines;
            $Fieldset->render();
         }

         // # Usage
         $Fieldset = new Fieldset($Output);
         $Fieldset->title = '@#Cyan: Lint ' . $submodule . ' usage @;';
         $Fieldset->content = 'bootgly lint ' . $submodule . ' @#Black: [path] @;' . PHP_EOL
            . 'bootgly lint ' . $submodule . ' @#Black: [path] --fix @;' . PHP_EOL
            . 'bootgly lint ' . $submodule . ' @#Black: --dry-run @;';
         $Fieldset->render();

         // # Example
         $Fieldset = new Fieldset($Output);
         $Fieldset->title = '@#Cyan: Lint ' . $submodule . ' example @;';
         $Fieldset->content = '@#Black:bootgly lint ' . $submodule . '@;' . PHP_EOL
            . '@#Black:bootgly lint ' . $submodule . ' Bootgly/ABI/@;' . PHP_EOL
            . '@#Black:bootgly lint ' . $submodule . ' --fix@;';
         $Fieldset->render();
      }
      else {
         $status = false;

         // @ Show invalid argument alert then general help
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "Invalid argument: @#cyan:{$arguments[0]}@;.";
         $Alert->render();

         $this->help([]);

         return false;
      }

      $output .= '@.;';

      $Output->render($output);

      return $status;
   }

   /**
    * Collect all PHP files from a path.
    *
    * @param string $path File or directory path
    *
    * @return array<int,string>
    */
   private function collect (string $path): array
   {
      if (is_file($path)) {
         return [$path];
      }

      if (!is_dir($path)) {
         return [];
      }

      $files = [];
      /** @var RecursiveIteratorIterator<RecursiveDirectoryIterator> $iterator */
      $iterator = new RecursiveIteratorIterator(
         new RecursiveDirectoryIterator($path)
      );

      /** @var \SplFileInfo $file */
      foreach ($iterator as $file) {
         if ($file->isFile() && str_ends_with($file->getPathname(), '.php')) {
            $pathname = $file->getPathname();

            // @ Skip vendor, tests, examples
            if (str_contains($pathname, '/vendor/')
               || str_contains($pathname, '/tests/')
               || str_contains($pathname, '/examples/')
               || str_contains($pathname, '/vs/')
            ) {
               continue;
            }

            $files[] = $pathname;
         }
      }

      sort($files);

      return $files;
   }

   /**
    * Validate PHP syntax of source code using php -l.
    *
    * @param string $source PHP source code to validate
    *
    * @return bool True if syntax is valid
    */
   private function validate (string $source): bool
   {
      $process = proc_open(
         'php -l',
         [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
         ],
         $pipes
      );

      if ($process === false) {
         return true; // If we can't validate, allow the write
      }

      fwrite($pipes[0], $source);
      fclose($pipes[0]);

      $output = stream_get_contents($pipes[1]);
      fclose($pipes[1]);
      fclose($pipes[2]);

      $exitCode = proc_close($process);

      return $exitCode === 0 && str_contains($output !== false ? $output : '', 'No syntax errors');
   }
}
