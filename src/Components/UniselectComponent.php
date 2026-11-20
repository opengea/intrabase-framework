<?php

declare(strict_types=1);

namespace Intrabase\Components;

use Intrabase\Security\Output;

/**
 * UniselectComponent
 *
 * Single select from pre-loaded values (like xcombo but simpler).
 * Extends SelectComponent since the behavior is identical.
 *
 * options:
 *   values      — associative array key => label
 *   placeholder — placeholder text (default: 'Select...')
 *   allow_empty — whether empty/blank selection is allowed (default: true)
 *   required    — whether the field is required
 *   readonly    — whether the field is read-only
 */
final class UniselectComponent extends SelectComponent
{
    // Inherits all behavior from SelectComponent.
    // This class exists as a semantic alias for cases where values
    // come from a DB query (pre-loaded by the module).
}
