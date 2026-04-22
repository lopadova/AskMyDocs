<?php

namespace Tests\Unit\Skills;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Structural tests for the Phase 6 shipped skill templates.
 *
 * The skills under `.claude/skills/kb-canonical/` are consumer-facing
 * templates — they must advertise themselves correctly (valid YAML
 * frontmatter, consistent naming, explicit "consumer-side" guidance
 * in the description so they don't auto-activate when editing AskMyDocs
 * itself). These tests catch silent drift.
 */
class CanonicalSkillTemplatesTest extends TestCase
{
    private const SKILLS_ROOT = __DIR__ . '/../../../.claude/skills';

    private const KB_CANONICAL_SKILLS = [
        'promote-decision',
        'promote-module-kb',
        'promote-runbook',
        'link-kb-note',
        'session-close',
    ];

    public function test_all_five_canonical_template_skills_exist(): void
    {
        foreach (self::KB_CANONICAL_SKILLS as $name) {
            $path = self::SKILLS_ROOT . "/kb-canonical/{$name}/SKILL.md";
            $this->assertFileExists($path, "Missing skill template: {$name}");
        }
    }

    public function test_kb_canonical_has_a_readme_explaining_the_template_nature(): void
    {
        $readme = self::SKILLS_ROOT . '/kb-canonical/README.md';
        $this->assertFileExists($readme);

        $content = (string) file_get_contents($readme);
        $this->assertStringContainsString('TEMPLATES', $content);
        $this->assertStringContainsString('consumer', strtolower($content));
    }

    public function test_every_canonical_skill_has_valid_frontmatter(): void
    {
        foreach (self::KB_CANONICAL_SKILLS as $name) {
            $frontmatter = $this->loadFrontmatter("kb-canonical/{$name}/SKILL.md");

            $this->assertArrayHasKey('name', $frontmatter, "[$name] name missing");
            $this->assertSame($name, $frontmatter['name'], "[$name] name mismatch");

            $this->assertArrayHasKey('description', $frontmatter, "[$name] description missing");
            $this->assertIsString($frontmatter['description']);
            $this->assertGreaterThan(100, strlen($frontmatter['description']), "[$name] description too short — skill triggers rely on detailed descriptions");
        }
    }

    public function test_every_template_description_marks_itself_as_consumer_side(): void
    {
        // User rule (memory: project_canonical_compilation + user_auto_workflow):
        // skills must NOT auto-activate when editing AskMyDocs itself.
        // The description must explicitly say "consumer-side".
        foreach (self::KB_CANONICAL_SKILLS as $name) {
            $frontmatter = $this->loadFrontmatter("kb-canonical/{$name}/SKILL.md");
            $description = $frontmatter['description'];

            $this->assertStringContainsStringIgnoringCase(
                'CONSUMER-SIDE',
                $description,
                "[$name] description must explicitly identify itself as consumer-side (must NOT auto-activate when editing AskMyDocs itself)."
            );
        }
    }

    public function test_every_template_has_a_banner_warning_in_the_body(): void
    {
        foreach (self::KB_CANONICAL_SKILLS as $name) {
            $content = (string) file_get_contents(self::SKILLS_ROOT . "/kb-canonical/{$name}/SKILL.md");
            $this->assertStringContainsStringIgnoringCase(
                '> **Banner:**',
                $content,
                "[$name] body must carry an explicit 'Banner:' block flagging it as a consumer-side template."
            );
        }
    }

    public function test_canonical_awareness_skill_is_present(): void
    {
        $path = self::SKILLS_ROOT . '/canonical-awareness/SKILL.md';
        $this->assertFileExists($path);

        $frontmatter = $this->loadFrontmatter('canonical-awareness/SKILL.md');
        $this->assertSame('canonical-awareness', $frontmatter['name']);
        $this->assertIsString($frontmatter['description']);

        $content = (string) file_get_contents($path);
        // R10 skill documents the in-repo rule; unlike the kb-canonical
        // templates, this one SHOULD trigger when editing AskMyDocs code.
        $this->assertStringContainsString('R10', $content);
    }

    public function test_promote_skills_mention_the_writes_nothing_contract(): void
    {
        // ADR 0003 governs the trust boundary: skills produce drafts, only
        // humans commit. Each promote-* skill must echo this rule.
        foreach (['promote-decision', 'promote-module-kb', 'promote-runbook'] as $name) {
            $content = (string) file_get_contents(self::SKILLS_ROOT . "/kb-canonical/{$name}/SKILL.md");
            $this->assertMatchesRegularExpression(
                '/draft|never commits?|writes? nothing/i',
                $content,
                "[$name] must explicitly state that it only produces drafts"
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function loadFrontmatter(string $relativePath): array
    {
        $path = self::SKILLS_ROOT . '/' . $relativePath;
        $content = (string) file_get_contents($path);
        $this->assertMatchesRegularExpression(
            '/\A---\n(.+?)\n---\n/s',
            $content,
            "[$relativePath] missing YAML frontmatter block"
        );
        preg_match('/\A---\n(.+?)\n---\n/s', $content, $m);
        return Yaml::parse($m[1]);
    }
}
