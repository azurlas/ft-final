<?php
$finder = PhpCsFixer\Finder::create()->in([__DIR__.'/src', __DIR__.'/tests', __DIR__.'/migrations']);
return (new PhpCsFixer\Config())
    ->setRules(['@PSR12'=>true, 'array_syntax'=>['syntax'=>'short']])
    ->setFinder($finder);
