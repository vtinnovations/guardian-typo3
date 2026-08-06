<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Application\Environment;

use Psr\Container\ContainerInterface;

/**
 * The V-T.ONE products installed here, in the order they should be shown.
 *
 * Registration is a plain entry in TYPO3's extension configuration array, written
 * by each extension's `ext_localconf.php`. That is deliberate: an extension can
 * register without depending on this one, several extensions can register without
 * any of them owning the shared screen, and installing or removing one changes
 * nothing for the others.
 *
 *     $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['vtone']['packages']['<slug>'] = [
 *         'title'    => '<Product>',
 *         'provider' => <ProviderClass>::class,
 *     ];
 *
 * A slug can only be registered once, so two products cannot collide, and a
 * product that registers an unusable provider is skipped rather than being allowed
 * to break the screen for everything else. Products are listed in slug order so
 * the screen looks the same on every installation.
 *
 * {@see sections()} turns that registry into what the screen actually renders, and
 * it is where the contract a product must satisfy is enforced. A section is shown
 * with working controls only when the product supplies all four of them — read the
 * current state, activate, refresh, remove. A product that supplies three, or
 * whose provider throws while being asked, gets a section carrying a plain
 * sentence and no controls at all. Rendering the two or three that did resolve
 * would be the worse outcome: an administrator would see Remove Licence, press it,
 * and have no way to tell that nothing happened.
 */
final class PackageDirectory
{
    /** Where the registry lives inside TYPO3's configuration array. */
    private const REGISTRY = ['EXTCONF', 'vtone', 'packages'];

    /**
     * The operations a product must be able to carry out before its controls are
     * offered. They correspond one to one with the buttons on the screen plus the
     * state behind them.
     *
     * @var list<string>
     */
    private const REQUIRED_ACTIONS = ['status', 'activate', 'refresh', 'clear'];

    public function __construct(
        private readonly ContainerInterface $container,
    ) {
    }

    /**
     * Every registered product's provider, keyed by slug.
     *
     * @return array<string, object>
     */
    public function providers(): array
    {
        $registry = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        foreach (self::REGISTRY as $level) {
            if (!\is_array($registry) || !\is_array($registry[$level] ?? null)) {
                return [];
            }
            $registry = $registry[$level];
        }

        $providers = [];
        foreach ($registry as $slug => $entry) {
            if (!\is_string($slug) || $slug === '' || !\is_array($entry)) {
                continue;
            }
            $identifier = $entry['provider'] ?? null;
            if (!\is_string($identifier) || $identifier === '' || !$this->container->has($identifier)) {
                continue;
            }
            $provider = $this->container->get($identifier);
            if (\is_object($provider) && $this->isUsable($provider)) {
                $providers[$slug] = $provider;
            }
        }

        ksort($providers, \SORT_STRING);

        return $providers;
    }

    /**
     * Every registered product as the screen should render it, in slug order.
     *
     * `complete` is the whole of the contract check: true means all four
     * operations resolved and the state was readable, so the controls may be
     * offered; false means the section renders its name and a sentence saying the
     * controls are unavailable, and nothing that can be pressed.
     *
     * A provider that throws is treated exactly like one that is incomplete. One
     * product's failure must not take the screen — and therefore every other
     * product's licence controls — down with it, but neither may it be passed off
     * as working.
     *
     * @return list<array{slug: string, title: string, complete: bool, state: array<string, mixed>, actions: array<string, string>}>
     */
    public function sections(string $titleSuffix = ''): array
    {
        $sections = [];
        foreach ($this->providers() as $slug => $provider) {
            try {
                $title = trim((string) $provider->title());
            } catch (\Throwable) {
                $title = '';
            }
            if ($title === '') {
                // A product that will not even name itself still gets a visible
                // section, because a silently missing one looks like a product
                // that was never installed.
                $title = $slug;
            }

            try {
                $state = (array) $provider->state();
                $actions = array_map('strval', (array) $provider->actions());
            } catch (\Throwable) {
                $sections[] = self::unavailable($slug, $title . $titleSuffix);
                continue;
            }

            foreach (self::REQUIRED_ACTIONS as $required) {
                if (($actions[$required] ?? '') === '') {
                    $sections[] = self::unavailable($slug, $title . $titleSuffix);
                    continue 2;
                }
            }

            $sections[] = [
                'slug' => $slug,
                'title' => $title . $titleSuffix,
                'complete' => true,
                'state' => $state,
                'actions' => $actions,
            ];
        }

        return $sections;
    }

    /**
     * @return array{slug: string, title: string, complete: bool, state: array<string, mixed>, actions: array<string, string>}
     */
    private static function unavailable(string $slug, string $title): array
    {
        return ['slug' => $slug, 'title' => $title, 'complete' => false, 'state' => [], 'actions' => []];
    }

    /**
     * A provider from another extension need not implement this extension's
     * interface — offering the four methods is enough, which is what keeps the
     * registry usable across products that do not depend on each other.
     */
    private function isUsable(object $provider): bool
    {
        foreach (['title', 'slug', 'state', 'actions'] as $method) {
            if (!method_exists($provider, $method)) {
                return false;
            }
        }

        return true;
    }
}
