<?php

namespace Tests\Feature\Exams;

use Tests\TestCase;

class InvigilatorAppTest extends TestCase
{
    public function test_the_app_can_be_installed(): void
    {
        $m = $this->get('/manifest.webmanifest')->assertOk()->assertHeader('Content-Type', 'application/manifest+json')->json();
        $this->assertSame('/exams/monitor', $m['start_url']);
        $this->assertSame('standalone', $m['display']);
        $this->assertCount(2, $m['icons']);
        $this->assertFileExists(public_path('sw.js'));
        $this->assertFileExists(public_path('icon-512.png'));
    }
}
