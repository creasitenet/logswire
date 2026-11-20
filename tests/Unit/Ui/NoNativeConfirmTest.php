<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * Destructive actions must go through the shared <dialog>, never a native
 * window.confirm()/alert(). Templates are scanned on disk so a future view cannot
 * silently reintroduce an inline handler (the CSP would block it anyway).
 */
final class NoNativeConfirmTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../..';

    public function testAppScriptDoesNotUseNativeDialogs(): void
    {
        $js = (string) file_get_contents(self::ROOT.'/public/js/logswire.js');

        self::assertStringNotContainsString('window.confirm', $js);
        self::assertStringNotContainsString('alert(', $js);
        self::assertStringContainsString('confirm-dialog', $js);
    }

    public function testTemplatesUseTheSharedDialogAndNoInlineHandlers(): void
    {
        $base = (string) file_get_contents(self::ROOT.'/templates/base.html.twig');
        self::assertStringContainsString('id="confirm-dialog"', $base);

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::ROOT.'/templates', \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || 'twig' !== $file->getExtension()) {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            self::assertDoesNotMatchRegularExpression('/\bonsubmit\s*=/i', $contents, $file->getPathname());
            self::assertDoesNotMatchRegularExpression('/\bonclick\s*=/i', $contents, $file->getPathname());
            self::assertDoesNotMatchRegularExpression('/\bconfirm\s*\(/i', $contents, $file->getPathname());
        }
    }

    public function testDestructiveFormsOptIntoTheDialog(): void
    {
        $monitor = (string) file_get_contents(self::ROOT.'/templates/monitors/detail.html.twig');
        $project = (string) file_get_contents(self::ROOT.'/templates/projects/detail.html.twig');

        self::assertStringContainsString('data-confirm-title="Delete monitor"', $monitor);
        self::assertStringContainsString('data-confirm-title="Delete project"', $project);
        self::assertStringContainsString('data-confirm-title="Rotate DSN key"', $project);
    }
}
