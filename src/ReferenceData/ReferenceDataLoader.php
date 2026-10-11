<?php

/**
 * Reads and checks the module's reference data files (data/*.json).
 *
 * The files hold the code mappings and published figures that change with each
 * UDS Manual or HHS release. They are kept out of the PHP so that people who
 * know the UDS, not only programmers, can check and update them. Every file is
 * checked strictly: a missing field, an unknown one (often a typo) or a value
 * of the wrong kind is a ReferenceDataException naming the file and entry, so
 * a bad edit stops the report instead of miscounting.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\ReferenceData;

final readonly class ReferenceDataLoader
{
    public const MODULE_DATA_DIR = __DIR__ . '/../../data';

    public function __construct(private string $directory = self::MODULE_DATA_DIR)
    {
    }

    /** @throws ReferenceDataException */
    public function load(): ReferenceData
    {
        $povertyGuidelines = PovertyGuidelines::fromData($this->read(PovertyGuidelines::FILE));
        $raceEthnicity = RaceEthnicity::fromData($this->read(RaceEthnicity::FILE));
        $insuranceCategories = InsuranceCategoryMap::fromData($this->read(InsuranceCategoryMap::FILE));
        $table6a = Table6a::fromData($this->read(Table6a::FILE));
        $table5 = Table5::fromData($this->read(Table5::FILE));
        return new ReferenceData(
            $povertyGuidelines,
            $raceEthnicity,
            $insuranceCategories,
            $table6a,
            $table5,
            ProviderTaxonomy::fromData($this->read(ProviderTaxonomy::FILE), $table5),
        );
    }

    /**
     * Decoded contents of data/<name>.json, not yet checked.
     *
     * @throws ReferenceDataException
     */
    public function read(string $name): mixed
    {
        $path = $this->directory . '/' . $name . '.json';
        $json = is_file($path) && is_readable($path) ? file_get_contents($path) : false;
        if ($json === false) {
            throw new ReferenceDataException(self::label($name), 'the file is missing or cannot be read');
        }
        try {
            return json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ReferenceDataException(self::label($name), 'not valid JSON', $e);
        }
    }

    /**
     * How a file is named in messages.
     *
     * @return non-empty-string
     */
    public static function label(string $name): string
    {
        return 'data/' . $name . '.json';
    }
}
