<?php

namespace Oro\Bundle\ApiBundle\Request;

/**
 * API version related utilities.
 */
final class Version
{
    /** a string that can be used to reference the latest API version */
    public const LATEST = 'latest';

    /**
     * Normalizes a given API version.
     * If the given version is NULL, the "latest" version will be returned.
     * If the given version number contains meaningless prefix, e.g. "v", it will be removed.
     *
     * @param string|null $version
     *
     * @return string
     */
    public static function normalizeVersion(?string $version): string
    {
        if (null === $version) {
            $version = self::LATEST;
        } elseif (0 === \strpos($version, 'v')) {
            $version = \substr($version, 1);
        }

        return $version;
    }
}
