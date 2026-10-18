<?php

/**
 * The eCQM readiness rules: the UDS measure list, whether a measure is installed
 * at the version UDS names, and when a record with no end date can count.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Tests;

use OpenEMR\Modules\UdsReport\ReferenceData\EcqmMeasures;
use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceDataException;
use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceDataLoader;
use OpenEMR\Modules\UdsReport\Report\EcqmChecks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EcqmTest extends TestCase
{
    public function testThe2026ListHasTheSixteenMeasuresTheManualNames(): void
    {
        $measures = (new ReferenceDataLoader())->load()->ecqmMeasures;
        $ids = array_column($measures->forYear(2026), 'measure');
        self::assertCount(16, $ids);
        self::assertSame([], array_diff([
            'CMS2v15', 'CMS69v14', 'CMS117v14', 'CMS122v14', 'CMS124v14', 'CMS125v14', 'CMS130v14', 'CMS137v14',
            'CMS138v14', 'CMS139v14', 'CMS155v14', 'CMS159v14', 'CMS164v7', 'CMS165v14', 'CMS347v9', 'CMS349v8',
        ], $ids));
        self::assertSame(['CMS165v14', 'CMS122v14'], array_column(array_filter($measures->forYear(2026), static fn(array $m): bool => $m['table'] === '7'), 'measure'));
        self::assertSame([], $measures->forYear(2025), 'a year the file does not cover lists nothing');
    }

    public function testMeasureStatus(): void
    {
        $installed = ['CMS1056v2', 'CMS117v13', 'CMS1171v2', 'CMS122v14', 'CMS122v13', 'CMS826v2-v2'];
        self::assertSame(['installed' => true, 'otherVersions' => ['CMS122v13']], EcqmChecks::measureStatus('CMS122v14', $installed));
        self::assertSame(['installed' => false, 'otherVersions' => ['CMS117v13']], EcqmChecks::measureStatus('CMS117v14', $installed), 'CMS1171 is another measure');
        self::assertSame(['installed' => false, 'otherVersions' => []], EcqmChecks::measureStatus('CMS164v7', $installed));
        self::assertSame(['installed' => false, 'otherVersions' => ['CMS826v2-v2']], EcqmChecks::measureStatus('CMS826v3', $installed));
        self::assertSame(['installed' => false, 'otherVersions' => []], EcqmChecks::measureStatus('CMS122v14', []));
    }

    /**
     * @return array<string, array{array<mixed>, bool, bool}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function periods(): array
    {
        $ended = ['low' => '2024-01-01', 'high' => '2026-12-31', 'lowClosed' => true, 'highClosed' => true];
        $unknown = ['low' => '2024-01-01', 'high' => null, 'lowClosed' => true, 'highClosed' => false];
        $ongoing = ['low' => '2024-01-01', 'high' => null, 'lowClosed' => true, 'highClosed' => true];
        return [
            'problem with an end' => [$ended, false, true],
            'problem, end unknown (OpenEMR #14526)' => [$unknown, false, false],
            'problem, ongoing' => [$ongoing, false, true],
            'visit with an end' => [$ended, true, true],
            'visit, end unknown' => [$unknown, true, false],
            'visit ongoing to the end of time is not during the year' => [$ongoing, true, false],
            'empty end' => [['high' => '', 'highClosed' => false], false, false],
            'nothing' => [[], false, false],
        ];
    }

    /** @param array<mixed> $period */
    #[DataProvider('periods')]
    public function testOpenEndCounts(array $period, bool $isVisit, bool $counts): void
    {
        self::assertSame($counts, EcqmChecks::openEndCounts($period, $isVisit));
    }

    /**
     * @return array<string, array{array<mixed>, string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function badFiles(): array
    {
        $m = ['table' => '6B', 'line' => '10', 'title' => 'Childhood Immunization Status', 'measure' => 'CMS117v14', 'page' => 92];
        $file = static fn(array $years): array => ['about' => 'a', 'basis' => 'b', 'years' => $years];
        return [
            'listed twice' => [$file(['2026' => [$m, $m]]), 'CMS117v14 is listed twice'],
            'no version' => [$file(['2026' => [['measure' => 'CMS117'] + $m]]), 'must be a CMS id with its version'],
            'unknown table' => [$file(['2026' => [['table' => '6A'] + $m]]), 'table'],
            'not a year' => [$file(['26' => [$m]]), '"26" is not a year'],
            'misspelled field' => [$file(['2026' => [['titel' => 'x'] + $m]]), 'unknown field "titel"'],
        ];
    }

    /** @param array<mixed> $data */
    #[DataProvider('badFiles')]
    public function testBadFileIsRefused(array $data, string $message): void
    {
        try {
            EcqmMeasures::fromData($data);
            self::fail('a bad file was accepted');
        } catch (ReferenceDataException $e) {
            self::assertStringContainsString('data/ecqm-measures.json', $e->getMessage());
            self::assertStringContainsString($message, $e->getMessage());
        }
    }

    /**
     * @return array<string, array{?string, bool}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function tobaccoValues(): array
    {
        return [
            'status chosen' => ['|0||1|0', true],
            'never smoker, with note and date' => ['no tobacco|nevertobacco|2026-03-01|4|0', true],
            'button only, older value' => ['|currenttobacco|', true],
            'quit button' => ['|quittobacco||', true],
            'nothing chosen' => ['|0||', false],
            'nothing chosen, five parts' => ['|0|||0', false],
            'not applicable button only' => ['|not_applicabletobacco||', false],
            'a date alone is not a status' => ['|0|2026-03-01', false],
            'note alone' => ['smokes cigars|0||', false],
            'empty' => ['', false],
            'null' => [null, false],
        ];
    }

    #[DataProvider('tobaccoValues')]
    public function testTobaccoRecorded(?string $value, bool $recorded): void
    {
        self::assertSame($recorded, EcqmChecks::tobaccoRecorded($value));
    }

    public function testObservationCodesAreLoincAndOfATypeTheEngineReads(): void
    {
        $measures = array_column((new ReferenceDataLoader())->load()->ecqmMeasures->forYear(2026), 'measure');
        foreach (EcqmChecks::OBSERVATION_CODES as $kind => $read) {
            self::assertContains($read['type'], ['assessment', 'physical_exam_performed'], $kind);
            self::assertNotSame([], $read['codes'], $kind);
            foreach ($read['codes'] as $code) {
                self::assertMatchesRegularExpression('/^\d{1,6}-\d$/D', $code, "$kind: LOINC code shape");
            }
            self::assertCount(1, preg_grep('/^' . $read['measure'] . 'v\d+$/D', $measures) ?: [], "$kind: {$read['measure']} is a 2026 UDS measure");
        }
    }

    /**
     * Queries as OpenEMR master writes them (2026-10-18), and as they read once each kind is left
     * out: whether the column is named decides the readiness rows.
     *
     * @return array<string, array{string, string, bool}>
     */
    public static function queries(): array
    {
        return [
            'Diagnosis on master' => ["SELECT pid, begdate, enddate, `date`, diagnosis FROM lists WHERE `type` = 'medical_problem' AND subtype = 'diagnosis'", 'verification', false],
            'Diagnosis leaving errors out' => ["SELECT pid FROM lists WHERE `type` = 'medical_problem' AND subtype = 'diagnosis' AND lists.`verification` NOT IN ('entered-in-error', 'refuted')", 'verification', true],
            'immunizations on master' => ['SELECT patient_id, patient_id AS pid, administered_date, cvx_code, refusal_reason, reason_code FROM immunizations', 'added_erroneously', false],
            'immunizations leaving errors out' => ['SELECT patient_id FROM immunizations WHERE added_erroneously = 0', 'added_erroneously', true],
            'prescriptions on master' => ["SELECT patient_id AS pid, drug, rxnorm_drugcode, FL.title AS drug_interval, date_added, start_date, end_date FROM prescriptions P LEFT JOIN list_options FL ON P.interval = FL.option_id AND FL.list_id = 'drug_intervals'", 'active', false],
            'not MedicationActive, inactive or activity' => ['SELECT 1 /* MedicationActive */ FROM t WHERE inactive = 0 AND activity = 1', 'active', false],
            'prescriptions leaving inactive out' => ['SELECT patient_id FROM prescriptions P WHERE P.`active` = 1', 'active', true],
            'observations on master' => ["SELECT `pid` FROM `form_observation` WHERE `ob_type` = 'physical_exam_performed'", 'deleted', false],
            'observations with #14587' => ["SELECT `pid` FROM `form_observation` WHERE `ob_type` = 'x' AND COALESCE(`form_observation`.`activity`, 1) <> 0 AND NOT EXISTS (SELECT 1 FROM `forms` WHERE `forms`.`deleted` = 1)", 'deleted', true],
            'lab results on master' => ["SELECT RES.result_code, RES.result FROM procedure_result RES WHERE O.procedure_order_type = 'laboratory_test' AND O.activity != 0", 'result_status', false],
            'lab results leaving errors out' => ["SELECT RES.result FROM procedure_result RES WHERE RES.result_status NOT IN ('cancel', 'error')", 'result_status', true],
        ];
    }

    #[DataProvider('queries')]
    public function testAQueryNamesAColumnOnlyAsAWord(string $sql, string $column, bool $named): void
    {
        self::assertSame($named, EcqmChecks::queryNames($sql, $column));
    }

    public function testEveryKindNamesAQdmServiceAndAColumn(): void
    {
        self::assertSame(
            ['problemsInError', 'immunizationsInError', 'inactivePrescriptions', 'deletedObservations', 'deletedCarePlans', 'deletedOrders', 'labResultsInError'],
            array_keys(EcqmChecks::LEFT_OUT_BY)
        );
        foreach (EcqmChecks::LEFT_OUT_BY as $kind => $rule) {
            self::assertMatchesRegularExpression('/^OpenEMR\\\\Services\\\\Qdm\\\\Services\\\\[A-Za-z]+Service$/', $rule['service'], $kind);
            self::assertMatchesRegularExpression('/^[a-z_]+$/', $rule['column'], $kind);
        }
    }
}
