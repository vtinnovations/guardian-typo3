<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Application\Contract;

/**
 * What one V-T.ONE product contributes to the shared licence overview.
 *
 * Several V-T.ONE extensions can be installed side by side, and each needs its own
 * licence section without any of them owning the screen. So the screen is a host
 * that renders sections, and a product supplies only what its own section says:
 * its name, its current state and the endpoints its own actions post to.
 *
 * Nothing here returns an activation key, a payload, a digest or a signature — a
 * section's state is the same administrator-facing projection the rest of the
 * interface uses, and the host renders it without knowing anything about the
 * product.
 *
 * A product from another extension is registered by class name and resolved from
 * the container, so it does not have to depend on this interface to take part; it
 * only has to offer the same four methods.
 */
interface PackageSectionProviderInterface
{
    /** The product's display name, e.g. "Guardian". */
    public function title(): string;

    /** The product's stable slug, unique across V-T.ONE products. */
    public function slug(): string;

    /**
     * The section's current state, safe for the browser.
     *
     * @return array<string, mixed>
     */
    public function state(): array;

    /**
     * Endpoint URLs the section's own actions post to, keyed by action name.
     *
     * @return array<string, string>
     */
    public function actions(): array;
}
