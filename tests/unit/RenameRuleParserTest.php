<?php

namespace OCA\Files_AutoRename\Tests;

use OCA\Files_AutoRename\Service\RenameRuleParseException;
use OCA\Files_AutoRename\Service\RenameRuleParser;
use OCA\Files_AutoRename\Service\RuleAnnotation;

class RenameRuleParserTest extends \PHPUnit\Framework\TestCase
{
    private RenameRuleParser $parser;

    protected function setUp(): void
    {
        $this->parser = new RenameRuleParser();
    }

    public function testEmptyFileReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->parser->parse(''));
    }

    public function testFileWithOnlyCommentsAndWhitespace(): void
    {
        $contents = <<<TXT
        # comment line
        
        # another comment"
        TXT;
        $this->assertSame([], $this->parser->parse($contents));
    }

    public function testSingleRules(): void
    {
        $contents = <<<TXT
        foo:bar
        baz:qux
        TXT;
        $expected = [
            [
                'patterns' => ['/foo/'],
                'replacements' => ['bar'],
                'annotations' => [],
            ],
            [
                'patterns' => ['/baz/'],
                'replacements' => ['qux'],
                'annotations' => [],
            ]
        ];
        $this->assertSame($expected, $this->parser->parse($contents));
    }

    public function testRuleWithEscapedColon(): void
    {
        $contents = 'foo:bar:baz\:qux';
        $expected = [
            [
                'patterns' => ['/foo:bar/'],
                'replacements' => ['baz:qux'],
                'annotations' => [],
            ]
        ];
        $this->assertSame($expected, $this->parser->parse($contents));
    }

    public function testGroupedRules(): void
    {
        $contents = <<<TXT
        {
        foo:bar
        baz:qux
        }
        TXT;

        $expected = [
            [
                'patterns' => ['/foo/', '/baz/'],
                'replacements' => ['bar', 'qux'],
                'annotations' => [],
            ]
        ];

        $this->assertSame($expected, $this->parser->parse($contents));
    }

    public function testGroupedRulesWithAnnotations(): void
    {
        $contents = <<<TXT
        {
        foo:bar
        } @ConflictCancel
        TXT;

        $expected = [
            [
                'patterns' => ['/foo/'],
                'replacements' => ['bar'],
                'annotations' => [RuleAnnotation::ConflictCancel],
            ]
        ];

        $this->assertSame($expected, $this->parser->parse($contents));
    }

    public function testInvalidRuleFormatThrowsException(): void
    {
        $this->expectException(RenameRuleParseException::class);
        $this->expectExceptionMessage('Invalid rule format at line 1');

        $this->parser->parse('justtextwithoutcolon');
    }

    public function testNestedGroupThrowsException(): void
    {
        $this->expectException(RenameRuleParseException::class);
        $this->expectExceptionMessage('Nested "{" found at line 2');

        $contents = <<<TXT
        {
        {
        foo:bar
        }
        TXT;

        $this->parser->parse($contents);
    }

    public function testClosingWithoutOpeningThrowsException(): void
    {
        $this->expectException(RenameRuleParseException::class);
        $this->expectExceptionMessage('Closing "}" found without matching "{" at line 1');

        $this->parser->parse('}');
    }

    public function testFileEndingInsideGroupThrowsException(): void
    {
        $this->expectException(RenameRuleParseException::class);
        $this->expectExceptionMessage('File ended while inside a group');

        $this->parser->parse("{\nfoo:bar");
    }
}