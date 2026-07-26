<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\Configuration;

use Vtinnovations\GuardianTypo3\Application\Contract\RuntimeConfigurationRepositoryInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\WorkingDirectoryProviderInterface;
use Vtinnovations\GuardianTypo3\Domain\Configuration\RuntimeConfiguration;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;

/**
 * Stores the runtime configuration as JSON at <var>/guardian/runtime.json.
 *
 * Reads always yield a valid, normalised {@see RuntimeConfiguration} (missing or
 * corrupt files degrade to the safe default). Writes go through a temp-file +
 * rename to be atomic and are validated by the value object before hitting disk.
 * In Phase 1 no wired UI calls {@see self::save()} — it exists for the guarded
 * settings write path of a later phase.
 */
final class JsonRuntimeConfigurationRepository implements RuntimeConfigurationRepositoryInterface
{
    private const FILENAME = 'runtime.json';

    public function __construct(
        private readonly WorkingDirectoryProviderInterface $workingDirectory,
    ) {
    }

    public function load(): RuntimeConfiguration
    {
        $file = $this->workingDirectory->resolve(self::FILENAME);
        if (!is_file($file)) {
            return RuntimeConfiguration::createDefault();
        }

        $raw = @file_get_contents($file);
        if ($raw === false) {
            return RuntimeConfiguration::createDefault();
        }

        $data = json_decode($raw, true);

        return \is_array($data)
            ? RuntimeConfiguration::fromArray($data)
            : RuntimeConfiguration::createDefault();
    }

    public function save(RuntimeConfiguration $configuration): RuntimeConfiguration
    {
        $file = $this->workingDirectory->resolve(self::FILENAME);
        $dir = \dirname($file);

        if (!is_dir($dir) && !@mkdir($dir, 0o750, true) && !is_dir($dir)) {
            throw new GuardianException('Could not create Guardian working directory: ' . $dir);
        }

        $json = json_encode($configuration->toArray(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new GuardianException('Could not encode runtime configuration to JSON.');
        }

        $tmp = $file . '.tmp';
        if (@file_put_contents($tmp, $json, \LOCK_EX) === false || !@rename($tmp, $file)) {
            @unlink($tmp);
            throw new GuardianException('Could not write runtime configuration to: ' . $file);
        }

        @chmod($file, 0o640);

        return $configuration;
    }
}
