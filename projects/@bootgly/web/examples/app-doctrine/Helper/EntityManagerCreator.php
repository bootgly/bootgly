<?php
namespace Bootgly\Examples\Doctrine\Helper;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;

class EntityManagerCreator
{
   public static function createEntityManager (): EntityManager
   {
      $config = ORMSetup::createAttributeMetadataConfiguration(
         paths: [__DIR__."/../"],
         isDevMode: true,
      );

      $conn = [
         'driver' => 'pdo_sqlite',
         'path' => __DIR__ . '/../database/db.sqlite',
      ];

      return EntityManager::create($conn, $config);
   }
}