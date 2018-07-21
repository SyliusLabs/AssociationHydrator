<?php

declare(strict_types=1);

namespace SyliusLabs\AssociationHydrator;

use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Persistence\Mapping\ClassMetadata;
use Doctrine\ORM\EntityManager;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessor;

final class AssociationHydrator
{
    /** @var EntityManager */
    private $entityManager;

    /** @var ClassMetadata */
    private $classMetadata;

    /** @var PropertyAccessor */
    private $propertyAccessor;

    public function __construct(EntityManager $entityManager, ClassMetadata $classMetadata, ?PropertyAccessor $propertyAccessor = null)
    {
        $this->entityManager = $entityManager;
        $this->classMetadata = $classMetadata;
        $this->propertyAccessor = $propertyAccessor ?? PropertyAccess::createPropertyAccessor();
    }

    /**
     * @param mixed $subjects
     * @param iterable|string[] $associationsPaths
     */
    public function hydrateAssociations($subjects, iterable $associationsPaths): void
    {
        foreach ($associationsPaths as $associationPath) {
            $this->hydrateAssociation($subjects, $associationPath);
        }
    }

    /**
     * @param mixed $subjects
     * @param string $associationPath
     */
    public function hydrateAssociation($subjects, string $associationPath): void
    {
        if (null === $subjects || [] === $subjects) {
            return;
        }

        $initialAssociations = explode('.', $associationPath);
        $finalAssociation = array_pop($initialAssociations);
        $subjects = $this->normalizeSubject($subjects);

        $classMetadata = $this->classMetadata;
        foreach ($initialAssociations as $initialAssociation) {
            $subjects = array_reduce($subjects, function (array $accumulator, $subject) use ($initialAssociation) {
                $subject = $this->propertyAccessor->getValue($subject, $initialAssociation);

                return array_merge($accumulator, $this->normalizeSubject($subject));
            }, []);

            if ([] === $subjects) {
                return;
            }

            $classMetadata = $this->entityManager->getClassMetadata($classMetadata->getAssociationTargetClass($initialAssociation));
        }

        $this->entityManager->createQueryBuilder()
            ->select('PARTIAL subject.{id}')
            ->addSelect('associations')
            ->from($classMetadata->name, 'subject')
            ->leftJoin(sprintf('subject.%s', $finalAssociation), 'associations')
            ->where('subject IN (:subjects)')
            ->setParameter('subjects', $this->array_unique($subjects))
            ->getQuery()
            ->getResult()
        ;
    }
    
    /**
     * @param mixed $subjects
     *
     * @return array|mixed[]
     */
    private function array_unique(array $subjects): array
    {
        $uniqueSubjects = array();
        foreach($subjects as $curSubject) {
           $curSubjectClass = $this->entityManager->getClassMetadata(get_class($curSubject))->name;
           $curSubjectId = $this->entityManager->getUnitOfWork()->getEntityIdentifier($curSubject);
           $uniqueSubjects[$curSubjectClass.'___'.implode('__', $curSubjectId)] = $curSubject;
        }
        return array_values($uniqueSubjects);
    }

    /**
     * @param mixed $subject
     *
     * @return array|mixed[]
     */
    private function normalizeSubject($subject): array
    {
        if ($subject instanceof Collection) {
            return $subject->toArray();
        }

        if (!is_array($subject)) {
            return [$subject];
        }

        return $subject;
    }
}
