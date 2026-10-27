<?php

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Tests;

use OpenEMR\Modules\UdsReport\Http\Guide;
use OpenHealthcare\Common\GuideLabels;
use PHPUnit\Framework\TestCase;

/**
 * GUIDE.md quotes the labels staff click in bold; each must be a string the module translates,
 * or be named in composer.json with the file it comes from (the three Demographics field titles,
 * which sql/install.sql writes). Help opens the guide at the installed version's tag.
 */
final class GuideTest extends TestCase
{
    public function testEveryLabelTheGuideQuotesIsOnThePages(): void
    {
        $result = GuideLabels::check(dirname(__DIR__));
        self::assertGreaterThan(40, $result['labels'], 'the guide quotes its labels');
        self::assertGreaterThan(100, $result['strings'], 'the translated strings were read');
        self::assertSame([], $result['missing']);
    }

    public function testHelpOpensTheGuideAtThisVersionsTag(): void
    {
        $v_major = $v_minor = $v_patch = '';
        include dirname(__DIR__) . '/version.php';
        self::assertSame("https://github.com/anun333/oe-module-uds-report/blob/v$v_major.$v_minor.$v_patch/GUIDE.md", Guide::url());
        self::assertSame('https://github.com/anun333/oe-module-uds-report/blob/main/GUIDE.md', Guide::url('/nonexistent/version.php'));
    }

    /**
     * WCAG 2.2's 3.2.6 Consistent Help: Help keeps one place on every page, right after the heading and before the
     * page's first form (the year toolbar on the report pages). The report page had it last in that toolbar.
     */
    public function testHelpSitsAfterTheHeadingAndBeforeTheFirstFormOnEveryPage(): void
    {
        $pages = 0;
        foreach (glob(dirname(__DIR__) . '/public/*.php') ?: [] as $page) {
            $source = (string)file_get_contents($page);
            $help = strpos($source, 'Guide::url()');
            if ($help === false) {
                continue;
            }
            $pages++;
            $heading = strpos($source, '<h1');
            $form = strpos($source, '<form');
            self::assertNotFalse($heading, basename($page) . ' has a heading');
            self::assertGreaterThan($heading, $help, basename($page) . ': Help comes after the heading');
            if ($form !== false) {
                self::assertLessThan($form, $help, basename($page) . ': Help comes before the first form');
            }
        }
        self::assertGreaterThan(5, $pages, 'the pages with Help were read');
    }
}
