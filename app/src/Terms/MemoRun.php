<?php

declare(strict_types=1);

namespace App\Terms;

/**
 * What one TermsAnalyzer::analyze call got from the model, kept apart from the memo's own errors.
 *
 * @internal
 */
final class MemoRun
{
    /** @var array{model: string, categories: array<string, list<string>>, quotes_dropped: int}|null */
    public ?array $answer = null;

    public ?\Throwable $failure = null;
}
