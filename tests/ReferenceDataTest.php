<?php

/**
 * The UDS Report module's shipped reference files load, and a bad edit to any
 * of them is refused with a message naming the file and entry: the report must
 * stop rather than miscount.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Tests;

use OpenEMR\Modules\UdsReport\Enum\PovertyRegion;
use OpenEMR\Modules\UdsReport\ReferenceData\InsuranceCategoryMap;
use OpenEMR\Modules\UdsReport\ReferenceData\PovertyGuidelines;
use OpenEMR\Modules\UdsReport\ReferenceData\RaceEthnicity;
use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceDataException;
use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceDataLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReferenceDataTest extends TestCase
{
    public function testShippedFilesLoad(): void
    {
        $reference = (new ReferenceDataLoader())->load();
        self::assertSame(15960, $reference->povertyGuidelines->annualThreshold(2026, PovertyRegion::Contiguous, 1));
    }

    public function testMissingFileIsNamed(): void
    {
        try {
            (new ReferenceDataLoader(sys_get_temp_dir() . '/no-such-uds-data'))->load();
            self::fail('a missing file was accepted');
        } catch (ReferenceDataException $e) {
            self::assertSame('data/poverty-guidelines.json', $e->location);
            self::assertSame('the file is missing or cannot be read', $e->reason);
        }
    }

    public function testInvalidJsonIsNamed(): void
    {
        $dir = sys_get_temp_dir() . '/uds-data-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            file_put_contents($dir . '/poverty-guidelines.json', '{"about": "x",');
            (new ReferenceDataLoader($dir))->load();
            self::fail('invalid JSON was accepted');
        } catch (ReferenceDataException $e) {
            self::assertSame('data/poverty-guidelines.json', $e->location);
            self::assertSame('not valid JSON', $e->reason);
            self::assertInstanceOf(\JsonException::class, $e->getPrevious());
        } finally {
            if (is_file($dir . '/poverty-guidelines.json')) {
                unlink($dir . '/poverty-guidelines.json');
            }
            rmdir($dir);
        }
    }

    /**
     * One bad edit per check: the file it is made to, the edit, and what the
     * message must say.
     *
     * @return array<string, array{string, \Closure(array<mixed>): array<mixed>, string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function badEdits(): array
    {
        return [
            'typo in a field name' => [PovertyGuidelines::FILE, static fn(array $d): array => self::with(
                self::without($d, ['guidelines', 0, 'firstPerson']),
                ['guidelines', 0, 'firstPersn'],
                15960,
            ), 'guidelines[0]: unknown field "firstPersn"'],
            'figure written as text' => [PovertyGuidelines::FILE, static fn(array $d): array => self::with($d, ['guidelines', 0, 'firstPerson'], '15,960'),
                'guidelines[0].firstPerson: must be a whole number'],
            'year and region listed twice' => [PovertyGuidelines::FILE, static fn(array $d): array => self::appendCopy($d, ['guidelines'], 0),
                '2026 contiguous is listed twice'],
            'figures with no source' => [PovertyGuidelines::FILE, static fn(array $d): array => self::with($d, ['guidelines', 0, 'source'], null),
                'guidelines[0].source: must be text'],
            'unknown region' => [PovertyGuidelines::FILE, static fn(array $d): array => self::with($d, ['guidelines', 0, 'region'], 'puerto_rico'),
                'guidelines[0].region: must be one of contiguous, alaska, hawaii'],
            'subgroup outside its group' => [RaceEthnicity::FILE, static fn(array $d): array => self::with($d, ['raceGroups', 0, 'subgroups', 0, 'code'], '2080-0'),
                "raceGroups[0].subgroups[0].code: 2080-0 is not in this group's codeRanges"],
            "subgroup on another group's line" => [RaceEthnicity::FILE, static fn(array $d): array => self::with($d, ['raceGroups', 1, 'subgroups', 0, 'line'], '1b'),
                "line 1b is not one of this group's lines"],
            'overlapping groups' => [RaceEthnicity::FILE, static fn(array $d): array => self::with($d, ['raceGroups', 2, 'codeRanges', 0, 'from'], '2050'),
                'raceGroups[2].codeRanges[0]: overlaps the codes of group "asian"'],
            'race mapped to the more-than-one-race line' => [RaceEthnicity::FILE, static fn(array $d): array => self::with($d, ['raceGroups', 4, 'line'], '6'),
                'raceGroups[4].line: must be one of'],
            'origin mapped to not hispanic' => [RaceEthnicity::FILE, static fn(array $d): array => self::with($d, ['ethnicity', 'origins', 0, 'column'], 'B'),
                'ethnicity.origins[0].column: must be one of A1, A2, A3, A4'],
            'malformed CDC code' => [RaceEthnicity::FILE, static fn(array $d): array => self::with($d, ['ethnicity', 'origins', 0, 'code'], '21485'),
                'ethnicity.origins[0].code: must be a CDC code'],
            'category spelled wrong' => [InsuranceCategoryMap::FILE, static fn(array $d): array => self::with($d, ['claimTypes', 1, 'category'], 'Medicare'),
                'claimTypes[1].category: must be one of none, medicaid'],
            'undecided with no reason' => [InsuranceCategoryMap::FILE, static fn(array $d): array => self::without($d, ['claimTypes', 0, 'note']),
                'claimTypes[0]: an undecided (null) category needs a note'],
            'claim type listed twice' => [InsuranceCategoryMap::FILE, static fn(array $d): array => self::appendCopy($d, ['claimTypes'], 0),
                'claim type 1 is listed twice'],
            'unmapped is not a category a file can assign' => [InsuranceCategoryMap::FILE, static fn(array $d): array => self::with($d, ['claimTypes', 1, 'category'], 'unmapped'),
                'claimTypes[1].category: must be one of'],
        ];
    }

    /** @param \Closure(array<mixed>): array<mixed> $edit */
    #[DataProvider('badEdits')]
    public function testBadEditIsRefusedWithItsLocation(string $file, \Closure $edit, string $message): void
    {
        $data = (new ReferenceDataLoader())->read($file);
        self::assertIsArray($data);
        $parse = match ($file) {
            PovertyGuidelines::FILE => PovertyGuidelines::fromData(...),
            RaceEthnicity::FILE => RaceEthnicity::fromData(...),
            default => InsuranceCategoryMap::fromData(...),
        };
        $parse($data); // the shipped file itself is fine

        try {
            $parse($edit($data));
            self::fail('the bad edit was accepted');
        } catch (ReferenceDataException $e) {
            self::assertStringStartsWith(ReferenceDataLoader::label($file), $e->getMessage());
            self::assertStringContainsString($message, $e->getMessage());
        }
    }

    public function testEmptyRowIsListedButNotUsed(): void
    {
        $data = (new ReferenceDataLoader())->read(PovertyGuidelines::FILE);
        $guidelines = CaseFile::list(CaseFile::object($data)['guidelines'] ?? null);
        $empty = 0;
        $poverty = (new ReferenceDataLoader())->load()->povertyGuidelines;
        foreach ($guidelines as $row) {
            $g = CaseFile::object($row);
            if ($g['firstPerson'] === null) {
                $empty++;
                self::assertFalse($poverty->isAvailable(CaseFile::int($g['year']), PovertyRegion::from(CaseFile::string($g['region']))));
            }
        }
        self::assertGreaterThan(0, $empty, 'the 2025 Alaska and Hawaii rows are still to be filled in');
    }

    /**
     * A copy of decoded JSON with the value at $path replaced (or added).
     *
     * @param array<mixed>          $data
     * @param non-empty-list<int|string> $path
     * @return array<mixed>
     */
    private static function with(array $data, array $path, mixed $value): array
    {
        $key = array_shift($path);
        if ($path === []) {
            $data[$key] = $value;
            return $data;
        }
        $child = $data[$key] ?? null;
        self::assertIsArray($child, 'no such entry in the reference file');
        $data[$key] = self::with($child, $path, $value);
        return $data;
    }

    /**
     * A copy of decoded JSON with the field at $path removed.
     *
     * @param array<mixed>          $data
     * @param non-empty-list<int|string> $path
     * @return array<mixed>
     */
    private static function without(array $data, array $path): array
    {
        $key = array_shift($path);
        if ($path === []) {
            unset($data[$key]);
            return $data;
        }
        $child = $data[$key] ?? null;
        self::assertIsArray($child, 'no such entry in the reference file');
        $data[$key] = self::without($child, $path);
        return $data;
    }

    /**
     * A copy of decoded JSON with entry $index of the list at $path appended again.
     *
     * @param array<mixed>          $data
     * @param non-empty-list<int|string> $path
     * @return array<mixed>
     */
    private static function appendCopy(array $data, array $path, int $index): array
    {
        $list = $data;
        foreach ($path as $key) {
            $list = is_array($list) ? ($list[$key] ?? null) : null;
        }
        self::assertIsArray($list, 'no such list in the reference file');
        $list[] = $list[$index] ?? null;
        return self::with($data, $path, $list);
    }
}
