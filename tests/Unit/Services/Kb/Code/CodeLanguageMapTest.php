<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kb\Code;

use App\Services\Kb\Code\CodeLanguageMap;
use App\Support\Kb\SourceType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CodeLanguageMapTest extends TestCase
{
    #[Test]
    public function extensions_resolve_to_languages_and_mimes_round_trip(): void
    {
        foreach (CodeLanguageMap::EXTENSIONS as $ext => $language) {
            $this->assertSame($language, CodeLanguageMap::languageForExtension('.'.strtoupper($ext)));
            $mime = CodeLanguageMap::mimeForLanguage($language);
            $this->assertSame($language, CodeLanguageMap::languageForMime($mime.'; charset=utf-8'));
        }
    }

    #[Test]
    public function unknown_extension_and_foreign_mime_resolve_to_null(): void
    {
        $this->assertNull(CodeLanguageMap::languageForExtension('exe'));
        $this->assertNull(CodeLanguageMap::languageForMime('text/plain'));
        $this->assertNull(CodeLanguageMap::languageForMime('text/x-cobol'));
        $this->assertNull(CodeLanguageMap::languageForPath('Makefile'));
    }

    #[Test]
    public function sql_yaml_json_have_no_grammar_by_design(): void
    {
        foreach (['sql', 'yaml', 'json'] as $language) {
            $this->assertFalse(CodeLanguageMap::isAstCapable($language));
        }
        foreach (['php', 'typescript', 'python', 'go'] as $language) {
            $this->assertTrue(CodeLanguageMap::isAstCapable($language));
        }
    }

    #[Test]
    public function source_type_code_is_opt_in_for_extensions_and_always_known_for_mimes(): void
    {
        $this->assertSame(SourceType::UNKNOWN, SourceType::fromExtension('php'));
        $this->assertSame(SourceType::CODE, SourceType::fromExtension('php', includeCode: true));
        $this->assertSame(SourceType::MARKDOWN, SourceType::fromExtension('md', includeCode: true));
        $this->assertSame(SourceType::CODE, SourceType::fromMime('text/x-php'));
        $this->assertSame(SourceType::UNKNOWN, SourceType::fromMime('text/x-cobol'));
        $this->assertNotContains('php', SourceType::knownExtensions(true));
        $this->assertContains('php', SourceType::knownExtensions(false, true));
        $this->assertNotContains('text/x-php', SourceType::supportedMimes(true));
        $this->assertContains('text/x-php', SourceType::supportedMimes(false, true));
        $this->assertFalse(SourceType::CODE->isBinary());
    }
}
