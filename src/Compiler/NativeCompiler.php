<?php

namespace Lorisleiva\LaravelSearchString\Compiler;

use Illuminate\Support\Enumerable;
use Illuminate\Support\LazyCollection;
use Lorisleiva\LaravelSearchString\AST;
use Lorisleiva\LaravelSearchString\Exceptions\InvalidSearchStringException;
use Lorisleiva\LaravelSearchString\SearchStringManager;

/** Native implementation of Grammar.pp; token order and boundaries are intentional. */
class NativeCompiler implements CompilerInterface
{
    public function __construct(SearchStringManager $manager)
    {
    }

    public function lex(?string $input): Enumerable
    {
        return LazyCollection::make(function () use ($input) {
            $input = $input ?? '';
            $offset = 0;
            $namespace = 'default';
            $patterns = [
                'T_ASSIGNMENT' => ':|=',
                'T_COMPARATOR' => '>=?|<=?',
                'T_AND' => '(and|AND)(?![^\\(\\)\\s])',
                'T_OR' => '(or|OR)(?![^\\(\\)\\s])',
                'T_NOT' => '(not|NOT)(?![^\\(\\)\\s])',
                'T_IN' => '(in|IN)(?![^\\(\\)\\s])',
                'T_NULL' => 'NULL(?![^\\(\\)\\s])',
                'T_SINGLE_LQUOTE' => "'",
                'T_DOUBLE_LQUOTE' => '"',
                'T_LPARENTHESIS' => '\\(',
                'T_RPARENTHESIS' => '\\)',
                'T_DOT' => '\\.',
                'T_COMMA' => ',',
                'T_INTEGER' => '(\\d+)(?![^\\(\\)\\s])',
                'T_DECIMAL' => '(\\d+\\.\\d+)(?![^\\(\\)\\s])',
                'T_TERM' => '[^\\s:><="\'\\(\\)\\.,]+',
            ];
            while ($offset < strlen($input)) {
                if ($namespace === 'default' && preg_match('/\G\s+/u', $input, $match, 0, $offset)) {
                    $offset += strlen($match[0]);
                    continue;
                }
                $active = $patterns;
                if ($namespace === 'single_quote_string') {
                    $active = ['T_STRING' => "[^']+", 'T_SINGLE_RQUOTE' => "'"];
                } elseif ($namespace === 'double_quote_string') {
                    $active = ['T_STRING' => '[^"]+', 'T_DOUBLE_RQUOTE' => '"'];
                }
                $found = false;
                foreach ($active as $type => $pattern) {
                    if (! preg_match('/\G(?:'.$pattern.')/u', $input, $match, 0, $offset)) {
                        continue;
                    }
                    $value = $match[0];
                    yield ['token' => $type, 'value' => $value, 'length' => mb_strlen($value), 'namespace' => $namespace, 'keep' => true, 'offset' => $offset];
                    $offset += strlen($value);
                    if ($type === 'T_SINGLE_LQUOTE') {
                        $namespace = 'single_quote_string';
                    } elseif ($type === 'T_DOUBLE_LQUOTE') {
                        $namespace = 'double_quote_string';
                    } elseif (in_array($type, ['T_SINGLE_RQUOTE', 'T_DOUBLE_RQUOTE'], true)) {
                        $namespace = 'default';
                    }
                    $found = true;
                    break;
                }
                if (! $found) {
                    throw InvalidSearchStringException::fromLexer('Unrecognized search string token at byte '.$offset, substr($input, $offset, 1));
                }
            }
            yield ['token' => 'EOF', 'value' => 'EOF', 'length' => 0, 'namespace' => 'default', 'keep' => true, 'offset' => $offset];
        });
    }

    public function parse(?string $input): AST\Symbol
    {
        if (! $input) {
            return new AST\EmptySymbol();
        }

        // Each parse owns its cursor, so a compiler can be reused after failure.
        return (new NativeParser($this->lex($input)->all()))->parse();
    }
}
