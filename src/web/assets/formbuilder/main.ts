/**
 * Form builder CP asset bundle.
 *
 * Boots the Vue app against the `#formable-builder` mount point, reading its
 * whole configuration from the element's data attributes - the CP template
 * renders those server-side, so the builder needs no bootstrap request.
 *
 * Every module under this bundle is internal (docs/api-stability.md).
 */

import { createApp } from 'vue';
import { createPinia } from 'pinia';
import { configureCsrf } from './api';
import { t } from './helpers';
import FormBuilder from './components/FormBuilder.vue';
import { useBuilderStore } from './stores/builder';
import type { BuilderConfig } from './types';
import './styles/builder.css';

function boot(): void {
  const mount = document.getElementById('formable-builder');

  if (!mount) {
    return;
  }

  let config: BuilderConfig;

  try {
    config = JSON.parse(mount.dataset.formableConfig ?? '') as BuilderConfig;
  } catch {
    const notice = document.createElement('p');
    notice.className = 'error';
    notice.textContent = t(
      'The form builder couldn’t read its configuration. Reload the page to try again.',
    );

    mount.replaceChildren(notice);

    return;
  }

  configureCsrf(
    mount.dataset.formableCsrfName ?? 'CRAFT_CSRF_TOKEN',
    mount.dataset.formableCsrfToken ?? '',
  );

  const app = createApp(FormBuilder);
  app.use(createPinia());

  // The store is initialised before mounting so the first render already has
  // the form in hand and never flashes an empty builder.
  useBuilderStore().init(config);

  mount.innerHTML = '';
  app.mount(mount);
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', boot);
} else {
  boot();
}
