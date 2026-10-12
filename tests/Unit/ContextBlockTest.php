<?php
declare(strict_types=1);

namespace Sonoquill\Tests\Unit;

use Sonoquill\Pipeline\Gate;
use Sonoquill\Text\SourceBlock;
use Sonoquill\Text\SourceDocument;
use Sonoquill\Tests\WpTestCase;

/**
 * Context blocks: background for the script, not a fact source.
 */
final class ContextBlockTest extends WpTestCase
{
    private function document(): SourceDocument
    {
        return new SourceDocument([
            new SourceBlock(SourceBlock::HEADING, 'Heute', 1),
            new SourceBlock(SourceBlock::PARAGRAPH, 'Es hat 23 Grad.'),
            SourceBlock::context('Gestern war sie 40 Minuten spazieren.'),
        ]);
    }

    public function testPromptIncludesContextMarked(): void
    {
        $prompt = $this->document()->forPrompt();

        self::assertStringContainsString("<context>\nGestern war sie 40 Minuten spazieren.\n</context>", $prompt);
        self::assertStringContainsString('Es hat 23 Grad.', $prompt);
    }

    public function testFactCheckAndBodyTextLeaveContextOut(): void
    {
        self::assertStringNotContainsString('spazieren', $this->document()->forFactCheck());
        self::assertStringContainsString('## Heute', $this->document()->forFactCheck());
        self::assertSame('Es hat 23 Grad.', $this->document()->bodyText());
    }

    public function testJsonStaysCompatible(): void
    {
        $array = $this->document()->toArray();

        self::assertSame(['type' => 'kontext', 'text' => 'Gestern war sie 40 Minuten spazieren.', 'level' => 0], $array[2]);
        self::assertTrue(SourceDocument::fromArray($array)->blocks[2]->isContext());
        // Old data without the type still reads as a paragraph.
        self::assertFalse(SourceBlock::fromArray(['text' => 'x'])->isContext());
    }

    public function testNumberDiffIgnoresContextFigures(): void
    {
        $this->settings(['podcast_language' => 'de']);
        $id = $this->episode([
            // A source that put everything into source_text itself.
            'source_text'   => "Es hat 23 Grad.\n\nGestern war sie 40 Minuten spazieren.",
            'source_blocks' => (string) json_encode($this->document()->toArray()),
            'script_text'   => 'Es hat dreiundzwanzig Grad.',
        ]);

        $result = Gate::evaluate($id);

        self::assertSame([], $result['diff']['fehlt'], 'The 40 from the context is not a missing figure.');
        self::assertFalse($result['blocking']);
    }
}
