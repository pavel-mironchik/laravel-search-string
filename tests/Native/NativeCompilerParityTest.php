<?php

namespace Lorisleiva\LaravelSearchString\Tests\Native;

use Illuminate\Support\Collection;
use Lorisleiva\LaravelSearchString\Compiler\NativeCompiler;
use Lorisleiva\LaravelSearchString\Exceptions\InvalidSearchStringException;
use Lorisleiva\LaravelSearchString\SearchStringManager;
use PHPUnit\Framework\TestCase;

class NativeCompilerParityTest extends TestCase
{
    public static function corpus(): array
    {
        return array_map(fn ($path) => [$path], glob(__DIR__.'/fixtures/*-0.old.json'));
    }

    /** @dataProvider corpus */
    #[\PHPUnit\Framework\Attributes\DataProvider('corpus')]
    public function testBoundedLegacyCorpus(string $path): void
    {
        $this->assertCorpus($path);
    }

    public function testRemainingUpstreamParserCases(): void
    {
        $this->assertCorpus(__DIR__.'/fixtures/parser-success-1.old.json');
    }

    private function assertCorpus(string $path): void
    {
        $manager = (new \ReflectionClass(SearchStringManager::class))->newInstanceWithoutConstructor();
        $this->assertInstanceOf(NativeCompiler::class, $manager->getCompiler());
        $this->assertSame(realpath(__DIR__.'/../../src/Compiler/NativeCompiler.php'), (new \ReflectionClass($manager->getCompiler()))->getFileName());
        $rows = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertLessThanOrEqual(50, count($rows));
        foreach ($rows as $expected) {
            $actual = ['input' => $expected['input'], 'tokens' => $manager->lex($expected['input'])->all()];
            try {
                $actual['ast'] = $this->shape($manager->parse($expected['input']));
            } catch (InvalidSearchStringException $exception) {
                $actual['error'] = ['class' => get_class($exception), 'token' => $exception->getToken(), 'step' => $exception->getStep()];
            }
            $this->assertSame($expected, $actual, json_encode($expected['input']));
        }
    }

    private function shape($value)
    {
        if ($value instanceof Collection) {
            return array_map([$this, 'shape'], $value->all());
        }
        if (is_object($value)) {
            return ['class' => get_class($value), 'properties' => array_map([$this, 'shape'], get_object_vars($value))];
        }
        return is_array($value) ? array_map([$this, 'shape'], $value) : $value;
    }
}
