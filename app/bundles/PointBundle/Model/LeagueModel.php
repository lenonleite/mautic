<?php

declare(strict_types=1);

namespace Mautic\PointBundle\Model;

use Mautic\CoreBundle\Model\FormModel as CommonFormModel;
use Mautic\PointBundle\Entity\League;
use Mautic\PointBundle\Entity\LeagueRepository;
use Mautic\PointBundle\Event as Events;
use Mautic\PointBundle\Form\Type\LeagueType;
use Mautic\PointBundle\LeagueEvents;
use Symfony\Component\Form\FormFactory;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * @extends CommonFormModel<League>
 */
class LeagueModel extends CommonFormModel
{
    public function getRepository(): LeagueRepository
    {
        $result = $this->em->getRepository(League::class);
        \assert($result instanceof LeagueRepository);

        return $result;
    }

    public function getPermissionBase(): string
    {
        return 'point:leagues';
    }

    /**
     * {@inheritdoc}
     *
     * @param object               $entity
     * @param FormFactory          $formFactory
     * @param string|null          $action
     * @param array<string,string> $options
     *
     * @return mixed
     *
     * @throws MethodNotAllowedHttpException
     */
    public function createForm($entity, $formFactory, $action = null, $options = [])
    {
        if (!$entity instanceof League) {
            throw new MethodNotAllowedHttpException(['League']);
        }

        if (!empty($action)) {
            $options['action'] = $action;
        }

        return $formFactory->create(LeagueType::class, $entity, $options);
    }

    /**
     * Get a specific entity or generate a new one if id is empty.
     *
     * @param int $id
     *
     * @return object|null
     */
    public function getEntity($id = null)
    {
        if (null === $id) {
            return new League();
        }

        return parent::getEntity($id);
    }

    /**
     * {@inheritdoc}
     *
     * @throws MethodNotAllowedHttpException
     */
    protected function dispatchEvent($action, &$entity, $isNew = false, Event $event = null)
    {
        if (!$entity instanceof League) {
            throw new MethodNotAllowedHttpException(['League']);
        }

        switch ($action) {
            case 'pre_save':
                $name = LeagueEvents::LEAGUE_PRE_SAVE;
                break;
            case 'post_save':
                $name = LeagueEvents::LEAGUE_POST_SAVE;
                break;
            case 'pre_delete':
                $name = LeagueEvents::LEAGUE_PRE_DELETE;
                break;
            case 'post_delete':
                $name = LeagueEvents::LEAGUE_POST_DELETE;
                break;
            default:
                return null;
        }

        if ($this->dispatcher->hasListeners($name)) {
            if (empty($event)) {
                $event = new Events\LeagueEvent($entity, $isNew);
                $event->setEntityManager($this->em);
            }

            $this->dispatcher->dispatch($event, $name);

            return $event;
        }

        return null;
    }
}
