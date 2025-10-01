# AGENTS.md

Guidance for agents working in this repository. This project is a technical exploration and is expected to be deprecated; favor minimal, low-risk changes and clear documentation over large refactors.

## Scope
- This file governs the entire repository rooted here.
- If additional `AGENTS.md` files appear in subdirectories later, those take precedence in their subtree.

## Local Environment
- Uses `@wordpress/env` with `.wp-env.json` at the repo root.
- Commands:
  - Start: `npx @wordpress/env start`
  - Stop: `npx @wordpress/env stop`
  - Destroy: `npx @wordpress/env destroy --force`
- Admin URL: `http://localhost:8888/wp-admin`
  - User: `admin`
  - Password: `password`
- WooCommerce is auto-installed/activated by `.wp-env.json`.

## Repo Layout
- `plugin.php` — bootstrap for the plugin (admin menu, activation checks).
- `.wp-env.json` — local dev environment config (includes WooCommerce).
- `.gitignore` — standard ignores.
- `README.md` — includes a deprecation/exploration notice. Keep it intact.

## Coding Conventions
- Language/Runtime: PHP 7.4+ (8.x recommended), WordPress 6+, WooCommerce current.
- WordPress Coding Standards (naming, escaping, sanitization, i18n):
  - Text domain: `openai-product-feed-for-woo`.
  - Escape on output: `esc_html`, `esc_attr`, `wp_kses` as appropriate.
  - Sanitize on input: `sanitize_text_field`, `sanitize_key`, etc.
  - Capability checks for admin actions: typically `manage_woocommerce`.
  - Nonces for any state-changing admin actions.
- Structure:
  - Keep the bootstrap in `plugin.php`.
  - If adding new code, prefer `includes/` with class names like `OAPFW_*` or function prefixes `oapfw_`.
  - Do not reorganize existing files unless explicitly requested.
- Performance/Safety:
  - Avoid heavy work on `init` or every admin page load; defer using hooks or background jobs when needed.
  - No external network calls unless explicitly requested.
- Licensing/Headers:
  - Do not add license headers. Keep existing plugin headers in `plugin.php` consistent.

## Product Notes
- This is a technical exploration and may be deprecated/replaced.
- Keep visible deprecation notice in `README.md` intact.
- Preserve WooCommerce dependency checks. The plugin should fail gracefully if WooCommerce is inactive.

## Tasks You Can Do
- Extend admin UI under the existing top-level menu.
- Add settings pages or WordPress options using proper capability checks and nonces.
- Implement feed-generation scaffolding (classes, services) without changing current entry points.
- Add hooks/filters that integrate with WooCommerce product data.
- Improve documentation in `README.md` and inline docblocks.

## Tasks To Avoid (without explicit request)
- Changing repository structure or renaming core files.
- Adding external services, SDKs, or Composer dependencies.
- Adding build tooling or CI.
- Performing destructive data operations or writing outside plugin scope.
- Committing to git (the user controls commits) or changing version numbers.

## Working Practices (for agent tools)
- Prefer `rg` for searching and read files in small chunks (≤250 lines).
- Use small, focused patches via `apply_patch`.
- Do not add inline debug `echo/var_dump`; use admin notices or logging if truly needed.
- Keep changes minimal and aligned with existing code style.

## Testing/Validation
- Use `@wordpress/env` to validate changes run and WooCommerce loads without fatal errors.
- Aim for defensive coding; handle missing prerequisites and edge cases gracefully.

## Internationalization
- Wrap user-facing strings with translation functions using the text domain `openai-product-feed-for-woo`.

## Security
- Always validate and sanitize input.
- Escape all output.
- Verify capabilities and use nonces on all state-changing actions.

## Contact/Ownership
- If behavior is ambiguous, prefer the least invasive, reversible change and document it in `README.md` under a short “Notes” section.

