<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://doc.hyperf.io
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf-cloud/hyperf/blob/master/LICENSE
 */

namespace Hyperf\Translation;

use Countable;
use Hyperf\Translation\Contract\Loader;
use Hyperf\Translation\Contract\Translator as TranslatorContract;
use Hyperf\Translation\Support\NamespacedItemResolver;
use Hyperf\Utils\Arr;
use Hyperf\Utils\Collection;
use Hyperf\Utils\Str;
use Hyperf\Utils\Traits\Macroable;

class Translator extends NamespacedItemResolver implements TranslatorContract
{
    use Macroable;

    /**
     * The loader implementation.
     *
     * @var \Hyperf\Translation\Contract\Loader
     */
    protected $loader;

    /**
     * The default locale being used by the translator.
     *
     * @var string
     */
    protected $locale;

    /**
     * The fallback locale used by the translator.
     *
     * @var string
     */
    protected $fallback;

    /**
     * The array of loaded translation groups.
     *
     * @var array
     */
    protected $loaded = [];

    /**
     * The message selector.
     *
     * @var \Hyperf\Translation\MessageSelector
     */
    protected $selector;

    /**
     * Create a new translator instance.
     *
     * @param \Hyperf\Translation\Contract\Loader $loader
     * @param string $locale
     */
    public function __construct(Loader $loader, string $locale)
    {
        $this->loader = $loader;
        $this->locale = $locale;
    }

    /**
     * Determine if a translation exists for a given locale.
     *
     * @param string $key
     * @param null|string $locale
     * @return bool
     */
    public function hasForLocale(string $key, $locale = null): bool
    {
        return $this->has($key, $locale, false);
    }

    /**
     * Determine if a translation exists.
     *
     * @param string $key
     * @param null|string $locale
     * @param bool $fallback
     * @return bool
     */
    public function has(string $key, $locale = null, bool $fallback = true): bool
    {
        return $this->get($key, [], $locale, $fallback) !== $key;
    }

    /**
     * Get the translation for a given key.
     *
     * @param string $key
     * @param array $replace
     * @param null|string $locale
     * @return array|string
     */
    public function trans(string $key, array $replace = [], $locale = null)
    {
        return $this->get($key, $replace, $locale);
    }

    /**
     * Get the translation for the given key.
     *
     * @param string $key
     * @param array $replace
     * @param null|string $locale
     * @param bool $fallback
     * @return array|string
     */
    public function get(string $key, array $replace = [], $locale = null, $fallback = true)
    {
        [$namespace, $group, $item] = $this->parseKey($key);

        // Here we will get the locale that should be used for the language line. If one
        // was not passed, we will use the default locales which was given to us when
        // the translator was instantiated. Then, we can load the lines and return.
        $locales = $fallback ? $this->localeArray($locale)
            : [$locale ?: $this->locale];

        foreach ($locales as $locale) {
            if (! is_null($line = $this->getLine(
                $namespace,
                $group,
                $locale,
                $item,
                $replace
            ))) {
                break;
            }
        }

        // If the line doesn't exist, we will return back the key which was requested as
        // that will be quick to spot in the UI if language keys are wrong or missing
        // from the application's language files. Otherwise we can return the line.
        return $line ?? $key;
    }

    /**
     * Get the translation for a given key from the JSON translation files.
     *
     * @param string $key
     * @param array $replace
     * @param null|string $locale
     * @return array|string
     */
    public function getFromJson(string $key, array $replace = [], $locale = null)
    {
        $locale = $locale ?: $this->locale;

        // For JSON translations, there is only one file per locale, so we will simply load
        // that file and then we will be ready to check the array for the key. These are
        // only one level deep so we do not need to do any fancy searching through it.
        $this->load('*', '*', $locale);

        $line = $this->loaded['*']['*'][$locale][$key] ?? null;

        // If we can't find a translation for the JSON key, we will attempt to translate it
        // using the typical translation file. This way developers can always just use a
        // helper such as __ instead of having to pick between trans or __ with views.
        if (! isset($line)) {
            $fallback = $this->get($key, $replace, $locale);

            if ($fallback !== $key) {
                return $fallback;
            }
        }

        return $this->makeReplacements($line ?: $key, $replace);
    }

    /**
     * Get a translation according to an integer value.
     *
     * @param string $key
     * @param array|\Countable|int $number
     * @param array $replace
     * @param null|string $locale
     * @return string
     */
    public function transChoice(string $key, $number, array $replace = [], $locale = null): string
    {
        return $this->choice($key, $number, $replace, $locale);
    }

    /**
     * Get a translation according to an integer value.
     *
     * @param string $key
     * @param array|\Countable|int $number
     * @param array $replace
     * @param null|string $locale
     * @return string
     */
    public function choice(string $key, $number, array $replace = [], $locale = null): string
    {
        $line = $this->get(
            $key,
            $replace,
            $locale = $this->localeForChoice($locale)
        );

        // If the given "number" is actually an array or countable we will simply count the
        // number of elements in an instance. This allows developers to pass an array of
        // items without having to count it on their end first which gives bad syntax.
        if (is_array($number) || $number instanceof Countable) {
            $number = count($number);
        }

        $replace['count'] = $number;

        return $this->makeReplacements(
            $this->getSelector()->choose($line, $number, $locale),
            $replace
        );
    }

    /**
     * Add translation lines to the given locale.
     *
     * @param array $lines
     * @param string $locale
     * @param string $namespace
     */
    public function addLines(array $lines, string $locale, string $namespace = '*')
    {
        foreach ($lines as $key => $value) {
            [$group, $item] = explode('.', $key, 2);

            Arr::set($this->loaded, "{$namespace}.{$group}.{$locale}.{$item}", $value);
        }
    }

    /**
     * Load the specified language group.
     *
     * @param string $namespace
     * @param string $group
     * @param string $locale
     */
    public function load(string $namespace, string $group, string $locale)
    {
        if ($this->isLoaded($namespace, $group, $locale)) {
            return;
        }

        // The loader is responsible for returning the array of language lines for the
        // given namespace, group, and locale. We'll set the lines in this array of
        // lines that have already been loaded so that we can easily access them.
        $lines = $this->loader->load($locale, $group, $namespace);

        $this->loaded[$namespace][$group][$locale] = $lines;
    }

    /**
     * Add a new namespace to the loader.
     *
     * @param string $namespace
     * @param string $hint
     */
    public function addNamespace(string $namespace, string $hint)
    {
        $this->loader->addNamespace($namespace, $hint);
    }

    /**
     * Add a new JSON path to the loader.
     *
     * @param string $path
     */
    public function addJsonPath(string $path)
    {
        $this->loader->addJsonPath($path);
    }

    /**
     * Parse a key into namespace, group, and item.
     *
     * @param string $key
     * @return array
     */
    public function parseKey(string $key): array
    {
        $segments = parent::parseKey($key);

        if (is_null($segments[0])) {
            $segments[0] = '*';
        }

        return $segments;
    }

    /**
     * Get the message selector instance.
     *
     * @return \Hyperf\Translation\MessageSelector
     */
    public function getSelector()
    {
        if (! isset($this->selector)) {
            $this->selector = new MessageSelector();
        }

        return $this->selector;
    }

    /**
     * Set the message selector instance.
     *
     * @param \Hyperf\Translation\MessageSelector $selector
     */
    public function setSelector(MessageSelector $selector)
    {
        $this->selector = $selector;
    }

    /**
     * Get the language line loader implementation.
     *
     * @return \Hyperf\Translation\Contract\Loader
     */
    public function getLoader()
    {
        return $this->loader;
    }

    /**
     * Get the default locale being used.
     *
     * @return string
     */
    public function locale(): string
    {
        return $this->getLocale();
    }

    /**
     * Get the default locale being used.
     *
     * @return string
     */
    public function getLocale(): string
    {
        return $this->locale;
    }

    /**
     * Set the default locale.
     *
     * @param string $locale
     */
    public function setLocale(string $locale)
    {
        $this->locale = $locale;
    }

    /**
     * Get the fallback locale being used.
     *
     * @return string
     */
    public function getFallback(): string
    {
        return $this->fallback;
    }

    /**
     * Set the fallback locale being used.
     *
     * @param string $fallback
     */
    public function setFallback(string $fallback)
    {
        $this->fallback = $fallback;
    }

    /**
     * Set the loaded translation groups.
     *
     * @param array $loaded
     */
    public function setLoaded(array $loaded)
    {
        $this->loaded = $loaded;
    }

    /**
     * Get the proper locale for a choice operation.
     *
     * @param null|string $locale
     * @return string
     */
    protected function localeForChoice($locale): string
    {
        return $locale ?: $this->locale ?: $this->fallback;
    }

    /**
     * Retrieve a language line out the loaded array.
     *
     * @param string $namespace
     * @param string $group
     * @param string $locale
     * @param mixed $item
     * @param array $replace
     * @return null|array|string
     */
    protected function getLine(string $namespace, string $group, string $locale, $item, array $replace)
    {
        $this->load($namespace, $group, $locale);
        if (! is_null($item)) {
            $line = Arr::get($this->loaded[$namespace][$group][$locale], $item);
        } else {
            // do for hyperf Arr::get
            $line = $this->loaded[$namespace][$group][$locale];
        }

        if (is_string($line)) {
            return $this->makeReplacements($line, $replace);
        }
        if (is_array($line) && count($line) > 0) {
            foreach ($line as $key => $value) {
                $line[$key] = $this->makeReplacements($value, $replace);
            }

            return $line;
        }
    }

    /**
     * Make the place-holder replacements on a line.
     *
     * @param string $line
     * @param array $replace
     * @return string
     */
    protected function makeReplacements($line, array $replace)
    {
        if (empty($replace)) {
            return $line;
        }

        $replace = $this->sortReplacements($replace);

        foreach ($replace as $key => $value) {
            $key = (string) $key;
            $value = (string) $value;
            $line = str_replace(
                [':' . $key, ':' . Str::upper($key), ':' . Str::ucfirst($key)],
                [$value, Str::upper($value), Str::ucfirst($value)],
                $line
            );
        }

        return $line;
    }

    /**
     * Sort the replacements array.
     *
     * @param array $replace
     * @return array
     */
    protected function sortReplacements(array $replace): array
    {
        return (new Collection($replace))->sortBy(function ($value, $key) {
            return mb_strlen((string) $key) * -1;
        })->all();
    }

    /**
     * Determine if the given group has been loaded.
     *
     * @param string $namespace
     * @param string $group
     * @param string $locale
     * @return bool
     */
    protected function isLoaded(string $namespace, string $group, string $locale)
    {
        return isset($this->loaded[$namespace][$group][$locale]);
    }

    /**
     * Get the array of locales to be checked.
     *
     * @param null|string $locale
     * @return array
     */
    protected function localeArray($locale): array
    {
        return array_filter([$locale ?: $this->locale, $this->fallback]);
    }
}
