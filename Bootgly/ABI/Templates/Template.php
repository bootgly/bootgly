<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Templates;


use const BOOTGLY_STORAGE_DIR;
use const BOOTGLY_VERSION;
use const EXTR_SKIP;
use function array_key_exists;
use function call_user_func;
use function clearstatcache;
use function count;
use function extract;
use function file_put_contents;
use function filemtime;
use function func_get_arg;
use function function_exists;
use function is_dir;
use function is_file;
use function is_string;
use function mkdir;
use function ob_end_clean;
use function ob_get_clean;
use function ob_get_level;
use function ob_start;
use function opcache_invalidate;
use function preg_match;
use function preg_replace;
use function preg_replace_callback;
use function preg_replace_callback_array;
use function rename;
use function sha1;
use function str_contains;
use function str_replace;
use function uniqid;
use Throwable;

use Bootgly\ABI\Data\__String\Path;
use Bootgly\ABI\IO\FS\File;
use Bootgly\ABI\Templates;
use Bootgly\ABI\Templates\Template\Exceptions\TemplateException;


class Template implements Templates
{
   public const string EXTENSION = '.template.php';

   private Iterators $Iterators; // @phpstan-ignore-line

   // * Config
   /**
    * Base directory used to resolve named templates (@extends, @include, @component).
    */
   public static string $path = '';

   // * Data
   public static Directives $Directives;
   public readonly string|File $raw;

   // * Metadata
   private null|string $file;
   // Cache
   private string $cache;
   /**
    * Compiled cache file => source template file (null = inline template).
    * @var array<string,null|string>
    */
   private static array $sources = [];
   // Pipeline
   // 1
   /**
    * Verbatim regions extracted before, and restored after, the directive pass.
    * @var array<int,string>
    */
   private array $verbatims = [];
   // 1.1
   private string $precompiled;
   // 1.2
   private string $compiled;
   // 1.3
   private string $postcompiled;
   // 2
   public protected(set) string $output;


   public function __construct (string|File $raw)
   {
      // Used to preload Iterators
      $this->Iterators = new Iterators;

      // * Config
      // ...

      // * Data
      self::$Directives ??= new Directives;
      $this->raw = $raw;

      // * Metadata
      $this->file = ($raw instanceof File)
         ? ($raw->file ?: null)
         : null;
      // Cache
      // ...
      // Pipeline
      $this->output = '';
   }

   private function precompile (string $raw, bool $minify = true, bool $unindent = false): bool
   {
      $precompiled = $raw;

      try {
         // @ Extract verbatim regions — restored untouched after the directive pass
         $this->verbatims = [];
         $verbatims = &$this->verbatims;
         $store = static function (string $content) use (&$verbatims): string {
            $index = count($verbatims);
            $verbatims[] = $content;

            // NUL-delimited placeholder: unmatchable by any directive pattern
            return "\0BOOTGLY:V{$index}\0";
         };
         // Escaped tokens (@@!: / @@!;) emit their literal form
         $precompiled = (string) preg_replace_callback(
            '/@(@!:|@!;)/',
            static fn (array $matches): string => $store($matches[1]),
            $precompiled
         );
         // Verbatim blocks (@!: ... @!;) pass through untouched
         $precompiled = (string) preg_replace_callback(
            '/@!:(.*?)@!;/s',
            static fn (array $matches): string => $store($matches[1]),
            $precompiled
         );

         $directives = self::$Directives->tokens;

         if ($minify) {
            $minified = preg_replace(
               "/(?<!\S)(@[$directives].*[:;])\s+/m",
               '$1',
               $precompiled
            );
            $precompiled = (string) $minified;
         }

         if ($unindent) {
            $unindented = preg_replace(
               "/^[\t ]+(@[$directives].*[:;])/m",
               '$1',
               $precompiled
            );
            $precompiled = (string) $unindented;
         }
      }
      catch (Throwable) {
         $precompiled = '';
      }

      $this->precompiled = $precompiled;

      return true;
   }
   private function compile (): bool
   {
      $Directives  = &self::$Directives;
      $precompiled = &$this->precompiled;

      if ($precompiled === '') {
         return false;
      }

      try {
         $compiled = preg_replace_callback_array(
            pattern: $Directives->directives,
            subject: $precompiled,
         );
      }
      catch (Throwable) {
         return false;
      }

      if (!is_string($compiled)) {
         return false;
      }

      $this->compiled = $compiled;

      return true;
   }
   private function postcompile (): bool
   {
      $compiled = &$this->compiled;

      try {
         // Merge adjacent PHP blocks keeping every captured newline:
         // compiled line N must stay equal to template line N (error mapping)
         $postcompiled = preg_replace_callback(
            '/\?>(\n+)<\?php /m',
            static fn (array $matches): string => $matches[1],
            $compiled
         );

         // @ Restore verbatim regions as literal text
         //   The captured bytes go back verbatim EXCEPT PHP open tags: every
         //   `<?` (covering `<?php`, `<?=`, `<?`, `<?xml`) is neutralized so a
         //   verbatim block renders as text instead of executing — raw PHP
         //   stays the job of `@:` … `@;`. Only the two-byte `<?` is rewritten,
         //   so all other bytes (and every newline) keep their position and
         //   template<->compiled line parity holds for error mapping.
         if (is_string($postcompiled) && $this->verbatims !== []) {
            $verbatims = $this->verbatims;

            $postcompiled = preg_replace_callback(
               '/\x00BOOTGLY:V(\d+)\x00/',
               static function (array $matches) use ($verbatims): string {
                  $content = $verbatims[(int) $matches[1]] ?? '';

                  // Neutralize PHP open tags without adding/removing newlines
                  return str_replace('<?', "<?php echo '<?'; ?>", $content);
               },
               $postcompiled
            );
         }
      }
      catch (Throwable) {
         return false;
      }

      if (!is_string($postcompiled)) {
         return false;
      }

      $this->postcompiled = $postcompiled;

      return true;
   }

   private function cache (): void
   {
      // !
      $file = $this->file;

      // # Key: file templates by path (edits overwrite); inline templates by content.
      // Salted with the framework version: upgrades may change directive output,
      // so caches compiled by an older Bootgly must not be reused.
      $key = ($file !== null)
         ? sha1(BOOTGLY_VERSION . $file)
         : sha1(BOOTGLY_VERSION . $this->raw);
      $cache = $this->cache = BOOTGLY_STORAGE_DIR . "cache/templates/{$key}.php";

      // ? Warm cache
      // Long-running workers: bypass the PHP stat cache to see external edits
      if ($file !== null) {
         clearstatcache(filename: $file);
      }
      if (
         is_file($cache)
         && ($file === null || filemtime($file) < filemtime($cache))
      ) {
         self::$sources[$cache] ??= $file;

         return;
      }

      // # Contents
      $raw = $this->raw;
      if ($raw instanceof File) {
         $contents = $raw->contents;

         // ? Unreadable source template
         if ($contents === false) {
            throw new TemplateException(
               "Unreadable template source file `{$file}`.",
               $file
            );
         }

         $raw = $contents;
      }

      // @ Compile (an empty template compiles to an empty cache)
      $postcompiled = '';
      if ($raw !== '') {
         $this->precompile($raw, minify: false, unindent: true);

         $compiled = $this->compile();
         if ($compiled === false) {
            throw new TemplateException(
               'Template compilation failed.',
               $file
            );
         }

         $this->postcompile();

         $postcompiled = $this->postcompiled;
      }
      // Debug trailer (trailer only — a header would break template<->compiled line parity)
      if ($file !== null) {
         $postcompiled .= "<?php /* FILE: {$file} */ ?>";
      }

      // @ Write atomically (temp file + rename), never leaving partial caches behind
      $directory = BOOTGLY_STORAGE_DIR . 'cache/templates';
      if (is_dir($directory) === false) {
         @mkdir($directory, recursive: true);
      }

      $temporary = "{$cache}." . uniqid('', true) . '.tmp';
      if (
         @file_put_contents($temporary, $postcompiled) === false
         || @rename($temporary, $cache) === false
      ) {
         throw new TemplateException(
            "Unwritable template cache file `{$cache}`.",
            $file
         );
      }

      if (function_exists('opcache_invalidate')) {
         opcache_invalidate($cache, true);
      }

      self::$sources[$cache] = $file;
   }

   /**
    * Resolve a template name to its file inside Template::$path.
    *
    * @throws TemplateException When the path is unset, the name is invalid or the file is missing.
    */
   public static function resolve (string $name): File
   {
      // ? No base path configured
      if (self::$path === '') {
         throw new TemplateException(
            "Unresolvable template `{$name}`: no Template::\$path configured."
         );
      }
      // ? Names are author-controlled, but still jailed (mirrors the F-12 view guard)
      if (
         $name === ''
         || str_contains($name, "\0")
         || $name[0] === '/'
         || preg_match('#^[\w/-]+$#', $name) !== 1
      ) {
         throw new TemplateException("Invalid template name `{$name}`.");
      }

      // !
      $name = Path::normalize($name);
      $path = self::$path;

      $File = new File("{$path}{$name}" . self::EXTENSION, base: $path);

      // ? Missing template file
      if ($File->exists === false) {
         throw new TemplateException("Template `{$name}` not found in `{$path}`.");
      }

      // :
      return $File;
   }
   /**
    * Render another template inline (@include), sharing the current frame.
    *
    * @param array<string,mixed> $variables
    */
   public static function include (string $name, array $variables = []): string
   {
      return new Template(self::resolve($name))->execute($variables);
   }
   /**
    * Compose the current component frame (@component; closer): render the
    * component template against its captured slots, then close the frame.
    */
   public static function compose (): string
   {
      // !
      [$component, $variables] = Sections::seal();

      // ? Orphan closer (no component frame open)
      if ($component === null) {
         return '';
      }

      // @
      try {
         // : The component template reads its slots from the still-open frame
         return new Template(self::resolve($component))->execute($variables);
      }
      finally {
         Sections::close();
      }
   }

   /**
    * Render the template, composing its inheritance chain (@extends).
    *
    * @param array<string,mixed> $parameters
    *
    * @throws TemplateException With file/line pointing at the template source.
    *
    * @return string
    */
   public function render (array $parameters = []): string
   {
      // !
      $level = ob_get_level();
      $depth = Sections::open();

      // @
      try {
         $output = $this->execute($parameters);

         // @@ Compose the inheritance chain (same frame, same parameters)
         $chain = [];
         while (($extend = Sections::pull()) !== null) {
            [$parent, $origin, $line] = $extend;
            // The @extends source location (origin = compiled cache path)
            $template = ($origin !== null)
               ? (self::$sources[$origin] ?? null)
               : null;

            try {
               $File = self::resolve($parent);
            }
            catch (TemplateException $Exception) {
               // ?: Re-locate the failure at the @extends directive line
               $location = ($template === null && $line !== null)
                  ? " at line {$line}"
                  : '';
               throw new TemplateException(
                  "{$Exception->getMessage()}{$location}",
                  $template,
                  $line,
                  $Exception
               );
            }

            // ? Cycle / runaway chain guard
            if (isSet($chain[$File->file]) || count($chain) >= 32) {
               throw new TemplateException(
                  "Template inheritance cycle detected at `{$parent}`.",
                  $template,
                  $line
               );
            }
            $chain[$File->file] = true;

            // The parent replaces the child loose output
            $output = new Template($File)->execute($parameters);
         }

         // :
         return $this->output = $output;
      }
      catch (Throwable $Throwable) {
         Iterators::reset();

         // ?: Already located at a template source line
         if ($Throwable instanceof TemplateException && $Throwable->located) {
            throw $Throwable;
         }

         // Map the compiled cache location back to the template source
         [$template, $line] = $this->locate($Throwable);

         // ?: Engine-level failure with no compiled frame to map — rethrow as is
         if (
            $Throwable instanceof TemplateException
            && $template === null && $line === null
         ) {
            throw $Throwable;
         }

         // :
         $location = ($line !== null) ? " at line {$line}" : '';
         throw new TemplateException(
            "Template error{$location}: {$Throwable->getMessage()}",
            $template,
            $line,
            $Throwable
         );
      }
      finally {
         // Close this render's frame (plus any frame leaked by failures)...
         while (Sections::$depth >= $depth && Sections::$depth > 0) {
            Sections::close();
         }
         // ...and drain any output buffer left open by failures
         while (ob_get_level() > $level) {
            @ob_end_clean();
         }
      }
   }

   /**
    * Execute one compiled template (no frame, no inheritance composition).
    *
    * @param array<string,mixed> $parameters
    */
   private function execute (array $parameters): string
   {
      $this->cache();

      if (ob_start() === false) {
         throw new TemplateException(
            'Unable to start template output buffering.',
            $this->file
         );
      }

      // ! Security: the compiled cache path and the parameter bag are read via
      //   func_get_arg(), never as named variables, so no engine internal
      //   (e.g. $__file__, $parameters) leaks into the template scope. The
      //   include target is func_get_arg(0) — a user `__file__` key cannot
      //   redirect it into arbitrary file inclusion. call_user_func (not an
      //   inline IIFE) keeps the two arguments explicit and formatter-safe.
      call_user_func(
         static function (): void { // @phpstan-ignore arguments.count
            /** @var array<string,mixed> $__data__ */
            $__data__ = func_get_arg(1);
            extract($__data__, EXTR_SKIP);
            unset($__data__);

            include func_get_arg(0); // @phpstan-ignore argument.type
         },
         $this->cache,
         $parameters
      );

      // :
      return (string) ob_get_clean();
   }

   /**
    * Locate the template source of a Throwable raised inside a compiled cache.
    *
    * @return array{null|string,null|int}
    */
   private function locate (Throwable $Throwable): array
   {
      // ? The Throwable was raised directly in a compiled cache file
      $file = $Throwable->getFile();
      if (array_key_exists($file, self::$sources)) {
         return [self::$sources[$file], $Throwable->getLine()];
      }

      // @ Walk the trace for the nearest compiled cache frame
      foreach ($Throwable->getTrace() as $frame) {
         $file = $frame['file'] ?? '';

         if (array_key_exists($file, self::$sources)) {
            return [self::$sources[$file], $frame['line'] ?? null];
         }
      }

      // : No compiled frame found (engine-internal failure)
      return [null, null];
   }
}
