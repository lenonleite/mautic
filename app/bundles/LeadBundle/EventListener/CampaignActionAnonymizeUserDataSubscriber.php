<?php

namespace Mautic\LeadBundle\EventListener;

use Doctrine\ORM\EntityManager;
use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use Mautic\CampaignBundle\Event\PendingEvent;
use Mautic\CoreBundle\Model\AuditLogModel;
use Mautic\EmailBundle\Model\EmailStatModel;
use Mautic\FormBundle\Model\SubmissionModel;
use Mautic\LeadBundle\Entity\Company;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadField;
use Mautic\LeadBundle\Form\Type\CampaignActionAnonymizeUserDataType;
use Mautic\LeadBundle\Helper\AnonymizeHelper;
use Mautic\LeadBundle\LeadEvents;
use Mautic\LeadBundle\Model\CompanyModel;
use Mautic\LeadBundle\Model\FieldModel;
use Mautic\LeadBundle\Model\LeadModel;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CampaignActionAnonymizeUserDataSubscriber implements EventSubscriberInterface
{
    public const KEY_EVENT_NAME = 'lead.action_anonymizeuserdata';

    public const COLUMNS_ACEPPTED = ['text', 'longtext'];

    public function __construct(
        private LeadModel $leadModel,
        private FieldModel $fieldModel,
        private CompanyModel $companyModel,
        private LoggerInterface $logger,
        private EmailStatModel $emailStatModel,
        private EntityManager $entityManager,
        private AuditLogModel $auditLogModel,
        private SubmissionModel $submissionModel,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CampaignEvents::CAMPAIGN_ON_BUILD                  => ['configureAction', 0],
            LeadEvents::ON_CAMPAIGN_ACTION_ANONYMIZE_USER_DATA => ['anonymizeUserData', 0],
        ];
    }

    public function configureAction(CampaignBuilderEvent $event): void
    {
        $event->addAction(
            self::KEY_EVENT_NAME,
            [
                'label'                  => 'mautic.lead.lead.events.anonymize',
                'description'            => 'mautic.lead.lead.events.anonymize_descr',
                // Kept for BC in case plugins are listening to the shared trigger
                'eventName'              => LeadEvents::ON_CAMPAIGN_TRIGGER_ACTION,
                'formType'               => CampaignActionAnonymizeUserDataType::class,
                'batchEventName'         => LeadEvents::ON_CAMPAIGN_ACTION_ANONYMIZE_USER_DATA,
            ]
        );
    }

    public function anonymizeUserData(PendingEvent $event): void
    {
        if (!$event->checkContext(self::KEY_EVENT_NAME)) {
            return;
        }

        $properties       = $event->getEvent()->getProperties();
        $pseudonymize     = $properties['pseudonymize'] ?? false;
        $leads            = $this->leadModel->getRepository()->findBy(['id' => $event->getContactIds()]);
        $companies        = $this->getCompaniesByLeads($event->getContactIds());

        $idFields   = array_merge($properties['fieldsToAnonymize'], $properties['fieldsToDelete']);
        $fields     = $this->fieldModel->getRepository()->findBy(['id' => $idFields]);

        foreach ($fields as $field) {
            if (in_array($field->getId(), $properties['fieldsToDelete'])) {
                [$leads,$companies] = $this->setDeleteFields($leads, $companies, $field);
                continue;
            }

            if (in_array($field->getId(), $properties['fieldsToAnonymize'])) {
                $leadsCompanyColumnsLength = $this->getLeadCompanyColumnsLenght();
                [$leads,$companies]        = $this->setHashFields($leads, $companies, $field, $pseudonymize, $leadsCompanyColumnsLength, $event);
            }
        }

        if (in_array($field->getId(), $properties['fieldsToAnonymize']) || in_array($field->getId(), $properties['fieldsToDelete'])) {
            $this->deleteFormResults($event);
        }

        if (!empty($leads)) {
            $this->leadModel->saveEntities($leads);
        }

        if (!empty($companies)) {
            $this->companyModel->saveEntities($companies);
        }

        $event->passAll();
    }

    /**
     * @param array<int> $leadIds
     *
     * @return array<Company>
     */
    private function getCompaniesByLeads(array $leadIds): array
    {
        $companiesByLead  = $this->companyModel->getRepository()->getCompaniesForContacts($leadIds);
        $companiesId      = [];
        foreach ($companiesByLead as $companies) {
            foreach ($companies as $company) {
                $companiesId[] = $company['id'];
            }
        }

        return $this->companyModel->getRepository()->findBy(['id' => $companiesId]);
    }

    /**
     * @param array<Lead>    $leads
     * @param array<Company> $companies
     *
     * @return array<int,array<mixed>>
     */
    private function setDeleteFields(array $leads, array $companies, LeadField $field): array
    {
        return [
            $this->setLeadsCompaniesFieldNull($leads, $field),
            $this->setLeadsCompaniesFieldNull($companies, $field),
        ];
    }

    /**
     * @param array<Lead|Company> $leadsCompanies
     *
     * @return array<mixed>
     */
    private function setLeadsCompaniesFieldNull(array $leadsCompanies, LeadField $field): array
    {
        foreach ($leadsCompanies as $key => $leadCompany) {
            if (!method_exists($leadCompany, 'addUpdatedField') || !method_exists($leadCompany, 'getField')) {
                continue;
            }

            if ($leadCompany instanceof Lead) {
                $leadField = $leadCompany->getField($field->getAlias());
                if (false !== $leadField) {
                    $leadsCompanies[$key] = $leadCompany->addUpdatedField($field->getAlias(), null);
                    continue;
                }
            }

            if ($leadCompany instanceof Company) {
                $this->companyModel->setFieldValues($leadCompany, [$field->getAlias()=>null]);
            }
        }

        return $leadsCompanies;
    }

    /**
     * @param array<Lead>    $leads
     * @param array<Company> $companies
     *
     * @return array<int,array<mixed>>
     */
    private function setHashFields(array $leads, array $companies, LeadField $field, bool $pseudonymize): array
    {
        return [
            $this->setHashes($leads, $field, $pseudonymize),
            $this->setHashes($companies, $field, $pseudonymize),
        ];
    }

    /**
     * @param array<Lead>|array<Company> $leadsCompanies
     *
     * @return array<mixed>
     */
    private function setHashes(array $leadsCompanies, LeadField $field, bool $pseudonymize): array
    {
        foreach ($leadsCompanies as $key => $leadCompany) {
            if (!method_exists($leadCompany, 'getField')) {
                continue;
            }
            if ($leadCompany instanceof Company) {
                $leadField = $leadCompany->getField($field->getAlias());
                if (false === $leadField) {
                    continue;
                }
            }
            $leadField = $leadCompany->getField($field->getAlias());
            if (false === $leadField) {
                continue;
            }

            $field     = $this->fieldModel->getRepository()->find($leadField['id']);

            if (null === $field) {
                continue;
            }

            $leadsCompanies[$key] = $this->setHash($leadCompany, $leadField, $field, $pseudonymize);
        }

        return $leadsCompanies;
    }

    /**
     * @param array<string, string|null> $field
     */
    private function setHash(
        Company|Lead $leadOrCompany,
        array $field,
        LeadField $leadField,
        bool $pseudonymize
    ): Lead|Company {
        if (empty($field['value'])) {
            return $leadOrCompany;
        }

        try {
            if ('email' === $field['type']) {
                $valueAnonymized = AnonymizeHelper::email($field['value'], $pseudonymize);
                if ($leadField->getCharLengthLimit() < strlen($valueAnonymized)) {
                    $valueAnonymized = $this->formatHashEmail($valueAnonymized, $leadField->getCharLengthLimit());
                }
                $this->updateEmailStatusValues($field['value'], $valueAnonymized, $pseudonymize);
            } else {
                $valueAnonymized = AnonymizeHelper::text($field['value'], $pseudonymize);
            }

            if ($leadField->getCharLengthLimit() < strlen($valueAnonymized) && 'email' === $field['type']) {
                $valueAnonymized = $this->formatHashEmail($valueAnonymized, $leadField->getCharLengthLimit());
            } elseif ($leadField->getCharLengthLimit() < strlen($valueAnonymized)) {
                $valueAnonymized = substr($valueAnonymized, 0, $leadField->getCharLengthLimit());
            }
            $leadOrCompany->addUpdatedField($leadField->getAlias(), $valueAnonymized);
            $this->updateAuditLogs($leadOrCompany);
        } catch (\Exception $e) {
            // Do nothing
            $this->logger->error('AnonymizeUserDataSubscriber setHash fail: '.$e->getMessage());
        }

        return $leadOrCompany;
    }

    private function updateAuditLogs($leadOrCompany): void
    {
        $auditLogs = $this->auditLogModel->getRepository()->findBy([
            'bundle'   => 'lead',
            'object'   => 'lead',
            'objectId' => $leadOrCompany->getId(),
            'action'   => 'update',
        ]);

        foreach ($auditLogs as $auditLog) {
            $this->auditLogModel->getRepository()->deleteEntity($auditLog);
        }
    }

    private function formatHashEmail(string $email, int $limit): string
    {
        // Extract the domain from the email
        $atPosition = strrpos($email, '@'); // Find the position of '@'

        if (false === $atPosition) {
            // If the email does not have a domain, return the email as-is
            return $email;
        }

        $domain       = substr($email, $atPosition); // Extract the domain (e.g., @gmail.com or @uol.com)
        $domainLength = strlen($domain);

        // Calculate the allowed length for the local part
        $localPartLength = $limit - $domainLength;

        // If the local part length is less than 1, it's not possible to truncate
        if ($localPartLength < 1) {
            return $email;
        }

        // Extract and truncate the local part
        $localPart = substr($email, 0, $localPartLength);

        // Combine the truncated local part with the domain
        return $localPart.$domain;
    }

    /**
     * @param array<string<array<string>> $leads
     */
    private function getLeadCompanyColumnsLenght(): array
    {
        $leadMetadata    = $this->entityManager->getClassMetadata(Lead::class);
        $companyMetadata = $this->entityManager->getClassMetadata(Company::class);
        $columnsLength   = [
            'leads'     => [],
            'companies' => [],
        ];
        foreach ($leadMetadata->fieldMappings as $fieldName => $fieldMapping) {
            if (isset($fieldMapping['length'])) {
                $columnsLength['leads'][$fieldName] = $fieldMapping['length'];
            }
        }

        foreach ($companyMetadata->fieldMappings as $fieldName => $fieldMapping) {
            if (isset($fieldMapping['length'])) {
                $columnsLength['companies'][$fieldName] = $fieldMapping['length'];
            }
        }

        return $columnsLength;
    }

    private function updateEmailStatusValues(string $email, string $hash, bool $pseudonymize): void
    {
        // email_stats.email_address
        $emailStats = $this->emailStatModel->getRepository()->findBy(['emailAddress' => $email]);
        foreach ($emailStats as $emailStat) {
            if (!$pseudonymize) {
                $hash = AnonymizeHelper::email($email, $pseudonymize);
            }

            $emailStat->setEmailAddress($hash);
            $this->emailStatModel->saveEntity($emailStat);
        }
    }

    private function deleteFormResults(PendingEvent $event): void
    {
        $leads = $event->getContacts();
        foreach ($leads as $lead) {
            $submissionForms = $this->submissionModel->getRepository()->findBy(['lead' => $lead]);
            foreach ($submissionForms as $submissionForm) {
                $newSubmissionForm     = $submissionForm;
                $id                    = $submissionForm->getForm()->getId();
                $alias                 = $submissionForm->getForm()->getAlias();
                $idSubmissionsToDelete = [$submissionForm->getId()];
                $this->submissionModel->getRepository()->deleteEntity($submissionForm);
                $this->deleteFormResultsByLead($submissionForm->getForm()->getId(), $submissionForm->getForm()->getAlias(), $idSubmissionsToDelete);
            }
        }
    }

    private function deleteFormResultsByLead(int $formId, string $formAlias, array $submissionsToDelete): void
    {
        $connection = $this->entityManager->getConnection();
        $prefix     = MAUTIC_TABLE_PREFIX;
        $query      = "DELETE FROM {$prefix}form_results_{$formId}_{$formAlias} WHERE submission_id IN (:submissions)";
        $connection->executeQuery(
            $query,
            [
                'submissions' => $submissionsToDelete,
            ],
            [
                'submissions' => \Doctrine\DBAL\Connection::PARAM_INT_ARRAY,
            ]
        );
    }
}
