<?php

namespace Tests\Unit;

use App\Enums\QualityControlResultType;
use App\Enums\QualityControlSamplingMode;
use App\Enums\QualityControlTemplateVersionStatus;
use App\Enums\QualityControlType;
use App\Enums\QualityRequirementScope;
use App\Enums\QualityRequirementTrigger;
use PHPUnit\Framework\TestCase;

class QualityEnumsTest extends TestCase
{
    public function test_control_types_expose_labels_and_options(): void
    {
        $options = QualityControlType::options();

        $this->assertCount(6, $options);
        $this->assertSame('Contrôle dimensionnel', $options[QualityControlType::Dimensional->value]);
        $this->assertSame('Contrôle final', $options[QualityControlType::Final->value]);

        foreach (QualityControlType::cases() as $type) {
            $this->assertNotSame('', $type->getLabel());
            $this->assertNotSame('', $type->getColor());
        }
    }

    public function test_result_types_declare_their_capabilities(): void
    {
        $this->assertTrue(QualityControlResultType::Numeric->requiresUnit());
        $this->assertTrue(QualityControlResultType::Numeric->usesTolerances());
        $this->assertFalse(QualityControlResultType::Numeric->usesOptions());

        $this->assertTrue(QualityControlResultType::Choice->usesOptions());
        $this->assertFalse(QualityControlResultType::Choice->usesTolerances());

        $this->assertFalse(QualityControlResultType::Conformity->usesTolerances());
        $this->assertFalse(QualityControlResultType::Text->requiresUnit());
    }

    public function test_sampling_modes(): void
    {
        $this->assertCount(3, QualityControlSamplingMode::options());
        $this->assertTrue(QualityControlSamplingMode::EveryN->requiresEvery());
        $this->assertFalse(QualityControlSamplingMode::All->requiresEvery());
        $this->assertTrue(QualityControlSamplingMode::Fixed->usesRequiredResultsAsContribution());
        $this->assertFalse(QualityControlSamplingMode::All->usesRequiredResultsAsContribution());
    }

    public function test_template_version_status_is_editable_only_when_draft(): void
    {
        $this->assertTrue(QualityControlTemplateVersionStatus::Draft->isEditable());
        $this->assertFalse(QualityControlTemplateVersionStatus::Active->isEditable());
        $this->assertFalse(QualityControlTemplateVersionStatus::Retired->isEditable());

        $this->assertTrue(QualityControlTemplateVersionStatus::Draft->isDraft());
        $this->assertTrue(QualityControlTemplateVersionStatus::Active->isActive());
        $this->assertTrue(QualityControlTemplateVersionStatus::Retired->isRetired());
    }

    public function test_frequency_sampling_is_reserved_to_item_scope(): void
    {
        $this->assertTrue(QualityRequirementScope::Item->allowsFrequencySampling());
        $this->assertFalse(QualityRequirementScope::Operation->allowsFrequencySampling());
        $this->assertFalse(QualityRequirementScope::WorkOrder->allowsFrequencySampling());
    }

    public function test_requirement_triggers_expose_labels(): void
    {
        $options = QualityRequirementTrigger::options();

        $this->assertCount(3, $options);
        $this->assertSame('Avant démarrage', $options[QualityRequirementTrigger::OnStart->value]);
        $this->assertSame('Contrôle final', $options[QualityRequirementTrigger::Final->value]);
    }
}
