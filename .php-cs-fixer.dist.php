<?php

return (new PhpCsFixer\Config())
    // lambda_not_used_import removed Screening's $guard once (473f02f): it is kept alive on purpose.
    ->setRules(['@Symfony' => true, 'lambda_not_used_import' => false])
    ->setFinder(PhpCsFixer\Finder::create()->in([__DIR__.'/app/src', __DIR__.'/app/tests']));
