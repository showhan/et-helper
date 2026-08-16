# Reset Divi Presets & Global Variables

- **Author:** Eduard Ungureanu
- **Version:** 1.0.0
- **Requires at least:** Divi 4 (v4.27) or Divi 5 (v5.0.0)
- **License:** GPLv2 or later
- **License URI:** https://www.gnu.org/licenses/gpl-2.0.html

Adds a convenient admin bar menu to quickly reset Divi 4 presets, Divi 5 presets, and Divi 5 global variables — without leaving the page you are on.

## Description

This plugin provides a simple and efficient way to clear Divi presets and global variables directly from the WordPress admin bar. It is smart enough to detect which version of Divi is active and only shows the relevant options.

- If **Divi 4** is active, the menu shows the Divi 4 preset reset option.
- If **Divi 5** is active, the menu shows the Divi 5 preset sub-menu and the global variables sub-menu.
- The plugin **cannot be activated** if neither Divi 4 nor Divi 5 is the active theme.

Only users with `manage_options` capability (administrators) can see and use the menu.

## Menu Structure

### When Divi 4 is active

```
Reset Divi Presets
└── Reset Divi 4 Presets
```

### When Divi 5 is active

```
Reset Divi Presets
├── Reset Divi 5 Presets
│   ├── Reset Element Presets
│   ├── Reset Option Group Presets
│   └── ── Reset All Presets ──
└── Reset Divi 5 Global Variables
    ├── Reset Global Colors
    ├── Reset Global Fonts
    ├── Reset Global Images
    ├── Reset Global Links
    ├── Reset Global Numbers
    ├── Reset Global Text
    └── ── Reset All Global Variables ──
```

## What Gets Deleted

| Action | Database keys affected |
|---|---|
| Reset Divi 4 Presets | `et_divi_builder_global_presets_ng`, `et_divi_builder_global_presets_history_ng` |
| Reset D5 Element Presets | `module` key inside `et_divi_builder_global_presets_d5` |
| Reset D5 Option Group Presets | `group` key inside `et_divi_builder_global_presets_d5` |
| Reset All D5 Presets | `et_divi_builder_global_presets_d5`, all `et_divi_builder_presets_history_item_*`, `et_divi_builder_presets_history_meta` |
| Reset Global Colors | `et_global_data` key inside `et_divi` |
| Reset Global Fonts / Images / Links / Numbers / Text | Corresponding key inside `et_divi_global_variables` |
| Reset All Global Variables | `et_global_data` key inside `et_divi` + entire `et_divi_global_variables` option |

## How It Works

1. **Works on frontend and backend** — reset from any page, whether in the WP admin or on the live site.
2. **Confirmation dialog** — a prompt appears before any action is taken to prevent accidental resets.
3. **Instant feedback** — a success notice confirms what was reset.
4. **Stays on the current page** — after resetting you remain on the same URL.

This tool is particularly useful for developers and site builders who need to clear out presets or global variables during development or maintenance.

## Installation

1. Ensure the **Divi theme** (v4 or v5) is installed and active.
2. Upload the plugin folder to `/wp-content/plugins/`.
3. Activate the plugin through the **Plugins** menu in WordPress.
4. The **Reset Divi Presets** menu will appear in the admin bar.

## License

This plugin is licensed under the [GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html).
