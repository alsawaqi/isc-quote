<?php

namespace Tests\Unit;

use App\Services\QuotationDescriptionChangeClassifier;
use PHPUnit\Framework\TestCase;

class QuotationDescriptionChangeClassifierTest extends TestCase
{
    private QuotationDescriptionChangeClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->classifier = new QuotationDescriptionChangeClassifier;
    }

    public function test_genuine_spelling_corrections_are_minor(): void
    {
        $this->assertTrue($this->classifier->isMinorCorrection(
            '<p>Terminal box location: RHS</p>',
            '<p>Terminal box location: RSH</p>',
        ));

        $this->assertTrue($this->classifier->isMinorCorrection(
            'Flameprof motor enclosure',
            'Flameproof motor enclosure',
        ));
    }

    public function test_numeric_and_short_technical_specification_changes_are_material(): void
    {
        $this->assertFalse($this->classifier->isMinorCorrection(
            '24V motor with IP65 enclosure',
            '48V motor with IP65 enclosure',
        ));

        $this->assertFalse($this->classifier->isMinorCorrection(
            'AC motor control input',
            'DC motor control input',
        ));

        $this->assertFalse($this->classifier->isMinorCorrection(
            'Terminal box location: RHS',
            'Terminal box location: LHS',
        ));

        $this->assertFalse($this->classifier->isMinorCorrection(
            'Pressure unit: BAR',
            'Pressure unit: PSI',
        ));
    }

    public function test_comparison_operator_changes_are_material(): void
    {
        $this->assertFalse($this->classifier->isMinorCorrection(
            'Operating pressure < 10 bar',
            'Operating pressure > 10 bar',
        ));

        $this->assertFalse($this->classifier->isMinorCorrection(
            'Temperature &le; 55 C',
            'Temperature &ge; 55 C',
        ));
    }

    public function test_changes_after_the_old_truncation_boundary_are_still_classified(): void
    {
        $prefix = str_repeat('standard enclosure specification ', 50);

        $this->assertGreaterThan(1200, strlen($prefix));
        $this->assertFalse($this->classifier->isMinorCorrection(
            $prefix.'red finish',
            $prefix.'blue finish',
        ));
    }

    public function test_html_formatting_only_changes_are_not_material(): void
    {
        $this->assertTrue($this->classifier->isMinorCorrection(
            '<p>ABB motor <strong>with enclosure</strong></p>',
            '<div>ABB motor with <mark>enclosure</mark></div>',
        ));
    }
}
