<?php

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Tests;

use OpenEMR\Modules\UdsReport\Http\Page;
use PHPUnit\Framework\TestCase;

final class PageTest extends TestCase
{
    public function testWithNoSignedInUserThePageIsEnglishLeftToRight(): void
    {
        self::assertSame('en', Page::lang());
        self::assertSame('ltr', Page::dir());
    }

    public function testEveryPageDeclaresTheUsersLanguageNotAFixedOne(): void
    {
        foreach (glob(__DIR__ . '/../public/*.php') ?: [] as $page) {
            $source = (string)file_get_contents($page);
            self::assertStringNotContainsString('<html lang="en">', $source, basename($page));
            if (str_contains($source, '<html')) {
                self::assertStringContainsString('<html lang="<?php echo attr(Page::lang()); ?>" dir="<?php echo attr(Page::dir()); ?>">', $source, basename($page));
            }
        }
    }

    public function testNoYearOrReportListSubmitsOnItsOwn(): void
    {
        foreach (glob(__DIR__ . '/../public/*.php') ?: [] as $page) {
            self::assertStringNotContainsString('this.form.submit()', (string)file_get_contents($page), basename($page));
        }
    }
}
