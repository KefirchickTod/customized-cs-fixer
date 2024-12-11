<?php

namespace KepCustomFixer\CustomFixer;

use PhpCsFixer\AbstractFixer;
use PhpCsFixer\Fixer\ConfigurableFixerInterface;
use PhpCsFixer\Fixer\ConfigurableFixerTrait;
use PhpCsFixer\Fixer\WhitespacesAwareFixerInterface;
use PhpCsFixer\FixerConfiguration\FixerConfigurationResolver;
use PhpCsFixer\FixerConfiguration\FixerConfigurationResolverInterface;
use PhpCsFixer\FixerConfiguration\FixerOptionBuilder;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\Preg;
use PhpCsFixer\Tokenizer\CT;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;
use PhpCsFixer\Tokenizer\TokensAnalyzer;
use PhpCsFixer\Utils;

use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;

final class OrderedImportsGroupFixer extends AbstractFixer implements ConfigurableFixerInterface, WhitespacesAwareFixerInterface
{
    use ConfigurableFixerTrait;

    private const int MAXED_PRIORITY = -PHP_INT_MAX;

    public const string IMPORT_TYPE_CLASS = 'class';

    public const string IMPORT_TYPE_CONST = 'const';

    public const string IMPORT_TYPE_FUNCTION = 'function';

    public const string SORT_ALPHA = 'alpha';

    public const string SORT_LENGTH = 'length';

    public const string SORT_BY_NAMESPACE = 'namespace';

    public const string SORT_NONE = 'none';

    private const array SUPPORTED_SORT_TYPES = [self::IMPORT_TYPE_CLASS, self::IMPORT_TYPE_CONST, self::IMPORT_TYPE_FUNCTION];

    private const array SUPPORTED_SORT_ALGORITHMS = [self::SORT_ALPHA, self::SORT_LENGTH, self::SORT_NONE, self::SORT_BY_NAMESPACE];

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'Ordering `use` statements.',
            [
                new CodeSample(
                    "<?php\nuse function AAC;\nuse const AAB;\nuse AAA;\n"
                ),
                new CodeSample(
                    "<?php\nuse function Aaa;\nuse const AA;\n",
                    ['case_sensitive' => true]
                ),
                new CodeSample(
                    '<?php
use Acme\Bar;
use Bar1;
use Acme;
use Bar;
',
                    ['sort_algorithm' => self::SORT_LENGTH]
                ),
                new CodeSample(
                    '<?php
use const AAAA;
use const BBB;

use Bar;
use AAC;
use Acme;

use function CCC\AA;
use function DDD;
',
                    [
                        'sort_algorithm' => self::SORT_LENGTH,
                        'imports_order' => [
                            self::IMPORT_TYPE_CONST,
                            self::IMPORT_TYPE_CLASS,
                            self::IMPORT_TYPE_FUNCTION,
                        ],
                    ]
                ),
                new CodeSample(
                    '<?php
use const BBB;
use const AAAA;

use Acme;
use AAC;
use Bar;

use function DDD;
use function CCC\AA;
',
                    [
                        'sort_algorithm' => self::SORT_ALPHA,
                        'imports_order' => [
                            self::IMPORT_TYPE_CONST,
                            self::IMPORT_TYPE_CLASS,
                            self::IMPORT_TYPE_FUNCTION,
                        ],
                    ]
                ),
                new CodeSample(
                    '<?php
use const BBB;
use const AAAA;

use function DDD;
use function CCC\AA;

use Acme;
use AAC;
use Bar;
',
                    [
                        'sort_algorithm' => self::SORT_NONE,
                        'imports_order' => [
                            self::IMPORT_TYPE_CONST,
                            self::IMPORT_TYPE_CLASS,
                            self::IMPORT_TYPE_FUNCTION,
                        ],
                    ]
                ),
            ]
        );
    }

    public function getPriority(): int
    {
        return -31;
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isTokenKindFound(T_USE);
    }

    protected function applyFix(\SplFileInfo $file, Tokens $tokens): void
    {
        $tokensAnalyzer = new TokensAnalyzer($tokens);
        $namespacesImports = $tokensAnalyzer->getImportUseIndexes(true);

        foreach (array_reverse($namespacesImports) as $usesPerNamespaceIndices) {
            $count = \count($usesPerNamespaceIndices);

            if (0 === $count) {
                continue;
            }

            if (1 === $count) {
                $this->setNewOrder($tokens, $this->getNewOrder($usesPerNamespaceIndices, $tokens));
                $this->insertBlankLinesBetweenGroups($tokens, $this->getNewOrder($usesPerNamespaceIndices, $tokens));

                continue;
            }

            $groupUsesOffset = 0;
            $groupUses = [$groupUsesOffset => [$usesPerNamespaceIndices[0]]];

            for ($index = 0; $index < $count - 1; ++$index) {
                $nextGroupUse = $tokens->getNextTokenOfKind($usesPerNamespaceIndices[$index], [';', [T_CLOSE_TAG]]);

                if ($tokens[$nextGroupUse]->isGivenKind(T_CLOSE_TAG)) {
                    $nextGroupUse = $tokens->getNextTokenOfKind($usesPerNamespaceIndices[$index], [[T_OPEN_TAG]]);
                }

                $nextGroupUse = $tokens->getNextMeaningfulToken($nextGroupUse);

                if ($nextGroupUse !== $usesPerNamespaceIndices[$index + 1]) {
                    $groupUses[++$groupUsesOffset] = [];
                }

                $groupUses[$groupUsesOffset][] = $usesPerNamespaceIndices[$index + 1];
            }

            for ($index = $groupUsesOffset; $index >= 0; --$index) {
                $this->setNewOrder($tokens, $this->getNewOrder($groupUses[$index], $tokens));
                $this->insertBlankLinesBetweenGroups($tokens, $this->getNewOrder($groupUses[$index], $tokens));
            }
        }
    }

    protected function createConfigurationDefinition(): FixerConfigurationResolverInterface
    {
        $supportedSortTypes = self::SUPPORTED_SORT_TYPES;

        return new FixerConfigurationResolver([
            (new FixerOptionBuilder('sort_algorithm', 'Whether the statements should be sorted alphabetically or by length, or not sorted.'))
                ->setAllowedValues(self::SUPPORTED_SORT_ALGORITHMS)
                ->setDefault(self::SORT_ALPHA)
                ->getOption(),
            (new FixerOptionBuilder('imports_order', 'Defines the order of import types.'))
                ->setAllowedTypes(['string[]', 'null'])
                ->setAllowedValues([static function (?array $value) use ($supportedSortTypes): bool {
                    if (null !== $value) {
                        $missing = array_diff($supportedSortTypes, $value);
                        if (\count($missing) > 0) {
                            throw new InvalidOptionsException(\sprintf(
                                'Missing sort %s %s.',
                                1 === \count($missing) ? 'type' : 'types',
                                Utils::naturalLanguageJoin($missing)
                            ));
                        }

                        $unknown = array_diff($value, $supportedSortTypes);
                        if (\count($unknown) > 0) {
                            throw new InvalidOptionsException(\sprintf(
                                'Unknown sort %s %s.',
                                1 === \count($unknown) ? 'type' : 'types',
                                Utils::naturalLanguageJoin($unknown)
                            ));
                        }
                    }

                    return true;
                }])
                ->setDefault(null)
                ->getOption(),
            (new FixerOptionBuilder('case_sensitive', 'Whether the sorting should be case sensitive.'))
                ->setAllowedTypes(['bool'])
                ->setDefault(false)
                ->getOption(),
            (new FixerOptionBuilder('namespace_priority', 'Set namespace priority mapping for sorting imports.'))
                ->setAllowedTypes(['array', 'null'])
                ->setDefault(null)
                ->getOption(),
            (new FixerOptionBuilder('no_namespace_priority', 'Config priority mapping for sorting imports without full namespace.'))
                ->setAllowedTypes(['int', 'null'])
                ->setDefault(null)
                ->getOption(),
        ]);
    }

    private function sortAlphabetically(array $first, array $second): int
    {
        $firstNamespace = str_replace('\\', ' ', $this->prepareNamespace($first['namespace']));
        $secondNamespace = str_replace('\\', ' ', $this->prepareNamespace($second['namespace']));

        return true === $this->configuration['case_sensitive']
            ? $firstNamespace <=> $secondNamespace
            : strcasecmp($firstNamespace, $secondNamespace);
    }

    private function sortByLength(array $first, array $second): int
    {
        $firstNamespace = (self::IMPORT_TYPE_CLASS === $first['importType'] ? '' : $first['importType'] . ' ') . $this->prepareNamespace($first['namespace']);
        $secondNamespace = (self::IMPORT_TYPE_CLASS === $second['importType'] ? '' : $second['importType'] . ' ') . $this->prepareNamespace($second['namespace']);

        $firstNamespaceLength = \strlen($firstNamespace);
        $secondNamespaceLength = \strlen($secondNamespace);

        if ($firstNamespaceLength === $secondNamespaceLength) {
            $sortResult = true === $this->configuration['case_sensitive']
                ? $firstNamespace <=> $secondNamespace
                : strcasecmp($firstNamespace, $secondNamespace);
        } else {
            $sortResult = $firstNamespaceLength > $secondNamespaceLength ? 1 : -1;
        }

        return $sortResult;
    }

    private function sortByNamespaces(array $first, array $second): int
    {
        $namespacePriority = $this->configuration['namespace_priority'] ?? [];

        $firstNamespace = $this->prepareNamespace($first['namespace']);
        $secondNamespace = $this->prepareNamespace($second['namespace']);

        $firstPriority = $this->getNamespacePriority($firstNamespace, $namespacePriority);
        $secondPriority = $this->getNamespacePriority($secondNamespace, $namespacePriority);

        if ($firstPriority !== $secondPriority) {
            return $firstPriority <=> $secondPriority;
        }

        $firstNamespaceComparable = str_replace('\\', ' ', $firstNamespace);
        $secondNamespaceComparable = str_replace('\\', ' ', $secondNamespace);

        return true === $this->configuration['case_sensitive']
            ? $firstNamespaceComparable <=> $secondNamespaceComparable
            : strcasecmp($firstNamespaceComparable, $secondNamespaceComparable);
    }

    private function getNamespacePriority(string $namespace, array $priorityMap): int
    {
        $matchedPriority = 0;
        $matchedLength = 0;


        if (!str_contains($namespace, '\\')) {
            return $this->configuration['no_namespace_priority'] ?: self::MAXED_PRIORITY;
        }

        foreach ($priorityMap as $prefix => $priority) {
            if (strpos($namespace, $prefix) === 0) {
                $currentPrefixLength = strlen($prefix);
                if ($currentPrefixLength > $matchedLength) {
                    $matchedLength = $currentPrefixLength;
                    $matchedPriority = $priority;
                }
            }
        }

        return $matchedPriority;
    }

    private function prepareNamespace(string $namespace): string
    {
        return trim(Preg::replace('%/\*(.*)\*/%s', '', $namespace));
    }

    private function getNewOrder(array $uses, Tokens $tokens): array
    {
        $indices = [];
        $originalIndices = [];
        $lineEnding = $this->whitespacesConfig->getLineEnding();
        $usesCount = \count($uses);

        for ($i = 0; $i < $usesCount; ++$i) {
            $index = $uses[$i];

            $startIndex = $tokens->getTokenNotOfKindsSibling($index + 1, 1, [T_WHITESPACE]);
            $endIndex = $tokens->getNextTokenOfKind($startIndex, [';', [T_CLOSE_TAG]]);
            $previous = $tokens->getPrevMeaningfulToken($endIndex);

            $group = $tokens[$previous]->isGivenKind(CT::T_GROUP_IMPORT_BRACE_CLOSE);
            if ($tokens[$startIndex]->isGivenKind(CT::T_CONST_IMPORT)) {
                $type = self::IMPORT_TYPE_CONST;
                $index = $tokens->getNextNonWhitespace($startIndex);
            } elseif ($tokens[$startIndex]->isGivenKind(CT::T_FUNCTION_IMPORT)) {
                $type = self::IMPORT_TYPE_FUNCTION;
                $index = $tokens->getNextNonWhitespace($startIndex);
            } else {
                $type = self::IMPORT_TYPE_CLASS;
                $index = $startIndex;
            }

            $namespaceTokens = [];

            while ($index <= $endIndex) {
                $token = $tokens[$index];

                if ($index === $endIndex || (!$group && $token->equals(','))) {
                    if ($group && self::SORT_NONE !== $this->configuration['sort_algorithm']) {
                        $namespaceTokensCount = \count($namespaceTokens) - 1;
                        $namespace = '';
                        for ($k = 0; $k < $namespaceTokensCount; ++$k) {
                            if ($namespaceTokens[$k]->isGivenKind(CT::T_GROUP_IMPORT_BRACE_OPEN)) {
                                $namespace .= '{';

                                break;
                            }

                            $namespace .= $namespaceTokens[$k]->getContent();
                        }

                        $parts = [];
                        $firstIndent = '';
                        $separator = ', ';
                        $lastIndent = '';
                        $hasGroupTrailingComma = false;

                        for ($k1 = $k + 1; $k1 < $namespaceTokensCount; ++$k1) {
                            $comment = '';
                            $namespacePart = '';
                            for ($k2 = $k1;; ++$k2) {
                                if ($namespaceTokens[$k2]->equalsAny([',', [CT::T_GROUP_IMPORT_BRACE_CLOSE]])) {
                                    break;
                                }

                                if ($namespaceTokens[$k2]->isComment()) {
                                    $comment .= $namespaceTokens[$k2]->getContent();

                                    continue;
                                }

                                if (
                                    '' === $firstIndent
                                    && $namespaceTokens[$k2]->isWhitespace()
                                    && str_contains($namespaceTokens[$k2]->getContent(), $lineEnding)
                                ) {
                                    $lastIndent = $lineEnding;
                                    $firstIndent = $lineEnding . $this->whitespacesConfig->getIndent();
                                    $separator = ',' . $firstIndent;
                                }

                                $namespacePart .= $namespaceTokens[$k2]->getContent();
                            }

                            $namespacePart = trim($namespacePart);
                            if ('' === $namespacePart) {
                                $hasGroupTrailingComma = true;

                                continue;
                            }

                            $comment = trim($comment);
                            if ('' !== $comment) {
                                $namespacePart .= ' ' . $comment;
                            }

                            $parts[] = $namespacePart;

                            $k1 = $k2;
                        }

                        $sortedParts = $parts;
                        sort($parts);

                        if ($sortedParts === $parts) {
                            $namespace = Tokens::fromArray($namespaceTokens)->generateCode();
                        } else {
                            $namespace .= $firstIndent .
                                implode($separator, $parts) .
                                ($hasGroupTrailingComma ? ',' : '') .
                                $lastIndent . '}'
                            ;
                        }
                    } else {
                        $namespace = Tokens::fromArray($namespaceTokens)->generateCode();
                    }

                    $indices[$startIndex] = [
                        'namespace' => $namespace,
                        'startIndex' => $startIndex,
                        'endIndex' => $index - 1,
                        'importType' => $type,
                        'group' => $group,
                    ];

                    $originalIndices[] = $startIndex;

                    if ($index === $endIndex) {
                        break;
                    }

                    $namespaceTokens = [];
                    $nextPartIndex = $tokens->getTokenNotOfKindSibling($index, 1, [',', [T_WHITESPACE]]);
                    $startIndex = $nextPartIndex;
                    $index = $nextPartIndex;

                    continue;
                }

                $namespaceTokens[] = $token;
                ++$index;
            }
        }

        if (null !== $this->configuration['imports_order']) {
            $groupedByTypes = [];

            foreach ($indices as $startIndex => $item) {
                $groupedByTypes[$item['importType']][$startIndex] = $item;
            }

            foreach ($groupedByTypes as $type => $groupIndices) {
                $groupedByTypes[$type] = $this->sortByAlgorithm($groupIndices);
            }

            $sortedGroups = [];

            foreach ($this->configuration['imports_order'] as $type) {
                if (isset($groupedByTypes[$type]) && [] !== $groupedByTypes[$type]) {
                    foreach ($groupedByTypes[$type] as $startIndex => $item) {
                        $sortedGroups[$startIndex] = $item;
                    }
                }
            }

            $indices = $sortedGroups;
        } else {
            $indices = $this->sortByAlgorithm($indices);
        }

        $index = -1;
        $usesOrder = [];

        foreach ($indices as $v) {
            $usesOrder[$originalIndices[++$index]] = $v;
        }

        return $usesOrder;
    }

    private function sortByAlgorithm(array $indices): array
    {
        if (self::SORT_ALPHA === $this->configuration['sort_algorithm']) {
            uasort($indices, [$this, 'sortAlphabetically']);
        } elseif (self::SORT_LENGTH === $this->configuration['sort_algorithm']) {
            uasort($indices, [$this, 'sortByLength']);
        } elseif (self::SORT_BY_NAMESPACE === $this->configuration['sort_algorithm']) {
            uasort($indices, [$this, 'sortByNamespaces']);
        }

        return $indices;
    }

    private function setNewOrder(Tokens $tokens, array $usesOrder): void
    {
        $mapStartToEnd = [];
        foreach ($usesOrder as $use) {
            $mapStartToEnd[$use['startIndex']] = $use['endIndex'];
        }

        foreach (array_reverse($usesOrder, true) as $index => $use) {
            $code = \sprintf(
                '<?php use %s%s;',
                self::IMPORT_TYPE_CLASS === $use['importType'] ? '' : ' ' . $use['importType'] . ' ',
                $use['namespace']
            );

            $numberOfInitialTokensToClear = 3; // clear `<?php use `
            if (self::IMPORT_TYPE_CLASS !== $use['importType']) {
                $prevIndex = $tokens->getPrevMeaningfulToken($index);
                if ($tokens[$prevIndex]->equals(',')) {
                    $numberOfInitialTokensToClear = 5; // clear `<?php use const ` or `<?php use function `
                }
            }

            $declarationTokens = Tokens::fromCode($code);
            $declarationTokens->clearRange(0, $numberOfInitialTokensToClear - 1);
            $declarationTokens->clearAt(\count($declarationTokens) - 1); // clear `;`
            $declarationTokens->clearEmptyTokens();

            $tokens->overrideRange($index, $mapStartToEnd[$index], $declarationTokens);

            if ($use['group']) {
                $prev = $tokens->getPrevMeaningfulToken($index);
                if ($tokens[$prev]->equals(',')) {
                    $tokens[$prev] = new Token(';');
                    $tokens->insertAt($prev + 1, new Token([T_USE, 'use']));

                    if (!$tokens[$prev + 2]->isWhitespace()) {
                        $tokens->insertAt($prev + 2, new Token([T_WHITESPACE, ' ']));
                    }
                }
            }
        }
    }

    private function insertBlankLinesBetweenGroups(Tokens $tokens, array $usesOrder): void
    {
        $lineEnding = $this->whitespacesConfig->getLineEnding();
        $importIndices = [];

        for ($index = 0, $max = $tokens->count(); $index < $max; $index++) {
            if ($tokens[$index]->isGivenKind(T_USE)) {
                $importIndices[] = $index;
            }
        }

        $importsData = [];
        foreach ($importIndices as $i) {
            $namespace = '';
            $j = $tokens->getNextNonWhitespace($i);
            while ($j < $tokens->count() && !$tokens[$j]->equals(';')) {
                $namespace .= $tokens[$j]->getContent();
                $j++;
            }

            $namespace = trim(preg_replace('/^(const|function)\s+/i', '', $namespace));

            $parts = explode('\\', ltrim($namespace, '\\'));
            $groupName = $parts[0] ?? '';

            $importsData[] = [
                'index' => $i,
                'namespace' => $namespace,
                'group' => $groupName
            ];
        }

        $previousGroup = null;
        $insertPositions = [];

        foreach ($importsData as $data) {
            if (null !== $previousGroup && $previousGroup !== $data['group']) {
                $insertPositions[] = $data['index'];
            }
            $previousGroup = $data['group'];
        }

        for ($x = \count($insertPositions) - 1; $x >= 0; $x--) {
            $pos = $insertPositions[$x];
            $tokens->insertAt($pos, new Token([T_WHITESPACE, $lineEnding]));
        }
    }


    public function getName(): string
    {
        return 'CustomFixer/ordered_imports_group';
    }
}
