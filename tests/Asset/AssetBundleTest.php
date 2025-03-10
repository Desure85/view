<?php

namespace Yiisoft\Asset\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use yii\helpers\FileHelper;
use yii\helpers\Yii;
use Yiisoft\Asset\AssetBundle;
use Yiisoft\Asset\AssetManager;
use Yiisoft\EventDispatcher\Dispatcher;
use Yiisoft\EventDispatcher\Provider\Provider;
use Yiisoft\View\View;

/**
 * @group web
 */
class AssetBundleTest extends TestCase
{
    /**
     * @var string path for the test files.
     */
    private $testViewPath = '';

    private $eventDispatcher;
    private $eventProvider;

    protected function setUp()
    {
        $this->testViewPath = sys_get_temp_dir() . '/'. str_replace('\\', '_', get_class($this)) . uniqid('', false);
        FileHelper::createDirectory($this->testViewPath);

        $this->eventProvider = new Provider();
        $this->eventDispatcher = new Dispatcher($this->eventProvider);

        $this->app->setAlias('@public', '@yii/tests/data/web');
        $this->app->setAlias('@testAssetsPath', '@public/assets');
        $this->app->setAlias('@testAssetsUrl', '@web/assets');
        $this->app->setAlias('@testSourcePath', '@public/assetSources');

        // clean up assets directory
        $handle = opendir($dir = $this->app->getAlias('@testAssetsPath'));
        if ($handle === false) {
            throw new \Exception("Unable to open directory: $dir");
        }
        while (($file = readdir($handle)) !== false) {
            if ($file === '.' || $file === '..' || $file === '.gitignore') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$file;
            if (is_dir($path)) {
                FileHelper::removeDirectory($path);
            } else {
                FileHelper::unlink($path);
            }
        }
        closedir($handle);
    }

    /**
     * Returns View with configured AssetManager.
     *
     * @param array $amConfig may be used to override default AssetManager config
     *
     * @return View
     */
    protected function getView(array $amConfig = [])
    {
        new View($this->testViewPath, $theme, $this->eventDispatcher, new NullLogger());

        return $this->factory->create([
            '__class'      => View::class,
            'assetManager' => $this->factory->create(array_merge([
                '__class'  => AssetManager::class,
                'basePath' => '@testAssetsPath',
                'baseUrl'  => '@testAssetsUrl',
            ], $amConfig)),
        ]);
    }

    public function testSourcesPublish()
    {
        $view = $this->getView();
        $am = $view->assetManager;

        $bundle = TestSourceAsset::register($view);
        $bundle->publish($am);

        $this->assertTrue(is_dir($bundle->basePath));
        $this->sourcesPublish_VerifyFiles('css', $bundle);
        $this->sourcesPublish_VerifyFiles('js', $bundle);
    }

    private function sourcesPublish_VerifyFiles($type, $bundle)
    {
        foreach ($bundle->$type as $filename) {
            $publishedFile = $bundle->basePath.DIRECTORY_SEPARATOR.$filename;
            $sourceFile = $bundle->sourcePath.DIRECTORY_SEPARATOR.$filename;
            $this->assertFileExists($publishedFile);
            $this->assertFileEquals($publishedFile, $sourceFile);
        }
        $this->assertTrue(is_dir($bundle->basePath.DIRECTORY_SEPARATOR.$type));
    }

    public function testSourcesPublishedBySymlink()
    {
        $view = $this->getView(['linkAssets' => true]);
        $this->verifySourcesPublishedBySymlink($view);
    }

    public function testSourcesPublishedBySymlink_Issue9333()
    {
        $view = $this->getView([
            'linkAssets'   => true,
            'hashCallback' => function ($path) {
                return sprintf('%x/%x', crc32($path), crc32(Yii::getVersion()));
            },
        ]);
        $bundle = $this->verifySourcesPublishedBySymlink($view);
        $this->assertTrue(is_dir(dirname($bundle->basePath)));
    }

    public function testSourcesPublish_AssetManagerBeforeCopy()
    {
        $view = $this->getView([
            'beforeCopy' => function ($from, $to) {
                return false;
            },
        ]);
        $am = $view->assetManager;

        $bundle = TestSourceAsset::register($view);
        $bundle->publish($am);

        $this->assertFalse(is_dir($bundle->basePath));
        foreach ($bundle->js as $filename) {
            $publishedFile = $bundle->basePath.DIRECTORY_SEPARATOR.$filename;
            $this->assertFileNotExists($publishedFile);
        }
    }

    public function testSourcesPublish_AssetBeforeCopy()
    {
        $view = $this->getView();
        $am = $view->assetManager;

        $bundle = new TestSourceAsset();
        $bundle->publishOptions = [
            'beforeCopy' => function ($from, $to) {
                return false;
            },
        ];
        $bundle->publish($am);

        $this->assertFalse(is_dir($bundle->basePath));
        foreach ($bundle->js as $filename) {
            $publishedFile = $bundle->basePath.DIRECTORY_SEPARATOR.$filename;
            $this->assertFileNotExists($publishedFile);
        }
    }

    public function testSourcesPublish_publishOptions_Only()
    {
        $view = $this->getView();
        $am = $view->assetManager;

        $bundle = new TestSourceAsset();
        $bundle->publishOptions = [
            'only' => [
                'js/*',
            ],
        ];
        $bundle->publish($am);

        $notNeededFilesDir = dirname($bundle->basePath.DIRECTORY_SEPARATOR.$bundle->css[0]);
        $this->assertFileNotExists($notNeededFilesDir);

        foreach ($bundle->js as $filename) {
            $publishedFile = $bundle->basePath.DIRECTORY_SEPARATOR.$filename;
            $this->assertFileExists($publishedFile);
        }
        $this->assertTrue(is_dir(dirname($bundle->basePath.DIRECTORY_SEPARATOR.$bundle->js[0])));
        $this->assertTrue(is_dir($bundle->basePath));
    }

    /**
     * @param View $view
     *
     * @return AssetBundle
     */
    protected function verifySourcesPublishedBySymlink($view)
    {
        $am = $view->assetManager;

        $bundle = TestSourceAsset::register($view);
        $bundle->publish($am);

        $this->assertTrue(is_dir($bundle->basePath));
        foreach ($bundle->js as $filename) {
            $publishedFile = $bundle->basePath.DIRECTORY_SEPARATOR.$filename;
            $sourceFile = $bundle->basePath.DIRECTORY_SEPARATOR.$filename;

            $this->assertTrue(is_link($bundle->basePath));
            $this->assertFileExists($publishedFile);
            $this->assertFileEquals($publishedFile, $sourceFile);
        }

        $this->assertTrue(FileHelper::unlink($bundle->basePath));

        return $bundle;
    }

    /**
     * Properly removes symlinked directory under Windows, MacOS and Linux.
     *
     * @param string $file path to symlink
     *
     * @return bool
     */
    protected function unlink($file)
    {
        if (is_dir($file) && DIRECTORY_SEPARATOR === '\\') {
            return rmdir($file);
        }

        return unlink($file);
    }

    public function testRegister()
    {
        $view = $this->getView();

        $this->assertEmpty($view->assetBundles);
        TestSimpleAsset::register($view);
        $this->assertCount(1, $view->assetBundles);
        $this->assertArrayHasKey('yii\\web\\tests\\TestSimpleAsset', $view->assetBundles);
        $this->assertInstanceOf(AssetBundle::class, $view->assetBundles['yii\\web\\tests\\TestSimpleAsset']);

        $expected = <<<'EOF'
123<script src="/js/jquery.js"></script>4
EOF;
        $this->assertEquals($expected, $view->renderFile('@yii/tests/data/views/rawlayout.php'));
    }

    public function testSimpleDependency()
    {
        $view = $this->getView();

        $this->assertEmpty($view->assetBundles);
        TestAssetBundle::register($view);
        $this->assertCount(3, $view->assetBundles);
        $this->assertArrayHasKey(TestAssetBundle::class, $view->assetBundles);
        $this->assertArrayHasKey(TestJqueryAsset::class, $view->assetBundles);
        $this->assertArrayHasKey(TestAssetLevel3::class, $view->assetBundles);
        $this->assertInstanceOf(AssetBundle::class, $view->assetBundles[TestAssetBundle::class]);
        $this->assertInstanceOf(AssetBundle::class, $view->assetBundles[TestJqueryAsset::class]);
        $this->assertInstanceOf(AssetBundle::class, $view->assetBundles[TestAssetLevel3::class]);

        $expected = <<<'EOF'
1<link href="/files/cssFile.css" rel="stylesheet">23<script src="/js/jquery.js"></script>
<script src="/files/jsFile.js"></script>4
EOF;
        $this->assertEqualsWithoutLE($expected, $view->renderFile('@yii/tests/data/views/rawlayout.php'));
    }

    public function positionProvider()
    {
        return [
            [View::POS_HEAD, true],
            [View::POS_HEAD, false],
            [View::POS_BEGIN, true],
            [View::POS_BEGIN, false],
            [View::POS_END, true],
            [View::POS_END, false],
        ];
    }

    /**
     * @dataProvider positionProvider
     *
     * @param int  $pos
     * @param bool $jqAlreadyRegistered
     */
    public function testPositionDependency($pos, $jqAlreadyRegistered)
    {
        $view = $this->getView();

        $view->getAssetManager()->bundles['yii\\web\\tests\\TestAssetBundle'] = [
            'jsOptions' => [
                'position' => $pos,
            ],
        ];

        $this->assertEmpty($view->assetBundles);
        if ($jqAlreadyRegistered) {
            TestJqueryAsset::register($view);
        }
        TestAssetBundle::register($view);
        $this->assertCount(3, $view->assetBundles);
        $this->assertArrayHasKey('yii\\web\\tests\\TestAssetBundle', $view->assetBundles);
        $this->assertArrayHasKey('yii\\web\\tests\\TestJqueryAsset', $view->assetBundles);
        $this->assertArrayHasKey('yii\\web\\tests\\TestAssetLevel3', $view->assetBundles);

        $this->assertInstanceOf(AssetBundle::class, $view->assetBundles['yii\\web\\tests\\TestAssetBundle']);
        $this->assertInstanceOf(AssetBundle::class, $view->assetBundles['yii\\web\\tests\\TestJqueryAsset']);
        $this->assertInstanceOf(AssetBundle::class, $view->assetBundles['yii\\web\\tests\\TestAssetLevel3']);

        $this->assertArrayHasKey('position', $view->assetBundles['yii\\web\\tests\\TestAssetBundle']->jsOptions);
        $this->assertEquals($pos, $view->assetBundles['yii\\web\\tests\\TestAssetBundle']->jsOptions['position']);
        $this->assertArrayHasKey('position', $view->assetBundles['yii\\web\\tests\\TestJqueryAsset']->jsOptions);
        $this->assertEquals($pos, $view->assetBundles['yii\\web\\tests\\TestJqueryAsset']->jsOptions['position']);
        $this->assertArrayHasKey('position', $view->assetBundles['yii\\web\\tests\\TestAssetLevel3']->jsOptions);
        $this->assertEquals($pos, $view->assetBundles['yii\\web\\tests\\TestAssetLevel3']->jsOptions['position']);

        switch ($pos) {
            case View::POS_HEAD:
                $expected = <<<'EOF'
1<link href="/files/cssFile.css" rel="stylesheet">
<script src="/js/jquery.js"></script>
<script src="/files/jsFile.js"></script>234
EOF;
            break;
            case View::POS_BEGIN:
                $expected = <<<'EOF'
1<link href="/files/cssFile.css" rel="stylesheet">2<script src="/js/jquery.js"></script>
<script src="/files/jsFile.js"></script>34
EOF;
            break;
            default:
            case View::POS_END:
                $expected = <<<'EOF'
1<link href="/files/cssFile.css" rel="stylesheet">23<script src="/js/jquery.js"></script>
<script src="/files/jsFile.js"></script>4
EOF;
            break;
        }
        $this->assertEqualsWithoutLE($expected, $view->renderFile('@yii/tests/data/views/rawlayout.php'));
    }

    public function positionProvider2()
    {
        return [
            [View::POS_BEGIN, true],
            [View::POS_BEGIN, false],
            [View::POS_END, true],
            [View::POS_END, false],
        ];
    }

    /**
     * @dataProvider positionProvider
     *
     * @param int  $pos
     * @param bool $jqAlreadyRegistered
     */
    public function testPositionDependencyConflict($pos, $jqAlreadyRegistered)
    {
        $view = $this->getView();

        $view->getAssetManager()->bundles['yii\\web\\tests\\TestAssetBundle'] = [
            'jsOptions' => [
                'position' => $pos - 1,
            ],
        ];
        $view->getAssetManager()->bundles['yii\\web\\tests\\TestJqueryAsset'] = [
            'jsOptions' => [
                'position' => $pos,
            ],
        ];

        $this->assertEmpty($view->assetBundles);
        if ($jqAlreadyRegistered) {
            TestJqueryAsset::register($view);
        }
        $this->expectException('yii\\exceptions\\InvalidConfigException');
        TestAssetBundle::register($view);
    }

    public function testCircularDependency()
    {
        $this->expectException('yii\\exceptions\\InvalidConfigException');
        TestAssetCircleA::register($this->getView());
    }

    public function testDuplicateAssetFile()
    {
        $view = $this->getView();

        $this->assertEmpty($view->assetBundles);
        TestSimpleAsset::register($view);
        $this->assertCount(1, $view->assetBundles);
        $this->assertArrayHasKey('yii\\web\\tests\\TestSimpleAsset', $view->assetBundles);
        $this->assertInstanceOf(AssetBundle::class, $view->assetBundles['yii\\web\\tests\\TestSimpleAsset']);
        // register TestJqueryAsset which also has the jquery.js
        TestJqueryAsset::register($view);

        $expected = <<<'EOF'
123<script src="/js/jquery.js"></script>4
EOF;
        $this->assertEquals($expected, $view->renderFile('@yii/tests/data/views/rawlayout.php'));
    }

    public function testPerFileOptions()
    {
        $view = $this->getView();

        $this->assertEmpty($view->assetBundles);
        TestAssetPerFileOptions::register($view);

        $expected = <<<'EOF'
1<link href="/default_options.css" rel="stylesheet" media="screen" hreflang="en">
<link href="/tv.css" rel="stylesheet" media="tv" hreflang="en">
<link href="/screen_and_print.css" rel="stylesheet" media="screen, print" hreflang="en">23<script src="/normal.js" charset="utf-8"></script>
<script src="/defered.js" charset="utf-8" defer></script>4
EOF;
        $this->assertEqualsWithoutLE($expected, $view->renderFile('@yii/tests/data/views/rawlayout.php'));
    }

    public function registerFileDataProvider()
    {
        return [
            // JS files registration
            [
                'js', '@web/assetSources/js/missing-file.js', true,
                '123<script src="/assetSources/js/missing-file.js"></script>4',
            ],
            [
                'js', '@web/assetSources/js/jquery.js', false,
                '123<script src="/assetSources/js/jquery.js"></script>4',
            ],
            [
                'js', 'http://example.com/assetSources/js/jquery.js', false,
                '123<script src="http://example.com/assetSources/js/jquery.js"></script>4',
            ],
            [
                'js', '//example.com/assetSources/js/jquery.js', false,
                '123<script src="//example.com/assetSources/js/jquery.js"></script>4',
            ],
            [
                'js', 'assetSources/js/jquery.js', false,
                '123<script src="assetSources/js/jquery.js"></script>4',
            ],
            [
                'js', '/assetSources/js/jquery.js', false,
                '123<script src="/assetSources/js/jquery.js"></script>4',
            ],

            // CSS file registration
            [
                'css', '@web/assetSources/css/missing-file.css', true,
                '1<link href="/assetSources/css/missing-file.css" rel="stylesheet">234',
            ],
            [
                'css', '@web/assetSources/css/stub.css', false,
                '1<link href="/assetSources/css/stub.css" rel="stylesheet">234',
            ],
            [
                'css', 'http://example.com/assetSources/css/stub.css', false,
                '1<link href="http://example.com/assetSources/css/stub.css" rel="stylesheet">234',
            ],
            [
                'css', '//example.com/assetSources/css/stub.css', false,
                '1<link href="//example.com/assetSources/css/stub.css" rel="stylesheet">234',
            ],
            [
                'css', 'assetSources/css/stub.css', false,
                '1<link href="assetSources/css/stub.css" rel="stylesheet">234',
            ],
            [
                'css', '/assetSources/css/stub.css', false,
                '1<link href="/assetSources/css/stub.css" rel="stylesheet">234',
            ],

            // Custom `@web` aliases
            [
                'js', '@web/assetSources/js/missing-file1.js', true,
                '123<script src="/backend/assetSources/js/missing-file1.js"></script>4',
                '/backend',
            ],
            [
                'js', 'http://full-url.example.com/backend/assetSources/js/missing-file.js', true,
                '123<script src="http://full-url.example.com/backend/assetSources/js/missing-file.js"></script>4',
                '/backend',
            ],
            [
                'css', '//backend/backend/assetSources/js/missing-file.js', true,
                '1<link href="//backend/backend/assetSources/js/missing-file.js" rel="stylesheet">234',
                '/backend',
            ],
            [
                'css', '@web/assetSources/css/stub.css', false,
                '1<link href="/en/blog/backend/assetSources/css/stub.css" rel="stylesheet">234',
                '/en/blog/backend',
            ],

            // UTF-8 chars
            [
                'css', '@web/assetSources/css/stub.css', false,
                '1<link href="/рус/сайт/assetSources/css/stub.css" rel="stylesheet">234',
                '/рус/сайт',
            ],
            [
                'js', '@web/assetSources/js/jquery.js', false,
                '123<script src="/汉语/漢語/assetSources/js/jquery.js"></script>4',
                '/汉语/漢語',
            ],

            // Custom alias repeats in the asset URL
            [
                'css', '@web/assetSources/repeat/css/stub.css', false,
                '1<link href="/repeat/assetSources/repeat/css/stub.css" rel="stylesheet">234',
                '/repeat',
            ],
            [
                'js', '@web/assetSources/repeat/js/jquery.js', false,
                '123<script src="/repeat/assetSources/repeat/js/jquery.js"></script>4',
                '/repeat',
            ],
        ];
    }

    /**
     * @dataProvider registerFileDataProvider
     *
     * @param string      $type            either `js` or `css`
     * @param string      $path
     * @param string|bool $appendTimestamp
     * @param string      $expected
     * @param string|null $webAlias
     */
    public function testRegisterFileAppendTimestamp($type, $path, $appendTimestamp, $expected, $webAlias = null)
    {
        $originalAlias = $this->app->getAlias('@web');
        if ($webAlias === null) {
            $webAlias = $originalAlias;
        }
        $this->app->setAlias('@web', $webAlias);

        $view = $this->getView(['appendTimestamp' => $appendTimestamp]);
        $method = 'register'.ucfirst($type).'File';
        $view->$method($path);
        $this->assertEquals($expected, $view->renderFile('@yii/tests/data/views/rawlayout.php'));

        $this->app->setAlias('@web', $originalAlias);
    }
}

class TestSimpleAsset extends AssetBundle
{
    public $basePath = '@public/js';
    public $baseUrl = '@web/js';
    public $js = [
        'jquery.js',
    ];
}

class TestSourceAsset extends AssetBundle
{
    public $sourcePath = '@testSourcePath';
    public $js = [
        'js/jquery.js',
    ];
    public $css = [
        'css/stub.css',
    ];
}

class TestAssetBundle extends AssetBundle
{
    public $basePath = '@public/files';
    public $baseUrl = '@web/files';
    public $css = [
        'cssFile.css',
    ];
    public $js = [
        'jsFile.js',
    ];
    public $depends = [
        TestJqueryAsset::class,
    ];
}

class TestJqueryAsset extends AssetBundle
{
    public $basePath = '@public/js';
    public $baseUrl = '@web/js';
    public $js = [
        'jquery.js',
    ];
    public $depends = [
        TestAssetLevel3::class,
    ];
}

class TestAssetLevel3 extends AssetBundle
{
    public $basePath = '@public/js';
    public $baseUrl = '@web/js';
}

class TestAssetCircleA extends AssetBundle
{
    public $basePath = '@public/js';
    public $baseUrl = '@web/js';
    public $js = [
        'jquery.js',
    ];
    public $depends = [
        TestAssetCircleB::class,
    ];
}

class TestAssetCircleB extends AssetBundle
{
    public $basePath = '@public/js';
    public $baseUrl = '@web/js';
    public $js = [
        'jquery.js',
    ];
    public $depends = [
        TestAssetCircleA::class,
    ];
}

class TestAssetPerFileOptions extends AssetBundle
{
    public $basePath = '@public';
    public $baseUrl = '@web';
    public $css = [
        'default_options.css',
        ['tv.css', 'media' => 'tv'],
        ['screen_and_print.css', 'media' => 'screen, print'],
    ];
    public $js = [
        'normal.js',
        ['defered.js', 'defer' => true],
    ];
    public $cssOptions = ['media' => 'screen', 'hreflang' => 'en'];
    public $jsOptions = ['charset' => 'utf-8'];
}
