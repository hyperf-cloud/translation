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

namespace Hyperf\Translation\Contracts;

interface Loader
{
    /**
     * Load the messages for the given locale.
     *
     * @param string $locale
     * @param string $group
     * @param null|string $namespace
     * @return array
     */
    public function load(string $locale, string $group, $namespace = null): array;

    /**
     * Add a new namespace to the loader.
     *
     * @param string $namespace
     * @param string $hint
     */
    public function addNamespace(string $namespace, string $hint);

    /**
     * Add a new JSON path to the loader.
     *
     * @param string $path
     */
    public function addJsonPath(string $path);

    /**
     * Get an array of all the registered namespaces.
     *
     * @return array
     */
    public function namespaces(): array;
}
