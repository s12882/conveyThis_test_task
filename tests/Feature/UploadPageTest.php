<?php

namespace Tests\Feature;

use Tests\TestCase;

class UploadPageTest extends TestCase
{
    public function test_the_upload_page_renders_the_upload_form(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('id="upload-form"', false);
        $response->assertSee('id="file-input"', false);
    }
}
