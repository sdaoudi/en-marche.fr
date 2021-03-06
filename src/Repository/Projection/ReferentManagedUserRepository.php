<?php

namespace AppBundle\Repository\Projection;

use AppBundle\Entity\Adherent;
use AppBundle\Entity\Projection\ReferentManagedUser;
use AppBundle\Referent\ManagedUsersFilter;
use AppBundle\Repository\ReferentTrait;
use AppBundle\ValueObject\Genders;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Internal\Hydration\IterableResult;
use Doctrine\ORM\Query\Expr\Andx;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Bridge\Doctrine\RegistryInterface;

class ReferentManagedUserRepository extends ServiceEntityRepository
{
    use ReferentTrait;

    public function __construct(RegistryInterface $registry)
    {
        parent::__construct($registry, ReferentManagedUser::class);
    }

    public function search(Adherent $referent, ManagedUsersFilter $filter = null): Paginator
    {
        $qb = $this->createFilterQueryBuilder($referent, $filter);
        $qb->andWhere('u.isMailSubscriber = 1');

        $query = $qb->getQuery();
        $query
            ->setFirstResult($filter ? $filter->getOffset() : 0)
            ->setMaxResults(ManagedUsersFilter::PER_PAGE)
            ->useResultCache(true)
            ->setResultCacheLifetime(1800) // 30 minutes
        ;

        return new Paginator($query);
    }

    public function createDispatcherIterator(Adherent $referent, ManagedUsersFilter $filter = null): IterableResult
    {
        $qb = $this->createFilterQueryBuilder($referent, $filter);
        $qb->andWhere('u.isMailSubscriber = 1');

        if ($filter) {
            $qb->setFirstResult($filter->getOffset());
        }

        return $qb->getQuery()->iterate();
    }

    private function createFilterQueryBuilder(Adherent $referent, ManagedUsersFilter $filter = null): QueryBuilder
    {
        $this->checkReferent($referent);

        $qb = $this->createQueryBuilder('u');
        $qb
            ->where('u.status = :status')
            ->setParameter('status', ReferentManagedUser::STATUS_READY)
            ->orderBy('u.createdAt', 'DESC')
        ;

        $tagsFilter = $qb->expr()->orX();

        foreach ($referent->getManagedArea()->getTags() as $key => $tag) {
            $tagsFilter->add("FIND_IN_SET(:tag_$key, u.subscribedTags) > 0");
            $tagsFilter->add(
                $qb->expr()->andX(
                    'u.country = \'FR\'',
                    $qb->expr()->like('u.committeePostalCode', ":tag_prefix_$key")
                )
            );
            $qb->setParameter("tag_$key", $tag->getCode());
            $qb->setParameter("tag_prefix_$key", $tag->getCode().'%');
        }

        $qb->andWhere($tagsFilter);

        if (!$filter) {
            return $qb;
        }

        if ($queryId = $filter->getQueryId()) {
            $queryId = array_map('intval', explode(',', $queryId));

            $idExpression = $qb->expr()->orX();
            foreach ($queryId as $key => $id) {
                $idExpression->add('u.id = :id_'.$key);
                $qb->setParameter('id_'.$key, $id);
            }

            $qb->andWhere($idExpression);
        }

        if ($queryAreaCode = $filter->getQueryAreaCode()) {
            $queryAreaCode = array_map('trim', explode(',', $queryAreaCode));

            $areaCodeExpression = $qb->expr()->orX();
            foreach ($queryAreaCode as $key => $areaCode) {
                if (is_numeric($areaCode)) {
                    $areaCodeExpression->add('u.postalCode LIKE :postalCode_'.$key.' OR u.committeePostalCode LIKE :postalCode_'.$key);
                    $qb->setParameter('postalCode_'.$key, $areaCode.'%');
                }

                if (\is_string($areaCode)) {
                    $areaCodeExpression->add('u.country LIKE :countryCode_'.$key);
                    $qb->setParameter('countryCode_'.$key, $areaCode.'%');
                }
            }

            $qb->andWhere($areaCodeExpression);
        }

        if (\in_array($filter->getQueryGender(), Genders::ALL)) {
            $qb
                ->andWhere('u.gender = :gender')
                ->setParameter('gender', $filter->getQueryGender())
            ;
        }

        if ($queryLastName = $filter->getQueryLastName()) {
            $qb
                ->andWhere('u.lastName LIKE :lastname')
                ->setParameter('lastname', '%'.$queryLastName.'%')
            ;
        }

        if ($queryFirstName = $filter->getQueryFirstName()) {
            $qb
                ->andWhere('u.firstName LIKE :firstName')
                ->setParameter('firstName', '%'.$queryFirstName.'%')
            ;
        }

        if ($queryAgeMinimum = $filter->getQueryAgeMinimum()) {
            $qb
                ->andWhere('u.age >= :ageMinimum')
                ->setParameter('ageMinimum', $queryAgeMinimum)
            ;
        }

        if ($queryAgeMaximum = $filter->getQueryAgeMaximum()) {
            $qb
                ->andWhere('u.age <= :ageMaximum')
                ->setParameter('ageMaximum', $queryAgeMaximum)
            ;
        }

        if ($queryCity = $filter->getQueryCity()) {
            $queryCity = array_map('trim', explode(',', $queryCity));

            $cityExpression = $qb->expr()->orX();
            foreach ($queryCity as $key => $city) {
                $cityExpression->add('u.city LIKE :city_'.$key);
                $qb->setParameter('city_'.$key, $city.'%');
            }

            $qb->andWhere($cityExpression);
        }

        foreach (array_values($filter->getQueryInterests()) as $key => $interest) {
            $qb
                ->andWhere(sprintf('FIND_IN_SET(:interest_%s, u.interests) > 0', $key))
                ->setParameter('interest_'.$key, $interest)
            ;
        }

        $typeExpression = $qb->expr()->orX();

        if ($filter->includeAdherentsNoCommittee()) {
            $typeExpression->add('u.type = :type_anc AND u.isCommitteeMember = 0');
            $qb->setParameter('type_anc', ReferentManagedUser::TYPE_ADHERENT);
        }

        if ($filter->includeAdherentsInCommittee()) {
            $typeExpression->add('u.type = :type_aic AND u.isCommitteeMember = 1');
            $qb->setParameter('type_aic', ReferentManagedUser::TYPE_ADHERENT);
        }

        if ($filter->includeHosts()) {
            $typeExpression->add('u.type = :type_h AND u.isCommitteeHost = 1');
            $qb->setParameter('type_h', ReferentManagedUser::TYPE_ADHERENT);
        }

        if ($filter->includeSupervisors()) {
            $and = new Andx();
            $and->add('u.type = :type_s AND u.isCommitteeSupervisor = 1');
            $qb->setParameter('type_s', ReferentManagedUser::TYPE_ADHERENT);

            $supervisorExpression = $qb->expr()->orX();
            foreach ($referent->getManagedAreaTagCodes() as $key => $code) {
                $supervisorExpression->add(sprintf('FIND_IN_SET(:code_%s, u.supervisorTags) > 0', $key));
                $qb->setParameter('code_'.$key, $code);
            }

            $and->add($supervisorExpression);
            $typeExpression->add($and);
        }

        if ($filter->includeCitizenProject()) {
            $typeExpression->add('json_length(u.citizenProjectsOrganizer) > 0');
        }

        $qb->andWhere($typeExpression);

        return $qb;
    }
}
