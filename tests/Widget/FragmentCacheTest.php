<?php

namespace Yiisoft\Widget\Tests;

use Yiisoft\View\Theme;
use Yiisoft\View\View;

/**
 * @group widgets
 * @group caching
 */
class FragmentCacheTest extends \yii\tests\TestCase
{
    protected function setUp()
    {
        parent::setUp();
        $this->mockWebApplication();
        $this->container->set('cache', new Cache([
            '__class' => Cache::class,
            'handler' => new ArrayCache(),
        ]));
    }

    public function testCacheEnabled()
    {
        $expectedLevel = ob_get_level();
        ob_start();
        ob_implicit_flush(false);

        $view = $this->createView();
        $this->assertTrue($view->beginCache('test'));
        echo 'cached fragment';
        $view->endCache();

        ob_start();
        ob_implicit_flush(false);
        $this->assertFalse($view->beginCache('test'));
        $this->assertEquals('cached fragment', ob_get_clean());

        ob_end_clean();
        $this->assertEquals($expectedLevel, ob_get_level(), 'Output buffer not closed correctly.');
    }

    public function testCacheDisabled1()
    {
        $expectedLevel = ob_get_level();
        ob_start();
        ob_implicit_flush(false);

        $view = $this->createView();
        $this->assertTrue($view->beginCache('test', ['enabled' => false]));
        echo 'cached fragment';
        $view->endCache();

        ob_start();
        ob_implicit_flush(false);
        $this->assertTrue($view->beginCache('test', ['enabled' => false]));
        echo 'cached fragment';
        $view->endCache();
        $this->assertEquals('cached fragment', ob_get_clean());

        ob_end_clean();
        $this->assertEquals($expectedLevel, ob_get_level(), 'Output buffer not closed correctly.');
    }

    public function testCacheDisabled2()
    {
        $expectedLevel = ob_get_level();
        ob_start();
        ob_implicit_flush(false);

        $view = $this->createView();
        $this->assertTrue($view->beginCache('test'));
        echo 'cached fragment';
        $view->endCache();

        ob_start();
        ob_implicit_flush(false);
        $this->assertTrue($view->beginCache('test', ['enabled' => false]));
        echo 'cached fragment other';
        $view->endCache();
        $this->assertEquals('cached fragment other', ob_get_clean());

        ob_end_clean();
        $this->assertEquals($expectedLevel, ob_get_level(), 'Output buffer not closed correctly.');
    }

    public function testSingleDynamicFragment()
    {
        $this->app->params['counter'] = 0;

        $view = $this->createView();

        for ($counter = 0; $counter < 42; $counter++) {
            ob_start();
            ob_implicit_flush(false);

            $cacheUnavailable = $view->beginCache('test');

            if ($counter === 0) {
                $this->assertTrue($cacheUnavailable);
            } else {
                $this->assertFalse($cacheUnavailable);
            }

            if ($cacheUnavailable) {
                echo 'single dynamic cached fragment: ';
                echo $view->renderDynamic('return $this->app->params["counter"]++;');
                $view->endCache();
            }

            $expectedContent = vsprintf('single dynamic cached fragment: %d', [
                $counter,
            ]);
            $this->assertEquals($expectedContent, ob_get_clean());
        }
    }

    public function testMultipleDynamicFragments()
    {
        $this->app->params['counter'] = 0;

        $view = $this->createView();

        for ($counter = 0; $counter < 42; $counter++) {
            ob_start();
            ob_implicit_flush(false);

            $cacheUnavailable = $view->beginCache('test');

            if ($counter === 0) {
                $this->assertTrue($cacheUnavailable);
            } else {
                $this->assertFalse($cacheUnavailable);
            }

            if ($cacheUnavailable) {
                echo 'multiple dynamic cached fragments: ';
                echo $view->renderDynamic('return md5($this->app->params["counter"]);');
                echo $view->renderDynamic('return $this->app->params["counter"]++;');
                $view->endCache();
            }

            $expectedContent = vsprintf('multiple dynamic cached fragments: %s%d', [
                md5($counter),
                $counter,
            ]);
            $this->assertEquals($expectedContent, ob_get_clean());
        }
    }

    public function testNestedDynamicFragments()
    {
        $this->app->params['counter'] = 0;

        $view = $this->createView();

        for ($counter = 0; $counter < 42; $counter++) {
            ob_start();
            ob_implicit_flush(false);

            $cacheUnavailable = $view->beginCache('test');

            if ($counter === 0) {
                $this->assertTrue($cacheUnavailable);
            } else {
                $this->assertFalse($cacheUnavailable);
            }

            if ($cacheUnavailable) {
                echo 'nested dynamic cached fragments: ';
                echo $view->renderDynamic('return md5($this->app->params["counter"]);');

                if ($view->beginCache('test-nested')) {
                    echo $view->renderDynamic('return sha1($this->app->params["counter"]);');
                    $view->endCache();
                }

                echo $view->renderDynamic('return $this->app->params["counter"]++;');
                $view->endCache();
            }

            $expectedContent = vsprintf('nested dynamic cached fragments: %s%s%d', [
                md5($counter),
                sha1($counter),
                $counter,
            ]);
            $this->assertEquals($expectedContent, ob_get_clean());
        }
    }

    public function testVariations()
    {
        $this->setOutputCallback(function ($output) {
        });

        ob_start();
        ob_implicit_flush(false);
        $view = $this->createView();
        $this->assertTrue($view->beginCache('test', ['variations' => ['ru']]), 'Cached fragment should not be exist');
        echo 'cached fragment';
        $view->endCache();

        $cached = ob_get_clean();
        $this->assertEquals('cached fragment', $cached);

        ob_start();
        ob_implicit_flush(false);
        $this->assertFalse($view->beginCache('test', ['variations' => ['ru']]), 'Cached fragment should be exist');

        $cachedEn = ob_get_clean();
        $this->assertEquals($cached, $cachedEn);

        $this->assertTrue($view->beginCache('test', ['variations' => ['en']]), 'Cached fragment should not be exist');
        echo 'cached fragment';
        $view->endCache();
        $this->assertFalse($view->beginCache('test', ['variations' => ['en']]), 'Cached fragment should be exist');

        //without variations
        ob_start();
        ob_implicit_flush(false);
        $view = $this->createView();
        $this->assertTrue($view->beginCache('test'), 'Cached fragment should not be exist');
        echo 'cached fragment';
        $view->endCache();
        $cached = ob_get_clean();
        $this->assertEquals('cached fragment', $cached);

        //with variations as a string
        ob_start();
        ob_implicit_flush(false);
        $this->assertTrue($view->beginCache('test', ['variations' => 'uz']), 'Cached fragment should not be exist');
        echo 'cached fragment';
        $view->endCache();
        $cached = ob_get_clean();
        $this->assertEquals('cached fragment', $cached);
        $this->assertFalse($view->beginCache('test', ['variations' => 'uz']), 'Cached fragment should be exist');
    }

    protected function createView(Theme $theme = null)
    {
        return new View($this->app, $theme ?: new Theme());
    }

    // TODO test dynamic replacements
}
