<?php
namespace Bootgly\Examples\Doctrine\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\OneToMany;

#[Entity]
class Student
{
   // * Imports
   #[OneToMany(mappedBy: "Student", targetEntity: Phone::class, cascade: ["persist", "remove"])]
   public Collection $phones;
   #[ManyToMany(targetEntity: Course::class, inversedBy: "students")]
   public Collection $courses;

   // * Data
   #[Id, GeneratedValue, Column]
   public int $id;
   #[Column]
   public string $name;


   public function __construct () {
      $this->phones = new ArrayCollection();
      $this->courses = new ArrayCollection();
   }
   public function __get (string $name)
   {
      switch ($name) {
         default:
            return $this->$name;
      }
   }
   public function __set (string $name, $value) {
      switch ($name) {
         case 'phone':
            return $this->addPhone($value);
         default:
            return $this->$name = $value;
      }
   }

   public function addPhone (Phone $Phone)
   {
      $this->phones[] = $Phone;
      $Phone->setStudent($this);
   }
}