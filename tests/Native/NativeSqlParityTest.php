<?php

namespace Lorisleiva\LaravelSearchString\Tests\Native;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Lorisleiva\LaravelSearchString\Tests\Stubs\Product;
use PHPUnit\Framework\TestCase;

class NativeSqlParityTest extends TestCase
{
    public function strategies(): array
    {
        return [['exceptions'], ['no-results'], ['all-results']];
    }

    /** @dataProvider strategies */
    public function testSqlBindingsAndFailureStrategies(string $strategy): void
    {
        $originalContainer = Container::getInstance();
        $originalFacade = Facade::getFacadeApplication();
        $originalResolver = Model::getConnectionResolver();
        $originalReporting = error_reporting(E_ALL & ~E_DEPRECATED);
        set_error_handler(function ($severity, $message, $file, $line) {
            if (error_reporting() & $severity) {
                throw new \ErrorException($message, 0, $severity, $file, $line);
            }
            return true;
        });
        try {
            $container = new Container();
            Container::setInstance($container);
            $container->instance('config', new Repository(['search-string' => require __DIR__.'/../../src/config.php']));
            $container['config']->set('search-string.fail', $strategy);
            $db = new Manager($container);
            $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
            $db->bootEloquent();
            $container->instance('db', $db->getDatabaseManager());
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($container);
            foreach (json_decode(file_get_contents(__DIR__.'/fixtures/sql.old.json'), true) as $row) {
                if ($row['strategy'] !== $strategy) {
                    continue;
                }
                try {
                    $query = (new NativeParityProduct())->newQuery()->where('company_id', 17)->usingSearchString($row['input']);
                    $actual = ['sql' => $query->toSql(), 'bindings' => array_map(fn ($value) => $value instanceof \DateTimeInterface ? ['class' => get_class($value), 'value' => $value->format('Y-m-d H:i:s.uP'), 'timezone' => $value->getTimezone()->getName()] : $value, $query->getBindings())];
                } catch (\Throwable $exception) {
                    $actual = ['error' => get_class($exception), 'token' => method_exists($exception, 'getToken') ? $exception->getToken() : null];
                }
                $this->assertSame($row['result'], $actual, $row['input']);
            }
        } finally {
            restore_error_handler();
            error_reporting($originalReporting);
            Container::setInstance($originalContainer);
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($originalFacade);
            if ($originalResolver) {
                Model::setConnectionResolver($originalResolver);
            } else {
                Model::unsetConnectionResolver();
            }
        }
    }
}

class NativeParityProduct extends Product
{
    protected $table = 'products';

    public function getSearchStringOptions()
    {
        return array_replace_recursive(parent::getSearchStringOptions(), [
            'case_insensitive' => true,
            'columns' => ['name' => ['key' => '/^(name|title)$/i'], 'email' => ['searchable' => true]],
        ]);
    }
}
