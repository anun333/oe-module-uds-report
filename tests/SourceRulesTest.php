<?php

/**
 * Rules about the module's own source that a reader can't see from one file.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Tests;

use PHPUnit\Framework\TestCase;

final class SourceRulesTest extends TestCase
{
    /**
     * OpenEMR translates only literal strings (xl() is typed literal-string on
     * master), and turns apostrophes into backticks on the way. Text from the
     * data files has no translation, so passing it to xl() only mangled it
     * ("Alzheimer`s"). Data-file text is printed as written.
     */
    public function testTranslationIsOnlyCalledWithLiteralStrings(): void
    {
        $files = [...glob(__DIR__ . '/../public/*.php') ?: [], ...glob(__DIR__ . '/../src/*/*.php') ?: []];
        self::assertGreaterThan(30, count($files), 'the source files were found');
        $offenders = [];
        foreach ($files as $file) {
            foreach (file($file) ?: [] as $i => $line) {
                if (preg_match('/\bxl[ta]?\(\s*\$/', $line) === 1) {
                    $offenders[] = basename($file) . ':' . ($i + 1);
                }
            }
        }
        self::assertSame([], $offenders, 'xl(), xlt() or xla() called with a variable');
    }
}
