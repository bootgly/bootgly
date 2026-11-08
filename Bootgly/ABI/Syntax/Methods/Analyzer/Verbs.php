<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Syntax\Methods\Analyzer;


use function in_array;
use function str_ends_with;
use function strlen;
use function strtolower;


/**
 * The verb-form vocabulary of method names: tells a single-word name that is
 * not a base-form verb — a past form (`loaded`), a predicate (`has`), a
 * plural or third-person form (`exists`, `items`), a noun (`status`) or an
 * adjective (`valid`).
 *
 * A word list and suffixes, never a dictionary: what it cannot judge it
 * accepts. The `-ing` form always passes — a method that IS a loop takes it
 * (`reading()`), and whether a method loops is not a lexical question.
 * Words that are verbs too (`label`, `format`, `index`, `limit`, `empty`) are
 * never flagged.
 */
class Verbs
{
   // * Metadata
   /** Auxiliary and modal verbs — a method named after one asks, it does not act */
   private const array PREDICATES = [
      'is', 'are', 'am', 'was', 'were', 'be', 'been', 'has', 'have', 'had', 'does', 'did',
      'can', 'could', 'should', 'would', 'will', 'shall', 'must', 'may', 'might',
   ];
   /** Irregular past and participle forms */
   private const array IRREGULARS = [
      'began', 'begun', 'beaten', 'bitten', 'born', 'bought', 'bred', 'broke', 'broken', 'brought',
      'built', 'came', 'caught', 'chose', 'chosen', 'dealt', 'done', 'drawn', 'drew', 'driven',
      'fallen', 'fed', 'felt', 'fled', 'fought', 'forgotten', 'frozen', 'gave', 'given', 'gone',
      'got', 'gotten', 'grew', 'grown', 'held', 'hidden', 'hung', 'kept', 'knew', 'known', 'laid',
      'led', 'lost', 'made', 'meant', 'paid', 'ran', 'ridden', 'risen', 'said', 'sat', 'seen',
      'sent', 'shown', 'slept', 'sold', 'sought', 'sped', 'spent', 'spoke', 'spoken', 'spun',
      'stood', 'struck', 'stuck', 'swept', 'taken', 'taught', 'thought', 'threw', 'thrown',
      'told', 'took', 'understood', 'went', 'woken', 'won', 'wrote', 'written',
   ];
   /** Base-form verbs that end in `-ed` */
   private const array EDS = ['bed', 'embed', 'imbed', 'shed', 'shred', 'sled', 'wed'];
   /** Words that name a thing and are not used as verbs — a word that is a verb too
    *  (`request`, `type`, `name`, `value`, `state`, `result`) is not one of them */
   private const array NOUNS = [
      'body', 'child', 'children', 'client', 'config', 'configuration', 'connection', 'content',
      'context', 'cookie', 'data', 'duration', 'entry', 'footer', 'header', 'height', 'id',
      'identifier', 'info', 'instance', 'interval', 'item', 'kind', 'length', 'maximum',
      'metadata', 'method', 'minimum', 'mode', 'node', 'owner', 'password', 'path', 'payload',
      'priority', 'property', 'repository', 'response', 'schema', 'server', 'session', 'setting',
      'socket', 'status', 'summary', 'uri', 'url', 'user', 'width',
   ];
   /** Words that describe a state */
   private const array ADJECTIVES = [
      'alive', 'current', 'dead', 'false', 'first', 'full', 'last', 'null', 'previous', 'ready',
      'true', 'valid',
   ];
   /** Base-form verbs a suffix rule would misjudge */
   private const array VERBS = [
      // # -s
      'alias', 'bias', 'canvas',
      // # -able / -ible
      'cable', 'disable', 'enable', 'table',
      // # -ial
      'dial', 'trial',
      // # -tion / -sion
      'action', 'audition', 'auction', 'caution', 'commission', 'condition', 'envision',
      'function', 'mention', 'motion', 'partition', 'petition', 'portion', 'position',
      'provision', 'question', 'ration', 'sanction', 'section', 'station', 'transition',
      'version',
      // # -ness
      'harness', 'witness',
      // # -ment
      'augment', 'cement', 'comment', 'complement', 'decrement', 'document', 'experiment',
      'ferment', 'foment', 'fragment', 'implement', 'increment', 'lament', 'segment',
      'supplement', 'torment',
   ];
   /**
    * Suffixes of nouns and adjectives, with what they make of the word.
    *
    * @var array<string,string>
    */
   private const array SUFFIXES = [
      'able' => 'an adjective',
      'ible' => 'an adjective',
      'ful'  => 'an adjective',
      'ous'  => 'an adjective',
      'tive' => 'an adjective',
      'sive' => 'an adjective',
      'ical' => 'an adjective',
      'ual'  => 'an adjective',
      'ial'  => 'an adjective',
      'ity'  => 'a noun',
      'ness' => 'a noun',
      'ism'  => 'a noun',
      'tion' => 'a noun',
      'sion' => 'a noun',
      'ment' => 'a noun',
   ];


   /**
    * Check a single-word method name.
    *
    * @param string $name The method name (one word)
    *
    * @return null|string Why the name is not a base-form verb, or null when it passes
    */
   public function check (string $name): null|string
   {
      $word = strtolower($name);
      $length = strlen($word);

      // ?: The loop form, and the verbs a rule below would misjudge
      if (str_ends_with($word, 'ing') || in_array($word, self::VERBS, true)) {
         return null;
      }

      // ?: Asks instead of acting
      if (in_array($word, self::PREDICATES, true)) {
         return 'is a predicate';
      }

      // ?: Past forms
      if (in_array($word, self::IRREGULARS, true)) {
         return 'is a past form';
      }
      if ($length >= 4 && str_ends_with($word, 'ed')
         && str_ends_with($word, 'eed') === false && in_array($word, self::EDS, true) === false
      ) {
         return 'is a past form';
      }

      // ?: Things and states
      if (in_array($word, self::NOUNS, true)) {
         return 'is a noun';
      }
      if (in_array($word, self::ADJECTIVES, true)) {
         return 'is an adjective';
      }
      foreach (self::SUFFIXES as $suffix => $kind) {
         if ($length > strlen($suffix) + 1 && str_ends_with($word, $suffix)) {
            return "is {$kind}";
         }
      }

      // ?: A plural noun or a third-person form (`exists`, `items`) — not `-ss`, `-us`, `-is`
      if ($length >= 4 && str_ends_with($word, 's')
         && str_ends_with($word, 'ss') === false
         && str_ends_with($word, 'us') === false
         && str_ends_with($word, 'is') === false
      ) {
         return 'is a plural or third-person form';
      }

      // :
      return null;
   }
}
