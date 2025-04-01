<?php

namespace Mautic\LeadBundle\Tests\Form\Type;

use Mautic\LeadBundle\Entity\LeadField;
use Mautic\LeadBundle\Entity\LeadFieldRepository;
use Mautic\LeadBundle\Form\Type\CampaignActionAnonymizeUserDataType;
use Mautic\LeadBundle\Model\FieldModel;
use Symfony\Component\Form\FormBuilderInterface;

class CampaignActionAnonymizeUserDataTypeTest extends \PHPUnit\Framework\TestCase
{
    public function testBuildForm(): void
    {
        $lead = $this->createMock(LeadField::class);
        $lead->expects($this->exactly(2))->method('getId')->willReturn(1);
        $lead->expects($this->exactly(2))->method('getLabel')->willReturn('email');

        $fieldsChoices = [
            $lead,
        ];

        $fieldModel       = $this->createMock(FieldModel::class);
        $fieldRepository  = $this->createMock(LeadFieldRepository::class);
        $fieldRepository->expects($this->exactly(2))->method('findBy')->willReturn($fieldsChoices);
        $fieldModel->expects($this->exactly(2))->method('getRepository')->willReturn($fieldRepository);
        $builder    = $this->createMock(FormBuilderInterface::class);
        $builder->expects($this->exactly(3))->method('add');

        $entityManager                       = $this->createMock(\Doctrine\ORM\EntityManager::class);
        $translator                          = $this->createMock(\Symfony\Contracts\Translation\TranslatorInterface::class);
        $campaignActionAnonymizeUserDataType = new CampaignActionAnonymizeUserDataType($fieldModel, $entityManager, $translator);
        $campaignActionAnonymizeUserDataType->buildForm($builder, []);
    }

    public function testGetBlockPrefix(): void
    {
        $fieldModel                          = $this->createMock(FieldModel::class);
        $entityManager                       = $this->createMock(\Doctrine\ORM\EntityManager::class);
        $translator                          = $this->createMock(\Symfony\Contracts\Translation\TranslatorInterface::class);
        $campaignActionAnonymizeUserDataType = new CampaignActionAnonymizeUserDataType($fieldModel, $entityManager, $translator);
        $this->assertEquals('lead_action_anonymizeuserdata', $campaignActionAnonymizeUserDataType->getBlockPrefix());
    }
}
