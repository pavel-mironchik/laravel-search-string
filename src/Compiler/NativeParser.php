<?php

namespace Lorisleiva\LaravelSearchString\Compiler;

use Lorisleiva\LaravelSearchString\AST;
use Lorisleiva\LaravelSearchString\Exceptions\InvalidSearchStringException;

/** @internal Recursive descent for Grammar.pp. */
class NativeParser
{
    private array $tokens;
    private int $position = 0;
    private ?int $firstAlternative = null;

    public function __construct(array $tokens)
    {
        $this->tokens = $tokens;
    }

    public function parse(): AST\Symbol
    {
        try {
            $result = $this->expression();
            $this->expect('EOF');
            return $result;
        } catch (InvalidSearchStringException $exception) {
            // The legacy grammar exhausts alternatives back to the earliest solo term.
            // Preserve its public error-token contract without exponential backtracking.
            if ($this->firstAlternative !== null) {
                $this->position = $this->firstAlternative;
                $this->fail();
            }
            throw $exception;
        }
    }

    private function type(): string
    {
        return $this->tokens[$this->position]['token'];
    }

    private function take(): string
    {
        return $this->tokens[$this->position++]['value'];
    }

    private function expect(string $type): void
    {
        if ($this->type() !== $type) {
            $this->fail();
        }
        $this->position++;
    }

    private function fail(): void
    {
        throw InvalidSearchStringException::fromLexer('Unexpected token '.$this->type(), $this->type());
    }

    private function expression(): AST\Symbol
    {
        $nodes = [$this->conjunction()];
        while ($this->type() === 'T_OR') {
            $this->take();
            $nodes[] = $this->conjunction();
        }
        return count($nodes) === 1 ? $nodes[0] : new AST\OrSymbol($nodes);
    }

    private function conjunction(): AST\Symbol
    {
        $nodes = [$this->terminal()];
        while (! in_array($this->type(), ['T_OR', 'T_RPARENTHESIS', 'EOF'], true)) {
            if ($this->type() === 'T_AND') {
                $this->take();
            }
            $nodes[] = $this->terminal();
        }
        return count($nodes) === 1 ? $nodes[0] : new AST\AndSymbol($nodes);
    }

    private function terminal(): AST\Symbol
    {
        if ($this->type() === 'T_NOT') {
            $this->take();
            return new AST\NotSymbol($this->terminal());
        }
        if ($this->type() === 'T_LPARENTHESIS') {
            $this->take();
            $node = $this->expression();
            $this->expect('T_RPARENTHESIS');
            return $node;
        }
        if ($this->type() !== 'T_TERM') {
            return new AST\SoloSymbol($this->scalar() ?? '');
        }

        $key = $this->take();
        $afterKey = $this->position;
        if ($this->firstAlternative === null && in_array($this->type(), ['T_DOT', 'T_ASSIGNMENT', 'T_COMPARATOR', 'T_IN'], true)) {
            $this->firstAlternative = $afterKey;
        }
        try {
            $terms = [$key];
            while ($this->type() === 'T_DOT') {
                $this->take();
                if ($this->type() !== 'T_TERM') {
                    $this->fail();
                }
                $terms[] = $this->take();
            }
            if ($this->type() === 'T_ASSIGNMENT' && $this->tokens[$this->position + 1]['token'] === 'T_LPARENTHESIS') {
                $this->position += 2;
                $node = $this->expression();
                $this->expect('T_RPARENTHESIS');
                $operator = '>';
                $count = 0;
                $afterGroup = $this->position;
                if ($this->isOperator()) {
                    $operator = $this->operator();
                    if ($this->type() === 'T_INTEGER') {
                        $count = (int) $this->take();
                    } else {
                        $this->position = $afterGroup;
                        $operator = '>';
                    }
                }
                while (count($terms) > 1) {
                    $node = new AST\RelationshipSymbol(array_pop($terms), $node);
                }
                return new AST\RelationshipSymbol($terms[0], $node, $operator, $count);
            }
            if (count($terms) > 1) {
                $last = array_pop($terms);
                $afterTerms = $this->position;
                $node = new AST\SoloSymbol($last);
                if ($this->isOperator()) {
                    try {
                        $operator = $this->operator();
                        $node = new AST\QuerySymbol($last, $operator, $this->scalar(true));
                    } catch (InvalidSearchStringException $e) {
                        $this->position = $afterTerms;
                    }
                }
                while ($terms) {
                    $node = new AST\RelationshipSymbol(array_pop($terms), $node);
                }
                return $node;
            }
            if ($this->type() === 'T_IN') {
                $this->take();
                $this->expect('T_LPARENTHESIS');
                $values = $this->scalarList();
                $this->expect('T_RPARENTHESIS');
                return new AST\ListSymbol($key, $values);
            }
            if ($this->isOperator()) {
                $assignment = $this->type() === 'T_ASSIGNMENT';
                $operator = $this->operator();
                $null = $this->type() === 'T_NULL';
                $value = $this->scalar(true);
                if ($assignment && ! $null && $this->type() === 'T_COMMA') {
                    $values = $value === null ? [] : [$value];
                    while ($this->type() === 'T_COMMA') {
                        $this->take();
                        $next = $this->scalar();
                        if ($next !== null) {
                            $values[] = $next;
                        }
                    }
                    return new AST\ListSymbol($key, $values);
                }
                return new AST\QuerySymbol($key, $operator, $null ? null : ($value ?? ''));
            }
        } catch (InvalidSearchStringException $e) {
            // Grammar alternatives fall back to a solo term; preserve the offending token.
            $this->position = $afterKey;
        }
        return new AST\SoloSymbol($key);
    }

    private function scalar(bool $nullable = false): ?string
    {
        if ($nullable && $this->type() === 'T_NULL') {
            $this->take();
            return null;
        }
        if (in_array($this->type(), ['T_TERM', 'T_INTEGER', 'T_DECIMAL'], true)) {
            return $this->take();
        }
        if (in_array($this->type(), ['T_SINGLE_LQUOTE', 'T_DOUBLE_LQUOTE'], true)) {
            $end = $this->type() === 'T_SINGLE_LQUOTE' ? 'T_SINGLE_RQUOTE' : 'T_DOUBLE_RQUOTE';
            $this->take();
            $value = $this->type() === 'T_STRING' ? $this->take() : null;
            $this->expect($end);
            return $value;
        }
        $this->fail();
    }

    private function scalarList(): array
    {
        $values = [];
        do {
            $value = $this->scalar();
            if ($value !== null) {
                $values[] = $value;
            }
            if ($this->type() !== 'T_COMMA') {
                break;
            }
            $this->take();
        } while (true);
        return $values;
    }

    private function isOperator(): bool
    {
        return in_array($this->type(), ['T_ASSIGNMENT', 'T_COMPARATOR'], true);
    }

    private function operator(): string
    {
        $assignment = $this->type() === 'T_ASSIGNMENT';
        $value = $this->take();
        return $assignment ? '=' : $value;
    }
}
