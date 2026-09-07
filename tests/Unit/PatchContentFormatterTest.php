<?php

namespace Tests\Unit;

use App\Services\PatchContentFormatter;
use Tests\TestCase;

class PatchContentFormatterTest extends TestCase
{
    public function test_formatter_models_headings_lists_nesting_and_wrapped_lines(): void
    {
        $sections = (new PatchContentFormatter())->format(<<<'TEXT'
*** Items ***
- First item
- - Nested item
continued on the next source line

+ Another item

1. Ordered item
2) Second ordered item
TEXT);

        $this->assertCount(1, $sections);
        $this->assertSame('Items', $sections[0]['title']);
        $this->assertSame('list', $sections[0]['blocks'][0]['type']);
        $this->assertFalse($sections[0]['blocks'][0]['ordered']);
        $this->assertSame(1, $sections[0]['blocks'][0]['items'][1]['depth']);
        $this->assertStringEndsWith('continued on the next source line', $sections[0]['blocks'][0]['items'][1]['text']);
        $this->assertSame('Another item', $sections[0]['blocks'][1]['items'][0]['text']);
        $this->assertTrue($sections[0]['blocks'][2]['ordered']);
    }

    public function test_highlighting_escapes_archive_and_query_text_before_adding_markup(): void
    {
        $html = (new PatchContentFormatter())
            ->highlight('<img src=x onerror=alert(1)> Plane of Time', '"<img" Plane')
            ->toHtml();

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('onerror=alert(1)>', $html);
        $this->assertStringContainsString('&lt;img', $html);
        $this->assertStringContainsString('<mark class="patch-highlight">Plane</mark>', $html);
    }
}
