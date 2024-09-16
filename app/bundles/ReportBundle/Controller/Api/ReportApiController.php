<?php

namespace Mautic\ReportBundle\Controller\Api;

use DateTimeImmutable;
use DateTimeZone;
use Mautic\ApiBundle\Controller\CommonApiController;
use Mautic\CoreBundle\Security\Exception\PermissionException;
use Mautic\ReportBundle\Entity\Report;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\FilterControllerEvent;

class ReportApiController extends CommonApiController
{
    /**
     * @var FormFactoryInterface
     */
    private $formFactory;

    /**
     * {@inheritdoc}
     */
    public function initialize(FilterControllerEvent $event)
    {
        $this->model            = $this->getModel('report');
        $this->entityClass      = Report::class;
        $this->entityNameOne    = 'report';
        $this->entityNameMulti  = 'reports';
        $this->serializerGroups = ['reportList', 'reportDetails'];
        $this->formFactory      = $this->container->get('form.factory');

        parent::initialize($event);
    }

    /**
     * Obtains a compiled report.
     *
     * @param int $id Report ID
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function getReportAction($id)
    {
        $entity        = $this->model->getEntity($id);
        $repo          = $this->model->getRepository();
        $tableAlias    = $repo->getTableAlias();

        if (!$entity instanceof $this->entityClass) {
            return $this->notFound();
        }

        try {
            if (!$this->security->isGranted($this->permissionBase.':view')) {
                return $this->accessDenied();
            }
        } catch (PermissionException $e) {
            return $this->accessDenied($e->getMessage());
        }

        if ($this->security->checkPermissionExists($this->permissionBase.':viewother')
            && !$this->security->isGranted($this->permissionBase.':viewother')
        ) {
            $this->listFilters[] = [
                'column' => $tableAlias.'.createdBy',
                'expr'   => 'eq',
                'value'  => $this->user->getId(),
            ];
        }

        $reportData = $this->model->getReportData($entity, $this->formFactory, $this->getOptionsFromRequest());

        // Unset keys that we don't need to send back
        foreach (['graphs', 'contentTemplate', 'columns'] as $key) {
            unset($reportData[$key]);
        }

        return $this->handleView(
            $this->view($reportData, Response::HTTP_OK)
        );
    }

    /**
     * This method is careful to add new options from the request to keep BC.
     * It originally loaded all rows without any filter or pagination applied.
     *
     * @return array
     */
    private function getOptionsFromRequest()
    {
        $options = ['paginate'=> false, 'ignoreGraphData' => true];

        if ($this->request->query->has('dateFrom')) {
            $options['dateFrom'] = new DateTimeImmutable($this->request->query->get('dateFrom'), new DateTimeZone('UTC'));
        }

        if ($this->request->query->has('dateTo')) {
            $options['dateTo']   = new DateTimeImmutable($this->request->query->get('dateTo'), new DateTimeZone('UTC'));
        }

        if ($this->request->query->has('page')) {
            $options['page']     = $this->request->query->getInt('page');
            $options['paginate'] = true;
        }

        if ($this->request->query->has('limit')) {
            $options['limit']    = $this->request->query->getInt('limit');
            $options['paginate'] = true;
        }

        return $options;
    }
}
