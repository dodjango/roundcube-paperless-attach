<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/PaperlessClient.php';

/**
 * Test double: overrides the three wire-transport SEAMS of PaperlessClient
 * (request / uploadTransport / downloadTransport) so the parsing, validation,
 * mapping and SSRF-guard logic can be exercised with canned responses — no real
 * network, no Paperless instance. Everything else runs the production code.
 */
final class TestablePaperlessClient extends PaperlessClient
{
    /** @var array<int,array{method:string,path:string}> recorded GET/JSON requests */
    public array $requests = [];

    /** @var array<int,array{status:int,body:string}> FIFO queue for request() */
    public array $responses = [];

    /** @var array|null args captured from the last uploadTransport() call */
    public ?array $lastUpload = null;

    /** @var array{status:int,body:string} canned uploadTransport() result */
    public array $uploadResponse = ['status' => 0, 'body' => ''];

    /** @var array<int,string> dest paths seen by downloadTransport() */
    public array $downloads = [];

    /** @var int status downloadTransport() returns */
    public int $downloadResult = 0;

    /** @var string|null if set, downloadTransport() writes this to the dest file */
    public ?string $downloadFileContent = null;

    public function __construct()
    {
        parent::__construct('http://paperless.test', 'TESTTOKEN');
    }

    /** Queue a canned response for the next request()/search()/etc. call. */
    public function pushResponse(int $status, $body): void
    {
        $this->responses[] = [
            'status' => $status,
            'body'   => is_string($body) ? $body : (string) json_encode($body),
        ];
    }

    protected function request(string $method, string $path): array
    {
        $this->requests[] = ['method' => $method, 'path' => $path];
        $r = array_shift($this->responses);
        if ($r === null) {
            return ['status' => 0, 'body' => '', 'content_type' => ''];
        }
        return ['status' => $r['status'], 'body' => $r['body'], 'content_type' => ''];
    }

    protected function uploadTransport(string $url, string $path, string $filename, string $mimetype, ?string $title): array
    {
        $this->lastUpload = compact('url', 'path', 'filename', 'mimetype', 'title');
        return $this->uploadResponse;
    }

    protected function downloadTransport(string $url, string $destPath): int
    {
        $this->downloads[] = $destPath;
        if ($this->downloadFileContent !== null) {
            file_put_contents($destPath, $this->downloadFileContent);
        }
        return $this->downloadResult;
    }
}

final class PaperlessClientTest extends TestCase
{
    private function client(): TestablePaperlessClient
    {
        return new TestablePaperlessClient();
    }

    /** Parse the query string of a recorded request path into an assoc array. */
    private function query(string $path): array
    {
        $qs  = [];
        $pos = strpos($path, '?');
        if ($pos !== false) {
            parse_str(substr($path, $pos + 1), $qs);
        }
        return $qs;
    }

    // ---- validateId -------------------------------------------------------

    public function testValidateIdAcceptsPositiveIntAndNumericString(): void
    {
        $c = $this->client();
        $this->assertSame(5, $c->validateId(5));
        $this->assertSame(5, $c->validateId('5'));
        $this->assertSame(1, $c->validateId(1));
    }

    /**
     * @dataProvider invalidIds
     * @param mixed $bad
     */
    public function testValidateIdRejects($bad): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->client()->validateId($bad);
    }

    public function invalidIds(): array
    {
        return [
            'zero int'     => [0],
            'negative int' => [-1],
            'zero string'  => ['0'],
            'neg string'   => ['-1'],
            'float string' => ['1.5'],
            'alpha'        => ['abc'],
            'empty'        => [''],
            'null'         => [null],
        ];
    }

    // ---- testConnection ---------------------------------------------------

    public function testConnectionMapsStatusToReason(): void
    {
        $cases = [200 => 'ok', 401 => 'badtoken', 403 => 'badtoken', 500 => 'unreachable', 0 => 'unreachable'];
        foreach ($cases as $status => $reason) {
            $c = $this->client();
            $c->pushResponse($status, '');
            $res = $c->testConnection();
            $this->assertSame($reason, $res['reason'], "status $status");
            $this->assertSame($status === 200, $res['ok']);
        }
    }

    // ---- search -----------------------------------------------------------

    public function testSearchBuildsAllStructuredFilterParams(): void
    {
        $c = $this->client();
        $c->pushResponse(200, ['count' => 2, 'next' => 'http://x/n', 'results' => [['id' => 1], ['id' => 2]]]);

        $res = $c->search([
            'query'         => 'rechnung',
            'tags'          => [3, 5],
            'correspondent' => 7,
            'document_type' => 2,
            'date_from'     => '2024-01-01',
            'date_to'       => '2024-12-31',
            'page'          => 2,
            'page_size'     => 25,
        ]);

        $qs = $this->query($c->requests[0]['path']);
        $this->assertStringStartsWith('/api/documents/?', $c->requests[0]['path']);
        $this->assertSame('-created', $qs['ordering']);
        $this->assertSame('rechnung', $qs['query']);
        $this->assertSame('3,5', $qs['tags__id__in']);
        $this->assertSame('7', $qs['correspondent__id']);
        $this->assertSame('2', $qs['document_type__id']);
        $this->assertSame('2024-01-01', $qs['created__date__gte']);
        $this->assertSame('2024-12-31', $qs['created__date__lte']);
        $this->assertSame('2', $qs['page']);
        $this->assertSame('25', $qs['page_size']);

        $this->assertSame(2, $res['count']);
        $this->assertSame('http://x/n', $res['next']);
        $this->assertCount(2, $res['results']);
    }

    public function testSearchOmitsInvalidIsoDate(): void
    {
        // isIsoDate is a FORMAT check (\d{4}-\d{2}-\d{2}); non-matching values are
        // dropped. (It does not validate the calendar, so e.g. "2024-13-99" would
        // pass the format — that's why these use clearly non-ISO strings.)
        $c = $this->client();
        $c->pushResponse(200, ['count' => 0, 'next' => null, 'results' => []]);
        $c->search(['query' => 'x', 'date_from' => 'not-a-date', 'date_to' => '31/12/2024']);

        $qs = $this->query($c->requests[0]['path']);
        $this->assertArrayNotHasKey('created__date__gte', $qs);
        $this->assertArrayNotHasKey('created__date__lte', $qs);
    }

    public function testSearchReturnsNullOnErrorOrBadJson(): void
    {
        $c = $this->client();
        $c->pushResponse(500, '');
        $this->assertNull($c->search(['query' => 'x']));

        $c->pushResponse(200, 'not-json');
        $this->assertNull($c->search(['query' => 'x']));
    }

    // ---- getDocumentInfo --------------------------------------------------

    public function testGetDocumentInfoArchivePresence(): void
    {
        $c = $this->client();
        $c->pushResponse(200, ['title' => 'T', 'archived_file_name' => '2024-x.pdf']);
        $info = $c->getDocumentInfo(1);
        $this->assertTrue($info['has_archive']);
        $this->assertSame('T', $info['title']);

        $c->pushResponse(200, ['title' => 'T2', 'archived_file_name' => null]);
        $this->assertFalse($c->getDocumentInfo(1)['has_archive']);

        $c->pushResponse(200, ['title' => 'T3']);
        $this->assertFalse($c->getDocumentInfo(1)['has_archive']);

        $c->pushResponse(404, '');
        $this->assertNull($c->getDocumentInfo(1));
    }

    // ---- getTaskStatus ----------------------------------------------------

    public function testGetTaskStatusRejectsMalformedUuidWithoutRequest(): void
    {
        $c = $this->client();
        $this->assertNull($c->getTaskStatus('../etc/passwd'));
        $this->assertNull($c->getTaskStatus('not a uuid'));
        $this->assertCount(0, $c->requests, 'no request must be issued for a bad task id');
    }

    public function testGetTaskStatusNormalizesListForm(): void
    {
        $c = $this->client();
        $uuid = 'abcdef12-3456-7890-abcd-ef1234567890';
        $c->pushResponse(200, [['status' => 'success', 'result' => 'New document id 42 created', 'related_document' => '42']]);
        $t = $c->getTaskStatus($uuid);
        $this->assertSame('SUCCESS', $t['status']); // upper-cased
        $this->assertSame(42, $t['related_document']);
        $this->assertStringContainsString('New document', $t['result']);
        $this->assertStringContainsString('task_id=' . $uuid, $c->requests[0]['path']);
    }

    public function testGetTaskStatusHandlesPaginatedForm(): void
    {
        $c = $this->client();
        $c->pushResponse(200, ['results' => [['status' => 'FAILURE', 'result' => 'It is a duplicate of Foo (#7)', 'related_document' => '7']]]);
        $t = $c->getTaskStatus('abcdef12-3456-7890-abcd-ef1234567890');
        $this->assertSame('FAILURE', $t['status']);
        $this->assertSame(7, $t['related_document']);
    }

    public function testGetTaskStatusNullOnEmptyOrError(): void
    {
        $c = $this->client();
        $c->pushResponse(200, []);
        $this->assertNull($c->getTaskStatus('abcdef12-3456-7890-abcd-ef1234567890'));

        $c->pushResponse(500, '');
        $this->assertNull($c->getTaskStatus('abcdef12-3456-7890-abcd-ef1234567890'));
    }

    // ---- uploadDocument ---------------------------------------------------

    public function testUploadDocumentParsesTaskUuid(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pp');
        file_put_contents($tmp, 'PDFDATA');
        try {
            $c = $this->client();

            // JSON-quoted UUID (what post_document actually returns)
            $c->uploadResponse = ['status' => 200, 'body' => '"f1d2c3b4-aaaa-bbbb-cccc-001122334455"'];
            $this->assertSame('f1d2c3b4-aaaa-bbbb-cccc-001122334455', $c->uploadDocument($tmp, 'f.pdf', 'application/pdf', 'My title'));
            $this->assertSame($tmp, $c->lastUpload['path']);
            $this->assertSame('f.pdf', $c->lastUpload['filename']);
            $this->assertSame('My title', $c->lastUpload['title']);

            // bare (unquoted) UUID body — fallback trim path
            $c->uploadResponse = ['status' => 200, 'body' => 'bareuuid1234'];
            $this->assertSame('bareuuid1234', $c->uploadDocument($tmp, 'f.pdf', 'application/pdf', null));

            // non-200 → null
            $c->uploadResponse = ['status' => 400, 'body' => 'error'];
            $this->assertNull($c->uploadDocument($tmp, 'f.pdf', 'application/pdf', null));
        } finally {
            @unlink($tmp);
        }
    }

    public function testUploadDocumentRejectsMissingFileWithoutTransport(): void
    {
        $c = $this->client();
        $this->assertNull($c->uploadDocument('/no/such/file.pdf', 'f.pdf', 'application/pdf', null));
        $this->assertNull($c->lastUpload, 'no transport call for a missing file');
    }

    // ---- download ---------------------------------------------------------

    public function testDownloadWritesFileOn200(): void
    {
        $dest = tempnam(sys_get_temp_dir(), 'ppd');
        @unlink($dest);
        $c = $this->client();
        $c->downloadResult = 200;
        $c->downloadFileContent = 'PDFBYTES';
        $this->assertTrue($c->download(1, $dest));
        $this->assertSame('PDFBYTES', file_get_contents($dest));
        @unlink($dest);
    }

    public function testDownloadRemovesFileOnNon200(): void
    {
        $dest = tempnam(sys_get_temp_dir(), 'ppd');
        @unlink($dest);
        $c = $this->client();
        $c->downloadResult = 404;
        $c->downloadFileContent = 'partial';
        $this->assertFalse($c->download(1, $dest));
        $this->assertFileDoesNotExist($dest);
    }

    public function testDownloadRejectsEmptyFile(): void
    {
        $dest = tempnam(sys_get_temp_dir(), 'ppd');
        @unlink($dest);
        $c = $this->client();
        $c->downloadResult = 200;
        $c->downloadFileContent = '';
        $this->assertFalse($c->download(1, $dest));
        $this->assertFileDoesNotExist($dest);
    }

    // ---- listAll (pagination + SSRF path reduction) -----------------------

    public function testListAllFollowsNextAndReducesAbsoluteUrlToPath(): void
    {
        $c = $this->client();
        $c->pushResponse(200, ['results' => [['id' => 1], ['id' => 2]], 'next' => 'http://paperless.test/api/tags/?page=2&page_size=250']);
        $c->pushResponse(200, ['results' => [['id' => 3]], 'next' => null]);

        $all = $c->listAll('/api/tags/');
        $this->assertCount(3, $all);

        // First page request, and the SSRF guard: the absolute `next` was reduced
        // to a base-relative PATH (no smuggled host) before the second request.
        $this->assertSame('/api/tags/?page_size=250', $c->requests[0]['path']);
        $this->assertSame('/api/tags/?page=2&page_size=250', $c->requests[1]['path']);
    }

    public function testListAllReturnsNullOnError(): void
    {
        $c = $this->client();
        $c->pushResponse(500, '');
        $this->assertNull($c->listAll('/api/tags/'));
    }
}
