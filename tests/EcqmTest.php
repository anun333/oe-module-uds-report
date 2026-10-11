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
}
