# Antimanual Builder

An AI-powered visual page builder add-on for WordPress. Build, migrate, and refine native WordPress pages with AI chat, manual editing, reusable components, and HTML import tools.

![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue?logo=wordpress)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple?logo=php)
![License](https://img.shields.io/badge/License-GPLv2%2B-green)
![Version](https://img.shields.io/badge/Version-0.1.0-orange)

---

## Overview

**Antimanual Builder** is a visual page builder plugin that works alongside the main [Antimanual](https://wordpress.org/plugins/antimanual) plugin. It provides a full-screen React-based editor for native WordPress pages, combining AI-assisted generation with manual drag-and-drop editing.

The plugin currently depends on Antimanual being installed and active. AI provider, model, and API key settings are inherited from Antimanual rather than managed separately inside Builder.

---

## Features

- **AI-assisted page building** — Generate or refine page layouts from natural-language prompts directly inside the editor.
- **Manual visual editing** — Build pages without AI using a drag-and-drop workflow and direct canvas editing.
- **Native WordPress page editing** — Builder pages are regular WordPress `page` posts with builder data stored in post meta.
- **Structured builder block system** — The editor uses internal layout, content, and advanced block structures to power generated pages, manual editing, rendering, and reusable components.
- **Custom blocks and component library** — Save reusable sections/components and insert your saved custom blocks from the editor toolbar.
- **Page migration** — Convert existing WordPress pages into Builder pages using either:
  - **AI migration** for redesign/restructure assistance, or
  - **Direct migration** to preserve the current front-end output as closely as possible.
- **Migration behavior controls** — Choose whether migration replaces the original page or creates a draft copy.
- **HTML import** — Import a single HTML file with assets, or analyze/create multiple pages from a ZIP project.
- **Design defaults** — Configure shared defaults for tone, design system, typography, spacing, corner style, and brand colors.
- **Responsive preview modes** — Preview layouts in Desktop, Tablet, Large Mobile, and Small Mobile widths.
- **Inline editing** — Edit text directly on the canvas.
- **Undo/Redo history** — Keyboard-friendly editing history with undo/redo support.
- **Generated asset storage** — Generated/imported assets are stored in `wp-content/uploads/antimanual-builder/`.

---

## AI Provider Integration

Antimanual Builder does **not** maintain its own AI provider settings at runtime.

It currently reads the active provider and model from the parent Antimanual plugin.

Supported providers:

| Provider | Configuration Source |
| --- | --- |
| OpenAI | Antimanual plugin settings |
| Google Gemini | Antimanual plugin settings |

> Manual editing, direct migration, and HTML import do not require an AI key, but the Antimanual plugin must still be active for Builder to run.

---

## Requirements

- WordPress 6.0 or higher
- PHP 7.4 or higher
- The [Antimanual](https://wordpress.org/plugins/antimanual) plugin installed and active

---

## Installation

1. Upload the `antimanual-builder` folder to `/wp-content/plugins/`.
2. Install and activate the **Antimanual** plugin.
3. Activate **Antimanual Builder**.
4. If you want to use AI features, configure your provider and API key in **Antimanual**.
5. Open **AM Builder** in the WordPress admin menu.

---

## Admin Areas

- **All Pages** — List and manage Builder-enabled pages.
- **Components** — Manage reusable components.
- **HTML Import** — Import single HTML documents or ZIP-based projects.
- **Settings** — Review shared AI settings, choose migration behavior, and configure design defaults.
- **Editor** — Full-screen visual builder with chat, canvas, hierarchy, and settings panels.

---

## Development

### Prerequisites

- Node.js (LTS recommended)
- npm

### Setup

```bash
npm install
```

### Build

```bash
npm run build
npm run start
```

Build output is written to the `build/` directory.

---

## Project Structure

```text
antimanual-builder/
├── antimanual-builder.php   # Plugin entry point
├── includes/                # PHP classes (admin, API, post types, render)
├── src/                     # React/TypeScript source files
│   ├── admin/               # Admin SPA pages
│   ├── editor/              # Full-screen builder editor
│   └── components/          # Shared UI components
├── build/                   # Compiled assets for distribution
├── assets/                  # Static CSS and images
└── templates/               # Frontend page templates
```

---

## FAQ

**Do I need the Antimanual plugin?**  
Yes. Antimanual Builder currently depends on Antimanual being installed and active.

**Do I need an AI API key?**  
Only for AI features such as generation, refinement, and AI-based migration. Manual editing, direct migration, and HTML import can be used without an AI key.

**Where do I configure AI providers?**  
In the parent Antimanual plugin. Builder reads the active provider, model, and API key status from there.

**Can I use this alongside other page builders?**  
Yes, but Builder edits native WordPress pages. It does not create a separate page post type for site pages, so migration should be used intentionally on a page-by-page basis.

**Where are generated assets stored?**  
In `wp-content/uploads/antimanual-builder/`.

---

## Changelog

### 0.1.0

- Initial public release
- Full-screen Builder editor with AI chat and manual editing
- Internal builder block system for layouts, content, and advanced elements
- Component library
- Page migration controls (AI/direct + replace/duplicate)
- Single HTML and ZIP project import tools
- Shared Antimanual AI provider integration
- Design defaults and responsive preview modes

---

## License

[GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html)

---

## Author

[Spider Themes](https://spider-themes.net)
