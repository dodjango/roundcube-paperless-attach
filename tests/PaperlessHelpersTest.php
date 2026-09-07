<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/PaperlessHelpers.php';

/**
 * Tests for the pure helpers extracted from the plugin (byte parsing, human
 * sizes, filename/title sanitisation, the consume-task status mapping incl.
 * Paperless's duplicate detection, and the compose-attachment storage-path
 * selection that keeps Roundcube 1.6.x and 1.7+ apart).
 */
final class PaperlessHelpersTest extends TestCase
{
    // ---- parseBytes -------------------------------------------------------

    /**
     * @dataProvider byteShorthands
     */
    public function testParseBytes(string $input, int $expected): void
    {
        $this->assertSame($expected, PaperlessHelpers::parseBytes($input));
    }

    public function byteShorthands(): array
    {
        return [
            'megabytes'        => ['15M', 15 * 1024 * 1024],
            'megabytes 256'    => ['256M', 256 * 1024 * 1024],
            'gigabytes'        => ['2G', 2 * 1024 * 1024 * 1024],
            'kilobytes'        => ['1024K', 1024 * 1024],
            'plain bytes'      => ['16777216', 16777216],
            'lowercase suffix' => ['8m', 8 * 1024 * 1024],
            'whitespace'       => ['  10M  ', 10 * 1024 * 1024],
            'empty → 0'        => ['', 0],
            'unlimited -1 → 0' => ['-1', 0],
            'zero → 0'         => ['0', 0],
        ];
    }

    public function testParseBytesDoesNotTruncateShorthandToFirstDigits(): void
    {
        // The whole point: a plain (int) cast of "15M" is 15 *bytes* — guard it.
        $this->assertNotSame(15, PaperlessHelpers::parseBytes('15M'));
        $this->assertSame(15 * 1024 * 1024, PaperlessHelpers::parseBytes('15M'));
    }

    // ---- humanSize --------------------------------------------------------

    public function testHumanSize(): void
    {
        $this->assertSame('', PaperlessHelpers::humanSize(0));
        $this->assertSame('', PaperlessHelpers::humanSize(-5));
        $this->assertSame('512 B', PaperlessHelpers::humanSize(512));
        $this->assertSame('1 KB', PaperlessHelpers::humanSize(1024));
        $this->assertSame('2 KB', PaperlessHelpers::humanSize(2048));
        $this->assertSame('1.5 MB', PaperlessHelpers::humanSize((int) (1.5 * 1024 * 1024)));
    }

    // ---- sanitizePdfName --------------------------------------------------

    public function testSanitizePdfNameAppendsExtensionAndKeepsCleanTitles(): void
    {
        $this->assertSame('Rechnung 2024.pdf', PaperlessHelpers::sanitizePdfName('Rechnung 2024'));
    }

    public function testSanitizePdfNameStripsPathAndControlChars(): void
    {
        $this->assertSame('etc_passwd.pdf', PaperlessHelpers::sanitizePdfName('etc/passwd'));
        $this->assertSame('a_b.pdf', PaperlessHelpers::sanitizePdfName('a:b'));
        $this->assertSame('x_y.pdf', PaperlessHelpers::sanitizePdfName("x\ny"));
        $this->assertSame('a_b_c.pdf', PaperlessHelpers::sanitizePdfName('a<b>c'));
    }

    public function testSanitizePdfNameFallsBackForEmpty(): void
    {
        $this->assertSame('document.pdf', PaperlessHelpers::sanitizePdfName(''));
        $this->assertSame('document.pdf', PaperlessHelpers::sanitizePdfName('   '));
    }

    public function testSanitizePdfNameCapsLength(): void
    {
        $name = PaperlessHelpers::sanitizePdfName(str_repeat('a', 500));
        $this->assertSame(200 + 4, strlen($name)); // 200 chars + ".pdf"
        $this->assertStringEndsWith('.pdf', $name);
    }

    // ---- titleFromFilename ------------------------------------------------

    public function testTitleFromFilenameDropsSingleExtension(): void
    {
        $this->assertSame('Rechnung_2024', PaperlessHelpers::titleFromFilename('Rechnung_2024.pdf'));
        $this->assertSame('scan', PaperlessHelpers::titleFromFilename('scan.PNG'));
        $this->assertSame('archive.tar', PaperlessHelpers::titleFromFilename('archive.tar.gz'));
    }

    public function testTitleFromFilenameKeepsNameWithoutExtension(): void
    {
        $this->assertSame('invoice', PaperlessHelpers::titleFromFilename('invoice'));
    }

    // ---- mapTaskStatus ----------------------------------------------------

    public function testMapTaskStatusSuccess(): void
    {
        $this->assertSame('success', PaperlessHelpers::mapTaskStatus('SUCCESS', 'New document id 42 created'));
        $this->assertSame('success', PaperlessHelpers::mapTaskStatus('success', '')); // case-insensitive
    }

    /**
     * @dataProvider duplicateResults
     */
    public function testMapTaskStatusDuplicate(string $result): void
    {
        $this->assertSame('duplicate', PaperlessHelpers::mapTaskStatus('FAILURE', $result));
    }

    public function duplicateResults(): array
    {
        return [
            'paperless wording' => ['Not consuming foo.pdf: It is a duplicate of Bar (#7).'],
            'already exists'    => ['A document with this checksum already exists.'],
        ];
    }

    public function testMapTaskStatusPlainFailure(): void
    {
        $this->assertSame('failure', PaperlessHelpers::mapTaskStatus('FAILURE', 'OCR failed on page 2'));
    }

    public function testMapTaskStatusPendingForEverythingElse(): void
    {
        foreach (['PENDING', 'STARTED', 'RETRY', 'RECEIVED', ''] as $state) {
            $this->assertSame('pending', PaperlessHelpers::mapTaskStatus($state, ''), "state '$state'");
        }
    }

    // ---- usesUploadsTable -------------------------------------------------

    /**
     * Roundcube 1.7+ exposes rcube_uploads::insert_uploaded_file(); 1.6.x does
     * not. That method is the feature probe deciding whether the attachment row
     * is written to the `uploads` DB table or to the compose session.
     */
    public function testUsesUploadsTableDetectsRoundcube17(): void
    {
        $rc17 = new class {
            public function insert_uploaded_file(&$data, $hook = null)
            {
                return true;
            }
        };

        $this->assertTrue(PaperlessHelpers::usesUploadsTable($rc17));
    }

    public function testUsesUploadsTableDetectsRoundcube16(): void
    {
        // 1.6.x rcmail: no uploads table, so no insert_uploaded_file().
        $rc16 = new class {
            public function insert_uploaded_file_lookalike()
            {
                return true;
            }
        };

        $this->assertFalse(PaperlessHelpers::usesUploadsTable($rc16));
    }

    /**
     * @dataProvider nonObjects
     *
     * @param mixed $value
     */
    public function testUsesUploadsTableRejectsNonObjects($value): void
    {
        $this->assertFalse(PaperlessHelpers::usesUploadsTable($value));
    }

    public function nonObjects(): array
    {
        return [
            'null'   => [null],
            'string' => ['rcmail'],
            'array'  => [[]],
            'int'    => [0],
        ];
    }

    // ---- legacyAttachmentRow ---------------------------------------------

    public function testLegacyAttachmentRowStripsTransientKeys(): void
    {
        $row = PaperlessHelpers::legacyAttachmentRow([
            'id'         => 'abc123',
            'group'      => 'compose1',
            'name'       => 'Invoice.pdf',
            'mimetype'   => 'application/pdf',
            'path'       => '/tmp/roundcube-temp/rcmAttmnt123',
            'size'       => 4096,
            'charset'    => null,
            'data'       => 'raw bytes that must not be persisted',
            'status'     => true,
            'content_id' => null,
            'abort'      => false,
        ]);

        foreach (['data', 'status', 'content_id', 'abort'] as $transient) {
            $this->assertArrayNotHasKey($transient, $row, "'$transient' must not be persisted");
        }
    }

    public function testLegacyAttachmentRowKeepsWhatSendTimeNeeds(): void
    {
        $row = PaperlessHelpers::legacyAttachmentRow([
            'id'       => 'abc123',
            'group'    => 'compose1',
            'name'     => 'Invoice.pdf',
            'mimetype' => 'application/pdf',
            'path'     => '/tmp/roundcube-temp/rcmAttmnt123',
            'size'     => 4096,
            'charset'  => null,
            'data'     => null,
            'status'   => true,
        ]);

        // attach_at_send() reads exactly these off the session row.
        $this->assertSame('abc123', $row['id']);
        $this->assertSame('Invoice.pdf', $row['name']);
        $this->assertSame('application/pdf', $row['mimetype']);
        $this->assertSame('/tmp/roundcube-temp/rcmAttmnt123', $row['path']);
        $this->assertSame(4096, $row['size']);
        $this->assertArrayHasKey('charset', $row);
    }

    /**
     * The marker is what makes attach_at_send() pick the row up on 1.6.x. It
     * must never be set on the 1.7+ path, where core attaches the document
     * itself and a second attach would duplicate it.
     */
    public function testLegacyAttachmentRowMarksRowForSendTimeReattach(): void
    {
        $row = PaperlessHelpers::legacyAttachmentRow(['id' => 'x', 'name' => 'a.pdf']);

        $this->assertTrue($row['paperless']);
    }
}
