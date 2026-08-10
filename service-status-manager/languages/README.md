# Translations

Every user-facing string in this plugin is wrapped in WordPress
internationalisation functions (`__()`, `_e()`, `esc_html__()`, etc.)
under the `service-status-manager` text domain, and default wording is
UK English.

To generate the `.pot` translation template, run from the plugin
directory:

```bash
wp i18n make-pot . languages/service-status-manager.pot
```

Translated `.po`/`.mo` (and modern `.l10n.php`) files placed in this
directory, named `service-status-manager-{locale}.mo`, are picked up
automatically via `load_plugin_textdomain()` in the plugin bootstrap.
