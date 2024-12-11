<?php

declare(strict_types=1);

namespace CustomFixer;

use SplFileInfo;
use Throwable;

use PHPUnit\Framework\TestCase;

use PhpCsFixer\Tokenizer\Tokens;

use KepCustomFixer\CustomFixer\OrderedImportsGroupFixer;

final class OrderedImportsGroupFixerTest extends TestCase
{
    /**
     * @var OrderedImportsGroupFixer
     */
    private OrderedImportsGroupFixer $fixer;

    protected function setUp(): void
    {
        $this->fixer = new OrderedImportsGroupFixer();
    }

    public function testIsCandidate(): void
    {
        $source = '<?php use Foo\Bar;';

        $tokens = Tokens::fromCode($source);

        $this->assertTrue($this->fixer->isCandidate($tokens));
    }

    public function testFixSimpleAlphabetical(): void
    {
        $this->fixer->configure([
            'sort_algorithm' => 'alpha',
        ]);

        $source = <<<'EOF'
<?php
use Z;
use A;
EOF;

        $expected = <<<'EOF'
<?php
use A;
use Z;
EOF;

        $this->assertSame($expected, $this->applyFix($source));
    }

    public function testFixWithImportsOrder(): void
    {
        $this->fixer->configure([
            'sort_algorithm' => 'alpha',
            'imports_order' => ['const', 'class', 'function'],
        ]);

        $source = <<<'EOF'
<?php
use function Zend\foo;
use const App\BAR;
use App\Zed;
use App\Aaa;
EOF;

        $expected = <<<'EOF'
<?php
use const App\BAR;
use App\Aaa;
use App\Zed;

use function Zend\foo;
EOF;

        $this->assertSame($expected, $this->applyFix($source));
    }

    public function testFixWithBlankLinesBetweenGroups(): void
    {
        $this->fixer->configure([
            'sort_algorithm' => 'alpha',
        ]);

        $source = <<<'EOF'
<?php
use DI\ContainerBuilder;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Monolog\Logger;
EOF;

        $expected = <<<'EOF'
<?php
use DI\ContainerBuilder;

use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;

use Monolog\Logger;
EOF;

        $this->assertSame($expected, $this->applyFix($source));
    }

    public function testSortByNamespacePriority(): void
    {
        $this->fixer->configure([
            'sort_algorithm' => 'namespace',
            'namespace_priority' => [
                'App\\' => 1,
                'DI\\' => -5
            ],
        ]);

        $source = <<<'EOF'
<?php
use App\Zed;
use App\Controller\Foo;
use DI\ContainerBuilder;
use App\Aaa;
EOF;

        $expected = <<<'EOF'
<?php
use DI\ContainerBuilder;

use App\Aaa;
use App\Controller\Foo;
use App\Zed;
EOF;

        $this->assertSame($expected, $this->applyFix($source));
    }

    public function testConfigurationWithInvalidSortAlgorithm(): void
    {
        $this->expectException(Throwable::class);

        $this->fixer->configure([
            'sort_algorithm' => 'invalid_sort'
        ]);
    }

    public function testConfigurationWithMissingImportType(): void
    {
        $this->expectException(Throwable::class);

        $this->fixer->configure([
            'imports_order' => ['const', 'class'] // 'function' missing
        ]);
    }

    public function testGetName(): void
    {
        $this->assertSame('CustomFixer/ordered_imports_group', $this->fixer->getName());
    }

    public function testGetPriority(): void
    {
        $this->assertSame(-31, $this->fixer->getPriority());
    }

    public function testGetDefinition(): void
    {
        $definition = $this->fixer->getDefinition();
        $this->assertNotEmpty($definition->getSummary());
        $this->assertNotEmpty($definition->getCodeSamples());
    }

    private function applyFix(string $source): string
    {
        $tokens = Tokens::fromCode($source);
        $file = new SplFileInfo('dummy.php');

        if ($this->fixer->isCandidate($tokens) && $this->fixer->supports($file)) {
            $this->fixer->fix($file, $tokens);
        }

        return $tokens->generateCode();
    }
}
