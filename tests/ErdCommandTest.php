<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Tests;

use Clcbws\DatabaseLifecycleSuite\ErdGenerator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

class ErdCommandTest extends TestCase
{
    protected ErdGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new ErdGenerator;

        Schema::create('authors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        Schema::create('books', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->unsignedBigInteger('author_id');
            $table->foreign('author_id')->references('id')->on('authors');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('books');
        Schema::dropIfExists('authors');
        parent::tearDown();
    }

    public function test_it_generates_valid_mermaid_erd(): void
    {
        $mermaid = $this->generator->generateMermaid('testing');

        $this->assertStringStartsWith("erDiagram\n", $mermaid);
        $this->assertStringContainsString('authors {', $mermaid);
        $this->assertStringContainsString('books {', $mermaid);
        $this->assertStringContainsString('books }o--|| authors', $mermaid);
    }

    public function test_it_executes_erd_artisan_command(): void
    {
        Artisan::call('db:erd', ['--database' => 'testing']);
        $output = Artisan::output();
        $this->assertStringContainsString('erDiagram', $output);
        $this->assertStringContainsString('authors', $output);
        $this->assertStringContainsString('books', $output);
    }

    public function test_it_saves_mermaid_to_file(): void
    {
        $filePath = sys_get_temp_dir().'/test_erd_'.bin2hex(random_bytes(4)).'.mmd';

        try {
            Artisan::call('db:erd', [
                '--database' => 'testing',
                '--output' => $filePath,
            ]);

            $this->assertFileExists($filePath);
            $content = file_get_contents($filePath);
            $this->assertStringContainsString('erDiagram', $content);
            $this->assertStringContainsString('authors', $content);
        } finally {
            @unlink($filePath);
        }
    }
}
