<?php

namespace eCamp\Core\EntityService;

use Doctrine\ORM\ORMException;
use Doctrine\ORM\QueryBuilder;
use eCamp\Core\Entity\Camp;
use eCamp\Core\Entity\Day;
use eCamp\Core\Entity\Period;
use eCamp\Core\Hydrator\PeriodHydrator;
use eCamp\Lib\Acl\NoAccessException;
use eCamp\Lib\Entity\BaseEntity;
use eCamp\Lib\Service\EntityNotFoundException;
use eCamp\Lib\Service\EntityValidationException;
use eCamp\Lib\Service\ServiceUtils;
use Laminas\Authentication\AuthenticationService;

class PeriodService extends AbstractEntityService {
    protected DayService $dayService;
    protected ScheduleEntryService $scheduleEntryService;

    public function __construct(
        ServiceUtils $serviceUtils,
        AuthenticationService $authenticationService,
        DayService $dayService,
        ScheduleEntryService $scheduleEntryService
    ) {
        parent::__construct(
            $serviceUtils,
            Period::class,
            PeriodHydrator::class,
            $authenticationService
        );

        $this->dayService = $dayService;
        $this->scheduleEntryService = $scheduleEntryService;
    }

    /**
     * @throws ORMException
     * @throws EntityNotFoundException
     * @throws NoAccessException
     */
    protected function createEntity($data): Period {
        /** @var Camp $camp */
        $camp = $this->findRelatedEntity(Camp::class, $data, 'campId');

        /** @var Period $period */
        $period = parent::createEntity($data);

        $camp->addPeriod($period);

        return $period;
    }

    /**
     * @param $data
     *
     * @throws NoAccessException
     * @throws ORMException
     */
    protected function createEntityPost(BaseEntity $entity, $data): Period {
        /** @var Period $period */
        $period = $entity;

        $durationInDays = $period->getDurationInDays();
        for ($idx = 0; $idx < $durationInDays; ++$idx) {
            $this->dayService->create((object) [
                'periodId' => $period->getId(),
                'dayOffset' => $idx,
            ]);
        }

        return $period;
    }

    /**
     * @throws NoAccessException
     * @throws ORMException
     */
    protected function patchEntity(BaseEntity $entity, $data): Period {
        /** @var Period $period */
        $period = $entity;
        $oldStart = $period->getStart();

        /** @var Period $period */
        $period = parent::patchEntity($period, $data);
        $this->updatePeriodDays($period);

        $moveScheduleEntries = isset($data->moveScheduleEntries) ? $data->moveScheduleEntries : false;
        if (!$moveScheduleEntries) {
            $newStart = $period->getStart();
            $delta = $newStart->getTimestamp() - $oldStart->getTimestamp();
            $delta = $delta / 60;

            $this->updateScheduleEntries($period, $delta);
        }

        return $period;
    }

    /**
     * @throws ORMException
     * @throws NoAccessException
     */
    protected function updateEntity($id, $data): Period {
        /** @var Period $period */
        $period = parent::updateEntity($id, $data);
        $this->updatePeriodDays($period);

        // $moveActivities = isset($data->move_activities) ? $data->move_activities : null;
        // $this->updateScheduleEntries($period, $moveActivities);

        return $period;
    }

    protected function deleteEntity(BaseEntity $entity): void {
        /** @var Period $period */
        $period = $entity;
        $period->getCamp()->removePeriod($period);

        parent::deleteEntity($entity);
    }

    /**
     * @param $entity
     */
    protected function validateEntity(BaseEntity $entity): void {
        /** @var Period $period */
        $period = $entity;

        // Chcek for other overlapping Period
        $qb = $this->findCollectionQueryBuilder(Period::class, 'p', null)
            ->where('p.camp = :camp')
            ->andWhere('p.id != :id')
            ->andWhere('p.start <= :end')
            ->andWhere('p.end >= :start')
            ->setParameter('camp', $period->getCamp()->getId())
            ->setParameter('id', $period->getId())
            ->setParameter('start', $period->getStart())
            ->setParameter('end', $period->getEnd())
        ;
        $rows = $qb->getQuery()->getResult();

        if (count($rows) > 0) {
            $ex = new EntityValidationException();
            $ex->setMessages(['start' => ['noOverlap' => 'Periods may not overlap']]);

            throw $ex;
        }
    }

    protected function fetchAllQueryBuilder($params = []): QueryBuilder {
        $q = parent::fetchAllQueryBuilder($params);
        $q->andWhere($this->createFilter($q, Camp::class, 'row', 'camp'));

        if (isset($params['campId'])) {
            $q->andWhere('row.camp = :campId');
            $q->setParameter('campId', $params['campId']);
        }

        return $q;
    }

    protected function fetchQueryBuilder($id): QueryBuilder {
        $q = parent::fetchQueryBuilder($id);
        $q->andWhere($this->createFilter($q, Camp::class, 'row', 'camp'));

        return $q;
    }

    /**
     * @throws NoAccessException
     * @throws ORMException
     */
    private function updatePeriodDays(Period $period): void {
        $days = $period->getDays();
        $daysCountNew = $period->getDurationInDays();

        $daysToDelete = $days->filter(function (Day $day) use ($daysCountNew) {
            return $day->getDayOffset() >= $daysCountNew;
        });

        foreach ($daysToDelete as $day) {
            // @var Day $day
            $this->dayService->delete($day->getId());
        }

        for ($idx = 0; $idx < $daysCountNew; ++$idx) {
            $day = $days->filter(function (Day $day) use ($idx) {
                return $day->getDayOffset() == $idx;
            });

            if ($day->isEmpty()) {
                $this->dayService->create((object) [
                    'periodId' => $period->getId(),
                    'dayOffset' => $idx,
                ]);
            }
        }
    }

    /**
     * @throws NoAccessException
     */
    private function updateScheduleEntries(Period $period, int $delta): void {
        $scheduleEntries = $period->getScheduleEntries();
        foreach ($scheduleEntries as $scheduleEntry) {
            // @var ScheduleEntry $scheduleEntry
            $this->scheduleEntryService->patch($scheduleEntry->getId(), (object) [
                'periodOffset' => $scheduleEntry->getPeriodOffset() - $delta,
            ]);
        }
    }
}
