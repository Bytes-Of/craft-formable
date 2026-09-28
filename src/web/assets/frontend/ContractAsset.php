<?php

declare(strict_types=1);

namespace bytesof\formable\web\assets\frontend;

use craft\web\AssetBundle;

/**
 * The `contract` theme level: the accessibility contract alone, no layout,
 * no paint.
 *
 * The a11y utility (`.formable-visually-hidden`), `[hidden]` state and a
 * tokenless focus-ring shape - nothing else - plus the structural normalize
 * in `formable-hardening.css` that keeps native list markers, a floated
 * `<legend>` and an oversized `<fieldset>` from a host's own unlayered reset
 * showing through even bare markup. Neither adds colour, typography or
 * layout - see `internal/decisions/0069`. For a headless or heavily-Tailwind
 * site that wants none of Formable's layout opinions but still needs the
 * markup's accessibility contract honoured; `none` drops that contract
 * entirely and leaves the site to reimplement it.
 * {@see ResetAsset} loads this same contract alongside layout for the
 * `reset` level, and {@see ThemeAsset} alongside both for `default`.
 *
 * @internal
 */
final class ContractAsset extends AssetBundle
{
    public function init(): void
    {
        // The commented source lives in `css/`; this is `formable-tokens.css`,
        // `formable-contract.css` and `formable-hardening.css` concatenated
        // and minified by `scripts/build-css.mjs` (`npm run build:css`, part
        // of `npm run build`), same as `FrontendAsset`'s JS bundle.
        $this->sourcePath = __DIR__ . '/dist';

        $this->css = [
            'formable-contract.css',
        ];

        parent::init();
    }
}
