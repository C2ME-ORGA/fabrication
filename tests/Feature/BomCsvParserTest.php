<?php

namespace Tests\Feature;

use App\DTO\BomParseResult;
use App\Services\BomCsvParser;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class BomCsvParserTest extends TestCase
{
    public function test_a_comma_separated_file_is_parsed(): void
    {
        $result = $this->parse(<<<'CSV'
mark,designation,profile,steel_grade,length_mm,quantity_planned,unit_weight_kg,operation_group,notes
P-01,Poteau,IPE 200,S235JR,6000,10,22.4,Débit,Note
CSV);

        $this->assertTrue($result->isValid());
        $this->assertSame(',', $result->delimiter);
        $this->assertSame(1, $result->rowsTotal);
        $this->assertSame(1, $result->rowsValid);
        $this->assertSame(0, $result->rowsInvalid);

        $row = $result->rows[0];
        $this->assertSame(2, $row->lineNumber);
        $this->assertSame('P-01', $row->mark);
        $this->assertSame('Poteau', $row->designation);
        $this->assertSame(10, $row->quantityPlanned);
        $this->assertSame(6000.0, $row->lengthMm);
        $this->assertSame(22.4, $row->unitWeightKg);
    }

    public function test_a_semicolon_separated_file_accepts_decimal_commas(): void
    {
        $result = $this->parse(<<<'CSV'
mark;designation;length_mm;quantity_planned;unit_weight_kg
P-02;Traverse;6000,50;2;15,250
CSV);

        $this->assertTrue($result->isValid());
        $this->assertSame(';', $result->delimiter);
        $this->assertSame(6000.5, $result->rows[0]->lengthMm);
        $this->assertSame(15.25, $result->rows[0]->unitWeightKg);
    }

    public function test_a_tab_separated_file_is_parsed(): void
    {
        $result = $this->parse("mark\tdesignation\tquantity_planned\nP-03\tPoteau\t3\n");

        $this->assertTrue($result->isValid());
        $this->assertSame("\t", $result->delimiter);
        $this->assertSame(3, $result->rows[0]->quantityPlanned);
    }

    public function test_a_utf8_bom_is_tolerated(): void
    {
        $result = $this->parse("\xEF\xBB\xBFmark,designation,quantity_planned\nP-04,Poteau,4\n");

        $this->assertTrue($result->isValid());
        $this->assertSame('P-04', $result->rows[0]->mark);
    }

    public function test_an_invalid_encoding_is_rejected(): void
    {
        config(['bom.mime_types.csv' => ['text/csv', 'text/plain', 'application/octet-stream']]);

        $result = $this->parse("\xFF\xFEm\x00a\x00r\x00k\x00");

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('UTF-8', $result->errors[0]->message);
    }

    public function test_missing_required_headers_are_rejected(): void
    {
        $result = $this->parse("mark,designation\nP-05,Poteau\n");

        $this->assertFalse($result->isValid());
        $this->assertSame('quantity_planned', $result->errors[0]->column);
    }

    public function test_forbidden_columns_are_rejected(): void
    {
        $result = $this->parse(<<<'CSV'
mark,designation,quantity_planned,quantity_produced
P-06,Poteau,1,10
CSV);

        $this->assertFalse($result->isValid());
        $this->assertSame('quantity_produced', $result->errors[0]->column);
    }

    public function test_invalid_rows_are_reported_with_line_and_column(): void
    {
        $result = $this->parse(<<<'CSV'
mark,designation,quantity_planned,length_mm
,Poteau,abc,-5
CSV);

        $this->assertFalse($result->isValid());
        $this->assertSame(1, $result->rowsTotal);
        $this->assertSame(0, $result->rowsValid);
        $this->assertSame(1, $result->rowsInvalid);

        $columns = array_map(fn ($error) => $error->column, $result->errors);
        $this->assertContains('mark', $columns);
        $this->assertContains('quantity_planned', $columns);
        $this->assertContains('length_mm', $columns);
    }

    public function test_a_row_with_too_many_columns_is_rejected(): void
    {
        $result = $this->parse("mark,designation,quantity_planned\nP-07,Poteau,1,extra\n");

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('plus de colonnes', $result->errors[0]->message);
    }

    public function test_french_headers_are_normalized_through_aliases(): void
    {
        $result = $this->parse(<<<'CSV'
repère;désignation;profil;nuance;longueur_mm;quantité_prévue;poids_unitaire_kg;groupe_opérations;observations
P-08;Poteau;IPE 200;S235JR;6000;5;20;Débit;Note
CSV);

        $this->assertTrue($result->isValid());
        $this->assertArrayHasKey('mark', $result->columnMapping['columns']);
        $this->assertArrayHasKey('quantity_planned', $result->columnMapping['columns']);
        $this->assertSame('P-08', $result->rows[0]->mark);
    }

    public function test_unknown_columns_are_ignored_and_recorded(): void
    {
        $result = $this->parse("mark,designation,quantity_planned,colonne_inconnue\nP-09,Poteau,1,valeur\n");

        $this->assertTrue($result->isValid());
        $this->assertContains('colonne_inconnue', $result->columnMapping['ignored']);
    }

    public function test_a_file_without_data_rows_is_rejected(): void
    {
        $result = $this->parse("mark,designation,quantity_planned\n");

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('aucune ligne', $result->errors[0]->message);
    }

    public function test_a_file_that_is_too_large_is_rejected(): void
    {
        config(['bom.max_size_kb' => 1]);

        $result = $this->parse("mark,designation,quantity_planned\n".str_repeat("P-10,Poteau,1\n", 200));

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('taille maximale', $result->errors[0]->message);
    }

    public function test_a_non_csv_extension_is_rejected(): void
    {
        $result = $this->parse("mark,designation,quantity_planned\nP-11,Poteau,1\n", 'bom.txt');

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Extension', $result->errors[0]->message);
    }

    public function test_a_content_mime_that_does_not_match_csv_is_rejected(): void
    {
        $file = UploadedFile::fake()->create('bom.csv', 1, 'application/pdf');

        $result = app(BomCsvParser::class)->parse($file);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Type de fichier non autorisé', $result->errors[0]->message);
    }

    private function parse(string $content, string $filename = 'bom.csv'): BomParseResult
    {
        return app(BomCsvParser::class)->parse(
            UploadedFile::fake()->createWithContent($filename, $content),
        );
    }
}
