<?php
declare(strict_types=1);

namespace Sonoquill\Text;

/**
 * A paragraph or a heading of the fact script.
 *
 * Headings are carried along because they serve the edited script as chapter
 * hints. They never belong in the spoken text — and for the same reason their
 * numbers are not included in the number diff either.
 *
 * Context blocks are background for the script, not a fact source: the
 * model sees them when writing (forPrompt), but they are neither checked by
 * the fact check nor counted in the number diff. A source uses them for
 * things like "what we talked about yesterday" or the listener's situation.
 * In the stored JSON they are an ordinary block with the type "kontext", so
 * older versions read them as a paragraph.
 */
final class SourceBlock
{
    public const HEADING   = 'ueberschrift';
    public const PARAGRAPH = 'absatz';
    public const CONTEXT   = 'kontext';

    public function __construct(
        public readonly string $type,
        public readonly string $text,
        public readonly int $level = 0
    ) {
    }

    public function isHeading(): bool
    {
        return $this->type === self::HEADING;
    }

    /** Background for the script, not a fact source. */
    public function isContext(): bool
    {
        return $this->type === self::CONTEXT;
    }

    public static function context(string $text): self
    {
        return new self(self::CONTEXT, $text);
    }

    /**
     * @return array{type:string,text:string,level:int}
     */
    public function toArray(): array
    {
        return ['type' => $this->type, 'text' => $this->text, 'level' => $this->level];
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['type'] ?? self::PARAGRAPH),
            (string) ($data['text'] ?? ''),
            (int) ($data['level'] ?? 0)
        );
    }
}
