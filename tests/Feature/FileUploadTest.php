<?php

namespace Tests\Feature;

use App\Jobs\DeleteExpiredFile;
use App\Models\File as FileModel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class FileUploadTest extends TestCase
{
    use RefreshDatabase;

    // Padded with a PDF comment line so the fixture clears the app's 1KB minimum file size rule.
    private const MINIMAL_PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 3 3]>>endobj\ntrailer<</Size 4/Root 1 0 R>>\n%%EOF\n%".PHP_EOL;

    private static function minimalPdf(): string
    {
        return self::MINIMAL_PDF.str_repeat('0', 1200);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        // Real requests go through M2's CSRF-token-header wiring; these tests
        // exercise upload validation/storage, not CSRF enforcement itself.
        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    public function test_uploading_a_valid_pdf_stores_it_and_creates_a_record(): void
    {
        $upload = UploadedFile::fake()->createWithContent('report.pdf', self::minimalPdf());

        $response = $this->postJson('/files', ['file' => $upload]);

        $response->assertCreated();

        $this->assertDatabaseCount('files', 1);

        $file = FileModel::first();
        $this->assertSame('report.pdf', $file->original_name);
        $this->assertSame('application/pdf', $file->mime_type);
        $this->assertSame('pending', $file->scan_status);
        $this->assertNotNull($file->expires_at);

        Storage::disk('local')->assertExists($file->stored_path);
    }

    public function test_uploading_a_file_schedules_its_expiry_deletion(): void
    {
        Queue::fake();

        $upload = UploadedFile::fake()->createWithContent('report.pdf', self::minimalPdf());

        $this->postJson('/files', ['file' => $upload])->assertCreated();

        $file = FileModel::first();

        Queue::assertPushed(DeleteExpiredFile::class, function (DeleteExpiredFile $job) use ($file) {
            $fileId = (new \ReflectionProperty($job, 'fileId'))->getValue($job);

            return (int) $fileId === $file->id
                && $job->delay?->equalTo($file->expires_at);
        });
    }

    public function test_uploading_a_valid_docx_stores_it_and_creates_a_record(): void
    {
        $upload = UploadedFile::fake()->createWithContent('contract.docx', $this->minimalDocx());

        $response = $this->postJson('/files', ['file' => $upload]);

        $response->assertCreated();
        $this->assertDatabaseCount('files', 1);
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            FileModel::first()->mime_type,
        );
    }

    public function test_rejects_a_disallowed_file_type(): void
    {
        $upload = UploadedFile::fake()->create('notes.txt', 10, 'text/plain');

        $response = $this->postJson('/files', ['file' => $upload]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('files', 0);
    }

    public function test_rejects_a_file_over_the_size_limit(): void
    {
        $upload = UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf');

        $response = $this->postJson('/files', ['file' => $upload]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('files', 0);
    }

    public function test_rejects_a_file_whose_name_is_not_valid_utf8(): void
    {
        $invalidName = "broken-\xB1\x31.pdf";

        $upload = UploadedFile::fake()->createWithContent($invalidName, self::minimalPdf());

        $response = $this->postJson('/files', ['file' => $upload]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('files', 0);
    }

    public function test_rejects_a_file_that_claims_to_be_a_pdf_but_is_not(): void
    {
        // Mimics a spoofed upload: the browser/client claims application/pdf,
        // but the actual bytes don't start with the PDF magic header.
        $upload = UploadedFile::fake()->create('fake.pdf', 10, 'application/pdf');

        $response = $this->postJson('/files', ['file' => $upload]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('files', 0);
    }

    private function minimalDocx(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'docx');

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body/></w:document>');
        // Padding so the fixture clears the app's 1KB minimum file size rule.
        // Random (incompressible) bytes, since repeated bytes get deflated back under 1KB.
        $zip->addFromString('word/media/pad.bin', random_bytes(1200));
        $zip->close();

        $content = file_get_contents($path);
        unlink($path);

        return $content;
    }
}
