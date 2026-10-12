<?php
declare(strict_types=1);

namespace Sonoquill\Tests;

use PHPUnit\Framework\TestCase;
use Sonoquill\Settings\Options;
use Sonoquill\Source\Sources;

/**
 * Base for tests that run code calling WordPress functions: fresh options,
 * hooks, HTTP stubs and an empty in-memory episodes table per test.
 */
abstract class WpTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Wp::reset();
        Sources::reset();
    }

    /**
     * @param array<string,mixed> $values
     */
    protected function settings(array $values): void
    {
        $stored = get_option(Options::OPTION, []);
        update_option(Options::OPTION, array_merge(is_array($stored) ? $stored : [], $values));
    }

    /**
     * @param array<string,mixed> $fields
     */
    protected function episode(array $fields = []): int
    {
        return \Sonoquill\Db\EpisodeRepository::create($fields);
    }
}
