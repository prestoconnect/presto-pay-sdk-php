<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/src', __DIR__ . '/tests']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        // Not @PSR12: this codebase deliberately keeps short methods and
        // empty class/method bodies on one line, which PSR-12's brace
        // rules would expand. These rules are the subset that matches the
        // style already in use, not a reformat pass.
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => true,
        'trailing_comma_in_multiline' => true,
        'no_superfluous_phpdoc_tags' => ['allow_mixed' => true],
        'no_trailing_whitespace' => true,
        'no_whitespace_in_blank_line' => true,
        'blank_line_after_namespace' => true,
        'single_blank_line_at_eof' => true,
        'array_syntax' => ['syntax' => 'short'],
        'binary_operator_spaces' => ['default' => 'single_space'],
        'concat_space' => ['spacing' => 'one'],
    ])
    ->setFinder($finder);
