# Maintained native compiler

This fork starts at libre-accounting/laravel-search-string
`c66de9bc9b31953b5800a607a4e3ed3d8b08a18f` (v1.4.0).
The original MIT license and authorship are retained in LICENSE.md.
No Hoa implementation, namespace shim, or source code has been copied into the
native implementation. Grammar.pp remains the language reference; AST classes,
options, visitors, model trait, service provider and command APIs are unchanged.

The intended release is 1.5.0 (initial candidate 1.5.0-rc.1): a maintained compiler
implementation is an additive maintenance capability, requiring a minor release.
Composer does not embed a version or alias. PHP ^8.1 and Illuminate 10/11 are the
bounded supported dependency ranges. The original Loris package replacement
continues to describe the preserved public API, not a replacement for Hoa.

The lexer preserves ordered token matching, Unicode character lengths and byte
offsets. Quotes do not acquire new escape semantics. Empty quoted list members
retain the original omission behavior. A falsey input, including "0", retains
EmptySymbol behavior. Parser failures retain InvalidSearchStringException,
getStep() == "Lexer" and token-name getToken(), including the grammar's fallback
to the earliest ambiguous solo-term alternative. Diagnostic prose changes;
exception messages are no longer Hoa-formatted source excerpts.
No arbitrary complexity limits or new query syntax are introduced.

## Regression oracle

The LexerTest.php and ParserTest.php corpora are byte-identical to original
Loris v1.3.0 `3842079d0fe6315a3a24dbc30c15ed590c635357`.
Fixtures in tests/Native/fixtures were captured independently from the existing
v1.4.0 Hoa implementation, in separate PHP processes. They preserve exact token
arrays and full public AST properties (including scalar types, nulls, collection
nesting, and node class names); no AST flattening or namespace substitution is
performed. SQL fixtures retain SQL, binding scalar types and DateTime class,
microseconds, offset and timezone. The 181 parser/lexer inputs include the
upstream corpus and bounded edge/backtracking cases. The 24 SQL inputs each run
under all three failure strategies and retain a pre-existing company predicate.
No production application or database is bootstrapped.

Use phpunit-native.xml for the standalone corpus. Normally its bootstrap uses
vendor/autoload.php; SEARCH_STRING_TEST_AUTOLOAD can select dependencies from an
existing installation. A prepended package loader and Reflection assertion
prevent Composer's optimized class map from accidentally loading the old package.
For bounded runs, first use --list-tests, then run each data set separately:

```sh
vendor/bin/phpunit -c phpunit-native.xml --list-tests
vendor/bin/phpunit -c phpunit-native.xml --filter 'testBoundedLegacyCorpus#0'
# Repeat #1 through #4 (18/49/31/25/40 inputs respectively across #0..#4).
vendor/bin/phpunit -c phpunit-native.xml --filter testRemainingUpstreamParserCases
vendor/bin/phpunit -c phpunit-native.xml --filter 'testSqlBindingsAndFailureStrategies#0'
# Repeat #1 and #2; each covers 24 inputs.
```

Each invocation should have a 900-second hard timeout. These fixtures require no
Hoa runtime or development dependency. They are bounded regression evidence,
not an exhaustive proof of all possible grammar inputs. Release gates remain
independent review, a normal Composer install/audit on the target dependency
graph, and the consuming application's actual custom-visitor regression suite.
