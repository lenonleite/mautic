<?php

namespace Mautic\LeadBundle\Tests\Form\Type;

use Mautic\LeadBundle\Entity\LeadField;
use Mautic\LeadBundle\Form\Type\FieldListType;
use Mautic\LeadBundle\Model\FieldModel;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class FieldListTypeTest extends \PHPUnit\Framework\TestCase
{
    public function testConfigureOptions(): void
    {
        $lead = $this->createMock(LeadField::class);
        $lead->expects($this->once())->method('getId')->willReturn(1);
        $lead->expects($this->once())->method('getLabel')->willReturn('email');

        $fieldsChoices = [
            $lead,
        ];

        $fieldModel = $this->createMock(FieldModel::class);
        $resolver   = $this->createMock(OptionsResolver::class);
        $resolver->expects($this->once())->method('setDefaults');
        $fieldModel->method('getLeadFields')->willReturn($fieldsChoices);
        $fieldListType = new FieldListType($fieldModel);
        $fieldListType->configureOptions($resolver);
    }

    public function testGetParent(): void
    {
        $model         = $this->createMock(FieldModel::class);
        $fieldListType = new FieldListType($model);
        $this->assertEquals(ChoiceType::class, $fieldListType->getParent());
    }
}
