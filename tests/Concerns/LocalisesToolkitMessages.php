<?php

declare(strict_types = 1);

namespace Tests\Concerns;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Lang;

/**
 * Switches the application to a locale holding the given toolkit messages.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait LocalisesToolkitMessages
{
    /**
     * Register the validation lines for the locale and make it current.
     *
     * @param  array<string, string>  $lines
     * @param  string  $locale
     * @return void
     */
    protected function localiseToolkitMessages(array $lines, string $locale = 'de'): void
    {
        $prefixed = [];

        foreach ($lines as $key => $line) {
            $prefixed['validation.' . $key] = $line;
        }

        Lang::addLines($prefixed, $locale, 'api-toolkit');
        App::setLocale($locale);
    }

    /**
     * Point the application at a lang path whose vendor override holds the
     * given lines of the group, as a published override would.
     *
     * @param  array<array-key, mixed>  $lines
     * @param  string  $locale
     * @param  string  $group
     * @return void
     */
    protected function overrideToolkitMessages(array $lines, string $locale = 'de', string $group = 'validation'): void
    {
        $app       = App::getFacadeRoot();
        $path      = sys_get_temp_dir() . '/api-toolkit-lang-' . getmypid() . '-' . uniqid('', true);
        $directory = $path . '/vendor/api-toolkit/' . $locale;

        assert($app instanceof Application);

        mkdir($directory, 0o755, true);
        file_put_contents(sprintf('%s/%s.json', $directory, $group), json_encode($lines, JSON_THROW_ON_ERROR));
        file_put_contents(sprintf('%s/%s.php', $directory, $group), sprintf('<?php return json_decode(file_get_contents(__DIR__ . \'/%s.json\'), true);', $group));

        $this->beforeApplicationDestroyed(static function () use ($path): void {
            (new Filesystem)->deleteDirectory($path);
        });

        $app->useLangPath($path);
        $app->forgetInstance('translation.loader');
        $app->forgetInstance('translator');

        Lang::clearResolvedInstance('translator');
    }
}
