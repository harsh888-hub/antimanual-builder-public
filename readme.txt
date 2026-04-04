=== Antimanual Builder ===
Contributors: spiderthemes
Requires Plugins: antimanual
Tags: page builder, ai, drag and drop, visual builder, html import
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 0.1.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An AI-powered visual page builder add-on for WordPress pages with AI chat, manual editing, migration tools, and HTML import.

== Description ==

**Antimanual Builder** is a visual page builder add-on for the Antimanual plugin. It gives you a full-screen editor for native WordPress pages, combining AI-assisted page generation with manual drag-and-drop editing, reusable components, and HTML import tools.

Builder currently depends on the Antimanual plugin being installed and active. AI provider, model, and API key settings are inherited from Antimanual instead of being managed separately inside Builder.

= Key Features =

* **AI-assisted page building** — Generate or refine page layouts from prompts directly inside the editor.
* **Manual visual editing** — Build pages without AI using drag-and-drop tools and inline canvas editing.
* **Native WordPress page workflow** — Builder pages are regular WordPress `page` posts stored with Builder meta.
* **Structured builder block system** — Builder uses internal layout, content, and advanced block structures to power generated pages, manual editing, rendering, and reusable components.
* **Page migration tools** — Migrate existing pages with AI or use direct migration to preserve the current rendered layout.
* **Migration behavior settings** — Replace the original page or create a draft copy before continuing.
* **HTML Import** — Import a single HTML file with assets or analyze/create pages from a ZIP project.
* **Custom Blocks and Component Library** — Save reusable sections/components and insert saved custom blocks from the editor toolbar.
* **Design Defaults** — Set tone, design system, typography, spacing, corners, and brand colors for new pages.
* **Responsive preview** — Preview layouts in Desktop, Tablet, Large Mobile, and Small Mobile modes.
* **Undo/Redo** — Editing history with keyboard shortcuts.
* **Generated asset storage** — Generated/imported assets are stored in `wp-content/uploads/antimanual-builder/`.

= AI Provider Integration =

* AI settings are inherited from the Antimanual plugin.
* Supported providers: **OpenAI** and **Google Gemini**.
* Manual editing, direct migration, and HTML import do not require an API key, but Antimanual must still be active.

== Installation ==

1. Upload the `antimanual-builder` folder to `/wp-content/plugins/`.
2. Install and activate the **Antimanual** plugin.
3. Activate **Antimanual Builder**.
4. If you want AI features, configure your provider and API key in **Antimanual**.
5. Open **AM Builder** from the WordPress admin menu.

== Frequently Asked Questions ==

= Do I need the Antimanual plugin? =

Yes. Antimanual Builder currently depends on the Antimanual plugin being installed and active.

= Do I need an AI API key? =

Only for AI features such as generation, refinement, and AI-based migration. Manual editing, direct migration, and HTML import can be used without an AI key.

= Which AI provider should I use? =

Builder currently supports the provider selected in Antimanual. OpenAI and Google Gemini are supported.

= Where do I configure AI providers? =

In the Antimanual plugin. Builder reads the active provider, model, and API key status from there.

= Can I use this alongside other page builders? =

Yes, but Builder edits native WordPress pages rather than a separate builder page post type. Use migration intentionally on the pages you want Builder to manage.

= Where are generated assets stored? =

Generated/imported assets are stored in `wp-content/uploads/antimanual-builder/`.

== Changelog ==

= 0.1.0 =
* Initial release.
* Full-screen Builder editor with AI chat and manual editing.
* Internal builder block system for layouts, content, and advanced elements.
* Component library.
* Page migration tools with AI/direct modes and replace/duplicate behavior.
* Single HTML and ZIP project import tools.
* Shared Antimanual AI provider integration.
* Design defaults and responsive preview modes.
