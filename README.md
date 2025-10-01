# OpenAI Product Feed for Woo

Status: Technical exploration only — this project is expected to be deprecated. It is not intended for production use and may be removed or replaced without notice.

A WordPress plugin that integrates OpenAI to generate and manage optimized product feeds for WooCommerce.

## Quick Start (Local Dev)

This repo includes a `.wp-env.json` so you can spin up a local WordPress with WooCommerce preinstalled using `@wordpress/env`.

- Prerequisites: Docker and Node.js (for `npx`).
- Start environment: `npx @wordpress/env start` (or `npx wp-env start`)
- Stop environment: `npx @wordpress/env stop`
- Open WP Admin: `http://localhost:8888/wp-admin`
  - User: `admin`, Password: `password`

The plugin in this folder is mounted automatically into the environment.

## Project Structure

- `plugin.php` — Main plugin bootstrap file and loader
- `.wp-env.json` — Local dev environment config (adds WooCommerce)
- `.gitignore` — Standard ignores for WordPress plugin development

## Development Notes

- Edit files and reload the browser; changes are reflected live in the container.
- To reset the environment completely: `npx @wordpress/env destroy --force`
- To enable debug logging, `.wp-env.json` sets `WP_DEBUG` to `true`.

## Minimum Requirements

- WordPress 6.0+
- PHP 7.4+ (8.x recommended)
- WooCommerce latest

## Roadmap

- Product feed generation endpoints and UI
- OpenAI prompt templates and settings
- Scheduled feed refreshes and background processing
- WooCommerce integration hooks and mappings

## License

GPL v2

## Notes

- Settings: WooCommerce > AI Feeds allows configuring feed format and merchant metadata.
- Export: Use the Export tab to download the current feed; or preview JSON at the admin-only route `oapfw/v1/feed`.
- Network: This plugin does not push feeds to external endpoints by default. External delivery can be added later upon explicit request.
