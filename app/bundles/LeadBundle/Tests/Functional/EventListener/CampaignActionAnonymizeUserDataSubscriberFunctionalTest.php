<?php

namespace Mautic\LeadBundle\Tests\Functional\EventListener;

use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Entity\Lead as CampaignLead;
use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\LeadBundle\Entity\Company;
use Mautic\LeadBundle\Entity\CompanyLead;
use Mautic\LeadBundle\Entity\Lead;

class CampaignActionAnonymizeUserDataSubscriberFunctionalTest extends MauticMysqlTestCase
{
    public const LEAD_DEFAULT_DEFINES = [
        'firstname' => 'Test',
        'lastname'  => 'User',
        'city'      => 'City',
        'zipcode'   => 'Zipcode',
        'address1'  => 'Address 1',
        'address2'  => 'Address 2',
        'instagram' => 'Instagram',
        'fax'       => 'Fax',
        'twitter'   => 'Twitter',
        'linkedin'  => 'LinkedIn',
        'company'   => 'Company',
    ];

    public function testRunCampaignWithAnonymizeUserDataAction(): void
    {
        $campaign           = $this->createCampaign();
        $event              = $this->createEvent($campaign);
        $preDefLead1        = 'Foo';
        $preDefLead2        = 'Bar';
        $company1           = $this->createCompany();
        $company2           = $this->createCompany('Company 2', 'foobaa2@mauit.com');
        $lead1              = $this->createLead($preDefLead1);
        $resultCompanyLead1 = $this->addCompanyOnLead($lead1, $company1);
        $resultCompanyLead2 = $this->addCompanyOnLead($lead1, $company2);

        $lead2              = $this->createLead($preDefLead2);
        $resultCompanyLead3 = $this->addCompanyOnLead($lead2, $company2);
        $campaignLead       = [
            $this->createLeadCampaign($campaign, $lead1),
            $this->createLeadCampaign($campaign, $lead2),
        ];
        $this->em->clear();

        // Execute Campaign
        $test = $this->testSymfonyCommand(
            'mautic:campaigns:trigger',
            ['--campaign-id' => $campaign->getId()]
        );

        // Check if the leads are anonymized
        $freshLead1   = $this->em->getRepository(Lead::class)->find($lead1->getId());
        $companyLead1 = $this->em->getRepository(Company::class)->find($company1->getId());
        $companyLead2 = $this->em->getRepository(Company::class)->find($company2->getId());
        // Check if Address1 from company 1 was deleted
        $this->assertNotSame($companyLead1->getAddress1(), $resultCompanyLead1['company']->getAddress1());
        $this->assertNull($companyLead1->getAddress1());
        // Check if Description from company 1 was anonymized
        $this->assertNotSame($companyLead1->getDescription(), $resultCompanyLead1['company']->getDescription());
        $this->assertNotNull($companyLead1->getDescription());
        // Check if Address1 from company 2 was deleted
        $this->assertNotSame($companyLead2->getAddress1(), $resultCompanyLead2['company']->getAddress1());
        $this->assertNull($companyLead2->getAddress1());
        // Check if Address 2 from company 2 kept the same because it was not defined to be deleted
        $this->assertSame($companyLead2->getAddress2(), $resultCompanyLead2['company']->getAddress2());
        // Check if position from lead 1 kept the same because position is a number field
        $this->assertFalse($freshLead1->getField('position'));
        // Check if address1 from lead 1 was anonymized
        $this->assertNotSame($lead1->getAddress1(), $freshLead1->getAddress1());
        $this->assertNotNull($freshLead1->getAddress1());
        // Check if address2 from lead 1 was deleted
        $this->assertNotSame($lead1->getAddress2(), $freshLead1->getAddress2());
        $this->assertNull($freshLead1->getAddress2());
        $this->assertIsArray($lead1->getField('address2'));
        $this->assertFalse($freshLead1->getField('address2'));
        $this->assertNull($freshLead1->getAddress2());
        $this->assertNotSame($lead1->getFirstname(), $freshLead1->getFirstname());
        $this->assertNotSame($lead1->getLastname(), $freshLead1->getLastname());
        $this->assertNotNull($freshLead1->getFirstname());
        $this->assertNotSame($lead1->getField('position'), $freshLead1->getField('position'));
        $this->assertNotSame($lead1->getPosition(), $freshLead1->getPosition());
        $this->assertNull($freshLead1->getPosition());
        $this->assertNotSame($lead1->getField('instagram'), $freshLead1->getField('instagram'));
        $this->assertNotSame($lead1->getEmail(), $freshLead1->getEmail());
        $this->assertStringContainsString('@ano.nym', $freshLead1->getEmail());
    }

    private function createCampaign(): Campaign
    {
        $campaign = new Campaign();
        $campaign->setName('Campaign With Anonymize User Data');
        $campaign->setIsPublished(true);
        $campaign->setAllowRestart(true);

        $this->em->persist($campaign);
        $this->em->flush();

        return $campaign;
    }

    private function createEvent(Campaign $campaign): Event
    {
        // Fields: Firstname, Lastname, Address Line 1, Instagram, Email, Company Description
        $fieldsToAnonymize = ['2', '3', '11', '25', '6', '43'];
        // Fields: Position, Address Line 2, Company Address 1
        $fieldsToDelete = ['5', '12', '29'];
        // Create event: Anonymize User Data
        $event = new Event();
        $event->setCampaign($campaign);
        $event->setName('Anonymize User Data');
        $event->setType('lead.action_anonymizeuserdata');
        $event->setEventType(Event::TYPE_ACTION);
        $event->setTriggerMode(Event::TRIGGER_MODE_IMMEDIATE);
        $event->setProperties([
            'pseudonymize'      => '1',
            'fieldsToAnonymize' => $fieldsToAnonymize,
            'fieldsToDelete'    => $fieldsToDelete,
        ]);
        $event->setDecisionPath('yes');
        $event->setOrder(1);

        $this->em->persist($event);
        $this->em->flush();

        return $event;
    }

    private function createLead(string $preDefinition): Lead
    {
        $lead = new Lead();
        $lead->setEmail($preDefinition.'test@test.com');
        $lead->setFirstname($preDefinition.' Test');
        $lead->setLastname($preDefinition.' User');
        $lead->setCity($preDefinition.' City');
        $lead->setZipcode($preDefinition.' Zipcode');
        $lead->setAddress1($preDefinition.self::LEAD_DEFAULT_DEFINES['address1']);
        $lead->setAddress2($preDefinition.' Address 2');
        $fields = [
            'position'  => $preDefinition.' Position',
            'instagram' => $preDefinition.' Instagram',
            'twitter'   => $preDefinition.' Twitter',
            'linkedin'  => $preDefinition.' LinkedIn',
            'company'   => $preDefinition.' Company',
        ];

        $this->em->getRepository(Lead::class)->saveEntity($lead);
        $leadModel = static::getContainer()->get('mautic.lead.model.lead');
        $leadModel->setFieldValues($lead, $fields);

        return $lead;
    }

    private function createLeadCampaign(Campaign $campaign, Lead $lead): CampaignLead
    {
        // Create Campaign Lead
        $campaignLead = new CampaignLead();
        $campaignLead->setCampaign($campaign);
        $campaignLead->setLead($lead);
        $campaignLead->setDateAdded(new \DateTime());

        $this->em->persist($campaignLead);
        $this->em->flush();

        return $campaignLead;
    }

    private function createCompany(string $name = 'Company', string $email='company@foobaa.com'): Company
    {
        $company = new Company();
        $company->setName($name);
        $company->setDescription('Company Description');
        $company->setIndustry('Industry');
        $company->setWebsite('www.company.com');
        $company->setEmail($email);
        $company->setPhone('1234567890');
        $company->setAddress1('Company Address 1');
        $company->setAddress2('Company Address 2');
        $company->setCity('Company City');
        $this->em->persist($company);
        $this->em->flush();

        return $company;
    }

    /**
     * @return array<string, Lead|Company|CompanyLead>
     */
    private function addCompanyOnLead(Lead $lead, Company $company): array
    {
        $companyLead = new CompanyLead();
        $companyLead->setCompany($company);
        $companyLead->setLead($lead);
        $companyLead->setPrimary(true);
        $companyLead->setDateAdded(new \DateTime());
        $lead->setPrimaryCompany($company);
        $lead->setCompany($company);
        $this->em->persist($companyLead);
        $this->em->persist($lead);
        $this->em->persist($company);
        $this->em->flush();

        return [
            'lead'        => $lead,
            'company'     => $company,
            'companyLead' => $companyLead,
        ];
    }
}
