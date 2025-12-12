<?php

namespace Tests;

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Laravel\Dusk\Browser; // ★ この行を追加
use Laravel\Dusk\TestCase as BaseTestCase;
use Closure; // ★ この行を追加 (Closure型ヒント用)
use Throwable; // ★ この行を追加 (Throwable例外用)

abstract class DuskTestCase extends BaseTestCase
{
    use CreatesApplication;

    // Add this method to handle failures
    // 引数の型ヒントを `callable` から `Closure` に変更
    public function browse(Closure $callback)
    {
        parent::browse(function (Browser $browser) use ($callback) {
            try {
                $callback($browser);
            } catch (Throwable $e) { // ここもThrowableに
                // Save screenshot and console log on error
                if ($browser->driver) { // ★ driverが存在するかチェックを追加 (安全のため)
                    $browser->dump();
                    $browser->screenshot('failure-' . date('Y-m-d-His'));
                    $browser->storeConsoleLog('failure-' . date('Y-m-d-His'));
                }
                throw $e;
            }
        });
    }

    /**
     * Prepare for Dusk test execution.
     *
     * @beforeClass
     * @return void
     */
    public static function prepare()
    {
        if (! static::runningInSail() && ! getenv('DUSK_DRIVER_URL')) {
            static::startChromeDriver();
        }
    }

    /**
     * Create the RemoteWebDriver instance.
     *
     * @return \Facebook\WebDriver\Remote\RemoteWebDriver
     */
    protected function driver()
    {
        $options = (new ChromeOptions)->addArguments(collect([
            $this->shouldStartMaximized() ? '--start-maximized' : '--window-size=1920,1080',
        ])->unless($this->hasHeadlessDisabled(), function ($items) {
            return $items->merge([
                '--disable-gpu',
                '--headless', // Temporarily commented out for debugging
                '--no-sandbox',
            ]);
        })->all());

        return RemoteWebDriver::create(
            $_ENV['DUSK_DRIVER_URL'] ?? 'http://selenium:4444/wd/hub',
            DesiredCapabilities::chrome()->setCapability(
                ChromeOptions::CAPABILITY,
                $options
            )
        );
    }

    /**
     * Determine whether the Dusk command has disabled headless mode.
     *
     * @return bool
     */
    protected function hasHeadlessDisabled()
    {
        return isset($_SERVER['DUSK_HEADLESS_DISABLED']) ||
            isset($_ENV['DUSK_HEADLESS_DISABLED']);
    }

    /**
     * Determine if the browser window should start maximized.
     *
     * @return bool
     */
    protected function shouldStartMaximized()
    {
        return isset($_SERVER['DUSK_START_MAXIMIZED']) ||
            isset($_ENV['DUSK_START_MAXIMIZED']);
    }
}
