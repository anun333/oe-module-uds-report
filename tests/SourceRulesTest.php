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

    /**
     * The other half of the same rule: an apostrophe in a translated literal
     * is shown as a backtick. Write "the clinic settings", not "the clinic's".
     */
    public function testTranslatedTextHasNoApostrophes(): void
    {
        $files = [...glob(__DIR__ . '/../public/*.php') ?: [], ...glob(__DIR__ . '/../src/*/*.php') ?: []];
        $offenders = [];
        $literals = [];
        foreach ($files as $file) {
            $source = file_get_contents($file) ?: '';
            preg_match_all('/\bxl[ta]?\(\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")/', $source, $m);
            $literals = [...$literals, ...$m[1]];
            foreach ($m[1] as $literal) {
                if (str_contains(substr($literal, 1, -1), "'")) {
                    $offenders[] = basename($file) . ': ' . $literal;
                }
            }
        }
        self::assertContains("'Table 9D worksheet'", $literals, 'the literals were found');
        self::assertGreaterThan(200, count($literals));
        self::assertSame([], $offenders, 'apostrophes in translated text');
    }
}
