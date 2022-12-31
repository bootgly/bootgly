<?php
#require_once 'imports/autoload.php';

namespace Bootgly\Web;

use Bootgly\Examples\Doctrine\Entity\Phone;
use Bootgly\Examples\Doctrine\Entity\Student;
use Bootgly\Examples\Doctrine\Helper\EntityManagerCreator;


// ! Entity
$Entity = EntityManagerCreator::createEntityManager();
// ! Repository
$Repository = $Entity->getRepository(Student::class);


// @ Create
$Student = new Student('Rodrigo Vieira');
$Student->phone = new Phone('(99) 99999-9999');
$Entity->persist($Student);

$Entity->flush();
// @ Read
/*
$students = $Repository->findAll();
foreach ($students as $Student) {
   echo "ID: $Student->id<br>Name: $Student->name<br><br>";

   echo implode(', ', $Student->phones
      ->map(fn (Phone $Phone) => $Phone->number)
      ->toArray());

   echo "<br>";
}
*/
// @ Update
/*
$Student = $Repository->find(2);
$Student->name = 'Rodrigo Bootgly';
$Entity->flush();
*/
// @ Delete
/*
$Student = $Entity->find(Student::class, 4);
$Entity->remove($Student);
$Entity->flush();
*/

// $Web->Router->boot();
