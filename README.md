# OrderedImportsGroupFixer (Modified Version)

This fixer is a modified version of `OrderedImportsGroupFixer` for PHP CS Fixer. It not only orders imports according to specified rules but also allows you to insert blank lines between import groups and set namespace priorities.

## Features

- **Sorting imports** by:
    - Alphabetical order (`alpha`)
    - Length (`length`)
    - None (`none`)
    - With predefined namespace priorities (`namespace_priority`)

- **Grouping imports** by type:
    - Classes (`class`)
    - Constants (`const`)
    - Functions (`function`)

- **Inserting blank lines between import groups**:
    - Groups are determined by the first segment of the namespace.
      For example, imports from `Doctrine\...` form the "Doctrine" group, while `Monolog\...` form the "Monolog" group, and a blank line will be inserted between these groups.

## Requirements

- PHP CS Fixer
- PHP 8.3 or higher

## Installation

1. Add the modified `OrderedImportsGroupFixer` class to your project (e.g., in the `CustomFixer` directory):

   ```bash
   mkdir -p src/CustomFixer
   ```

**copy OrderedImportsGroupFixer.php to src/CustomFixer/ (or another dir)**
(do not forget to configure yor composer autoload if you need it)


## Examples

**Config files**
```php
<?php

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

use KepCustomFixer\CustomFixer\OrderedImportsGroupFixer;

$finder = Finder::create()
    ->in(__DIR__ . '/src');

return (new Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'single_blank_line_before_namespace' => true,
        'OrderedImportsGroupFixer' => [
            'sort_algorithm' => 'alpha',
            'imports_order' => ['const', 'class', 'function'],
            'case_sensitive' => false,
            'namespace_priority' => [
                'Doctrine\\' => -3,
                'Monolog\\' => -4,
                'DI\\' => 1
            ],
        ],
    ])
    ->registerCustomFixers([new OrderedImportsGroupFixer()])
    ->setFinder($finder);
```

**Input**
```php
use Doctrine\DBAL\Types\Type;
use DI\ContainerBuilder;
use Doctrine\ORM\EntityManager;
use Monolog\Logger;
```

**Output**

```php
use DI\ContainerBuilder;

use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;

use Monolog\Logger;
```


