# Kitgenix Document Manager

## Overview

**Kitgenix Document Manager** is a WordPress document-management / document-library plugin. Its main plugin file docblock (`kitgenix-document-manager.php`) describes it as:

> "Manage document downloads with stable links, granular access, lifecycle scheduling, and usage analytics."

The plugin wraps ordinary WordPress attachments in a managed "document" layer so that a document keeps one **stable URL** (`/kitgenix-document-manager/{slug}/`) even when the underlying file is replaced. Around that core idea it adds:

- Public / Private / Restricted visibility, with role, capability, and user-ID based access rules.
- Time-limited, optionally use-limited **signed share links** for people without a WordPress account.
- Lifecycle scheduling (available-from / archive-at / expires-at) with an hourly cron sweep.
- Optional per-document version history with labels/notes and restore/delete.
- Hierarchical document categories.
- Two frontend shortcodes (a plain link and a document "card").
- A capped, self-pruning local activity/event log.
- Local, privacy-conscious document analytics (aggregate counts only, no per-visitor tracking).
- A read-only REST metadata endpoint, Site Health integration, and settings/category import-export.

**Key facts** (from the plugin header and `readme.txt`):

| Field | Value |
|---|---|
| Plugin Name | Kitgenix Document Manager |
| Version | 2.1.0 (`kitgenix-document-manager.php`) |
| Requires at least (WP) | 6.0 |
| Tested up to (WP) | 7.1 |
| Requires PHP | 8.1 |
| License | GPLv3 or later |
| Text Domain | `kitgenix-document-manager` |
| Domain Path | `/languages` |
| Author | Kitgenix (https://kitgenix.com/) |

The plugin is one of a family of "Kitgenix" plugins that share a common admin design system, a top-level "Kitgenix" admin menu/hub page, and shared CSS/JS component libraries (`kitgenix-admin-ui.css`, `kitgenix-admin-tabs.js`, `kitgenix-admin-components.js`, `kitgenix-hub.css`).

## Architecture

### Folder / file layout

```
kitgenix-document-manager.php          Main plugin file: bootstrap, constants, Kitgenix Hub page, activation/deactivation
uninstall.php                          Uninstall routine (WP_UNINSTALL_PLUGIN guarded)
readme.txt                             WordPress.org readme (features, FAQ, external services, privacy, changelog)
CHANGELOG.md                           Human-readable changelog (parallels the "Changelog" section of readme.txt)
languages/
  kitgenix-document-manager.pot        Translation template only – no .po/.mo files shipped
includes/
  core/
    helpers.php                       Namespaced free functions: settings, access control, schedules, analytics, share-link signing, upload-dir helpers
    class-event-log.php               Event_Log – capped, auto-pruning activity log stored in a WP option
    class-capabilities.php            Capabilities – custom capability + user_can_manage() gate
    class-cpt.php                     CPT – registers the document post type + category taxonomy; legacy post-type migration
    class-uploader.php                Uploader – $_FILES handling, wp_handle_upload() wrapper, version replacement logic
    class-endpoint.php                Endpoint – stable-link rewrite rule + the actual file-serving/streaming logic (Range, ETag, headers)
    class-attachment-guard.php        Attachment_Guard – blocks REST media / attachment-permalink access to protected documents' files
    class-lifecycle.php               Lifecycle – hourly cron that detects and fires lifecycle state transitions
    class-script-handler.php          Script_Handler – enqueues admin/public CSS & JS
    class-shortcode.php               Shortcode – [kitgenix_document_manager_link] and [kitgenix_document_manager_document]
    class-rest.php                    Rest – GET /kitgenix-document-manager/v1/doc/{slug} REST route
  admin/
    class-admin-menu.php              Admin_Menu – ensures the shared "Kitgenix" top-level menu exists
    class-admin-options.php           Admin_Options – register_setting()/sanitize() for the main settings option
    class-admin-columns.php           Admin_Columns – custom columns & row actions on the native CPT list table
    class-document-metabox.php        Document_Metabox – native post-editor metaboxes (File, Visibility) + admin-post upload/replace handlers
    class-settings-ui.php             Settings_UI – the entire custom admin app (tabs, tables, modals, all admin-post/ajax handlers) – largest file in the plugin
    class-site-health.php             Site_Health – Site Health test for the uploads directory
assets/
  css/
    admin.css                         Plugin-specific admin layout/state CSS (extends the shared admin-ui library)
    kitgenix-admin-ui.css             Shared Kitgenix admin design-system CSS (topbar, tabs, tables, cards, forms, toggles, modals, tokens, dark mode)
    kitgenix-hub.css                  CSS for the shared "Kitgenix" hub/landing admin page
    public.css                        Frontend document-card CSS
  js/
    admin.js                          Plugin-specific admin JS (tabs, modals, media picker, shortcode builder, bulk tools, dropzone)
    kitgenix-admin-tabs.js            Shared topbar/tabs/search/theme-toggle/mobile-menu controller (identical across Kitgenix plugins)
    kitgenix-admin-components.js      Shared component library: toasts, modals, collapsibles, copy-to-clipboard, table search/pagination
    public.js                         Empty placeholder (header comment only; no functional code)
```

### Bootstrap sequence

1. `kitgenix-document-manager.php` is loaded by WordPress. It first defines (guarded by `function_exists()`) several **global, cross-plugin helper functions** prefixed `kitgenix_` that are shared conventions across all Kitgenix plugins:
   - `kitgenix_get_admin_menu_icon()` / `kitgenix_ensure_admin_menu()` — create a single top-level "Kitgenix" admin menu (`admin_menu` priority 5) shared by every installed Kitgenix plugin, pointing at `kitgenix_render_admin_page()` (the "Hub").
   - `kitgenix_hub_get_wporg_active_installs()`, `kitgenix_hub_get_wporg_ratings()`, `kitgenix_hub_get_wporg_media()` — cached (`DAY_IN_SECONDS` transients) lookups against `plugins_api()` used only to decorate the Hub page with install counts/ratings/icons for the other Kitgenix plugins.
   - `kitgenix_render_admin_page()` — renders the Hub page: a grid of cards for every known Kitgenix plugin (Document Manager, Turnstile CAPTCHA, Custom Tabs for WooCommerce, Order Tracking, PDF Invoicing, Stock Sync, MultiStore, Image Optimizer, Affiliate Link Manager) with Install/Activate/Open/Details/Review/Support actions.
   - Admin CSS (`kitgenix-admin-ui.css`) is registered — not enqueued — on every `is_admin()` request, and explicitly enqueued by `Script_Handler::enqueue_admin()` on the plugin's settings/editor screens. Thickbox, the `plugin-install` style, and `kitgenix-hub.css` are only enqueued when `page=kitgenix` (the shared Hub page itself) — not on the Document Manager settings screen.
2. Plugin constants are defined (guarded): `KITGENIX_DOCUMENT_MANAGER_VERSION` (`2.1.0`), `_FILE`, `_DIR`, `_URL`, `_INCLUDES_DIR`, `_ASSETS_URL`, `_TEXT_DOMAIN` (`kitgenix-document-manager`), `_OPTION_NAME` (`kitgenix_document_manager_settings`).
3. All `includes/core/*.php` and `includes/admin/*.php` class files are `require_once`'d directly (no autoloader/Composer — see below).
4. On `plugins_loaded`, a closure initializes the core singletons in order: `Capabilities::init()`, `CPT::init()`, `Endpoint::init()`, `Attachment_Guard::init()`, `Lifecycle::init()`, `Event_Log::init()`, `Script_Handler::init()`, `Shortcode::init()`, `Rest::init()`. It then calls `kitgenix_document_manager_maybe_upgrade()` (idempotent, see below), and if `is_admin()`, also calls `Capabilities::add_caps()` and initializes `Admin_Options::init()`, `Settings_UI::init()`, `Site_Health::init()`.
   - Note: translations are **not** explicitly loaded via `load_plugin_textdomain()` — the code comment states this is unnecessary for a WordPress.org-hosted plugin (auto-loaded translations).
5. `kitgenix_document_manager_maybe_upgrade()` compares the stored option `kitgenix_document_manager_db_version` against `Core\kitgenix_document_manager_db_version()` (currently `'2.0.0'`). If different, it runs `CPT::migrate_legacy_posts()`, `kitgenix_document_manager_ensure_upload_dir()`, `Lifecycle::schedule()`, `Event_Log::schedule()`, then updates the stored version option. This runs on every request but is a cheap single `get_option()` check when already up to date.
6. **Activation** (`register_activation_hook`): registers the CPT, migrates legacy posts, adds rewrite rules, adds capabilities, ensures/protects the upload directory, schedules both cron hooks, stores the db-version option, and flushes rewrite rules.
7. **Deactivation** (`register_deactivation_hook`): unschedules both cron hooks and flushes rewrite rules (soft flush, `false`). No data is deleted on deactivation.

### Autoloading

There is **no PSR-4 autoloader and no Composer**. Every class file is explicitly `require_once`'d in a fixed order from the main plugin file. All classes live under the `KitgenixDocumentManager\Core` or `KitgenixDocumentManager\Admin` namespaces; free (non-class) functions live in `KitgenixDocumentManager\Core` as well (defined in `helpers.php` and at the bottom of the main plugin file for the upgrade routine, which is in the global namespace).

### Key classes and responsibilities

| Class | Namespace | Responsibility |
|---|---|---|
| `Capabilities` | Core | Defines `manage_kitgenix_document_manager_documents`; `user_can_manage()` also accepts `manage_options` and (if WooCommerce active) `manage_woocommerce`; grants the capability to Administrator and Shop Manager roles on activation. |
| `CPT` | Core | Registers the `kitgenix_dm_doc` post type and `kitgenix_dm_cat` taxonomy; migrates any posts of the legacy post type `kitgenix_document_manager_document` (over 20 chars, dropped early in development) to the new type. |
| `Uploader` | Core | Wraps `$_FILES` access (`get_incoming_file()`/`get_incoming_files()`), calls `wp_handle_upload()` scoped to the plugin's own upload subdirectory, validates extensions, creates the attachment, and implements version-replacement logic (`replace_file()`). |
| `Endpoint` | Core | Registers the `kitgenix-document-manager/{slug}/` rewrite rule and query var; on `template_redirect`, evaluates access, then streams the file with security headers, conditional caching (ETag/Last-Modified), and single-range (HTTP 206/416) support. |
| `Attachment_Guard` | Core | Defense-in-depth: prevents a protected document's underlying attachment from being reachable via `/wp/v2/media/{id}`, REST collection queries, or the classic attachment permalink. |
| `Lifecycle` | Core | Hourly cron (`kitgenix_document_manager_lifecycle_check`) that recomputes each scheduled document's lifecycle state and fires `kitgenix_document_manager_lifecycle_status_changed` + a log entry the first time a transition is observed. |
| `Event_Log` | Core | Capped (100 entries) WP-option-backed activity log with a daily prune cron (`kitgenix_document_manager_prune_event_log`) honoring a configurable retention period (7/14/30 days). |
| `Script_Handler` | Core | Enqueues admin CSS/JS only on the plugin's settings screen or the native document editor screen; enqueues public CSS only when the document-card shortcode is present in the current singular post's content. |
| `Shortcode` | Core | Implements `[kitgenix_document_manager_link]` and `[kitgenix_document_manager_document]`. |
| `Rest` | Core | Registers the read-only `GET /kitgenix-document-manager/v1/doc/{slug}` route. |
| `Admin_Menu` | Admin | Thin wrapper calling the shared `kitgenix_ensure_admin_menu()` — defined and `require_once`'d, but currently **dead code**: `Admin_Menu::init()` is never called anywhere in the bootstrap (the shared menu is instead created directly via `kitgenix_ensure_admin_menu()` hooked at `admin_menu` priority 5 in the main plugin file), so this class has no effect in the running plugin. |
| `Admin_Options` | Admin | `register_setting()` + `sanitize()` for `kitgenix_document_manager_settings` (the Settings API path used by the General/Frontend settings forms, which post to `options.php`). |
| `Admin_Columns` | Admin | Adds Stable Link / Current Version / File / Visibility / Actions columns and a "Replace File" row action to the native `kitgenix_dm_doc` list table (used only if that native list table is ever viewed directly; the plugin's own custom UI is the primary interface). |
| `Document_Metabox` | Admin | Native post-editor ("Add/Edit Document") metaboxes for the File and Visibility panels; registers `admin_post` handlers for the initial upload and file replacement forms in that editor. |
| `Settings_UI` | Admin | The plugin's actual admin application: renders the tabbed "Kitgenix Document Manager" page (Documents, Categories, Versions, Settings, Frontend, Log, Support) and implements essentially all `admin_post_*` handlers (document CRUD, bulk actions, batch upload, version restore/delete, category CRUD, share revoke/regenerate, settings import/export, log clear/retention) plus the `wp_ajax_kitgenix_dm_search_docs` handler used by the shortcode builder. |
| `Site_Health` | Admin | Registers a `site_status_tests` "direct" test checking that the plugin's uploads directory exists and is writable. |

## Features

### Document upload & storage

A document can acquire its "current file" in three ways, all funneled through `Uploader`:

1. **Initial upload** from the native post editor (`Document_Metabox::handle_upload_initial`, action `kitgenix_document_manager_upload_initial`).
2. **Replace file** from the native post editor (`Document_Metabox::handle_replace_file`, action `kitgenix_document_manager_replace_file`) or from the custom admin UI's Add/Edit form (`Settings_UI::handle_document_save`).
3. **Select an existing Media Library attachment** instead of uploading a new file (custom admin UI only — `selected_attachment_id` field, validated against the allowed-extensions list, and the attachment is re-parented to the document via `wp_update_post()`).

`Uploader::upload_and_attach()`:
- Requires `Capabilities::user_can_manage()`.
- Requires a genuine `is_uploaded_file()` temp file.
- Ensures the plugin's private upload directory exists/is protected.
- Filters `upload_dir` for the duration of the call so `wp_handle_upload()` writes into `wp-content/uploads/kitgenix-document-manager/` instead of the normal monthly media folder.
- Passes an explicit `mimes` allowlist (extension → MIME) built from the site's configured allowed extensions intersected with a **fixed, hand-audited map** (see File Handling).
- Re-validates the extension after `wp_handle_upload()` returns (defense in depth) and deletes the file if it somehow isn't allowed.
- Creates the attachment via `wp_insert_attachment()` parented to the document post, then generates attachment metadata.
- Every failure path is both returned as a `WP_Error` (with a specific code) and recorded to `Event_Log`.

`Uploader::replace_file()` additionally handles version bookkeeping: if versioning is enabled for the document, the old "current" attachment and the new attachment are both added to the `_kitgenix_document_manager_versions` list; if disabled, only the newest attachment is kept (optionally deleting all prior version/former-current attachments, per the `delete_old_versions` setting).

### Categorization

Documents are organized with the hierarchical `kitgenix_dm_cat` taxonomy (see CPT section). The custom admin UI's Categories tab supports add/edit/delete with a name, auto-generated-or-custom slug, and a parent category selector (self-parenting is silently prevented in `handle_category_save()`). A document is assigned to **at most one** category at a time (`wp_set_object_terms( ..., false )` — not additive) via the Documents tab/modal or via a bulk "Assign category" / "Clear category" action.

### Search / filter (admin)

The Documents tab renders up to 200 published documents into one HTML table and does **all filtering client-side** in `admin.js`'s `bindDocsSearch()`:
- A free-text search box matches against a precomputed `data-kitgenix-search` blob per row (title, category, slug, stable URL, filename, size, replaced-date, visibility label, roles, capability, lifecycle label).
- Three `<select>` filters — Status (`data-kitgenix-status`: `live|scheduled|restricted|archived|expired|unavailable`; a `draft` lifecycle state also exists in code but can never appear here since the Documents tab only ever queries `post_status=publish`), Visibility (`data-kitgenix-visibility`: `public|private|restricted`), and File Type (`data-kitgenix-filetype`: the current file's extension, options generated from the configured allowed-extensions list).
- A result counter, a "Clear" button, and an empty-state message are updated live. This is a client-side filter over a capped, already-rendered 200-row table, not a server-side query — there is no pagination beyond that first 200.

### Display formats

Frontend display is exclusively via the two shortcodes (no blocks, no widgets — see Shortcodes section): a plain text link and a "card" (thumbnail/icon + title + optional description + optional file-type/size metadata + an action button). Multiple documents can be rendered as a responsive grid of cards (`kitgenix-document-manager-cards`, 4 columns down to 1 column under 768px) via the `slugs` attribute.

### Download/view tracking

Every successful stable-link request records an analytics event (see Data Storage → `_kitgenix_document_manager_analytics`): a `view` (inline disposition) or `download` (attachment disposition) event, further split into "signed" vs. ordinary. Aggregates are: lifetime `views`, `downloads`, `signed_views`, `signed_downloads`, `last_access_gmt`/`last_access_type`, and a `daily` bucket map (`Y-m-d => {views,downloads,signed_views,signed_downloads}`) pruned to the last 90 days on every write. The admin UI additionally computes a rolling last-30-days sum (`kitgenix_document_manager_sum_document_analytics()`) for the Documents table/modal. Analytics deliberately never store an IP address, user agent, referrer, or per-visitor identifier (per `readme.txt`'s Privacy section).

### Versioning

Optional per document (`_kitgenix_document_manager_versioning_enabled`, defaulting to the site-wide `keep_version_history` setting). When enabled, every attachment that has ever been "current" for the document is retained in `_kitgenix_document_manager_versions` (an ordered array of attachment IDs) with an optional label/note (`_kitgenix_document_manager_version_meta`, keyed by attachment ID). The Versions tab and the Documents-tab edit modal let an admin:
- **Restore** an old version (`kitgenix_document_manager_version_restore`) — sets it back to "current" without altering the version list.
- **Delete** a single old (non-current) version (`kitgenix_document_manager_version_delete`) — removes it from the list and calls `wp_delete_attachment( ..., true )`.
- **Delete all** old versions for a document (`kitgenix_document_manager_versions_delete_all`) — keeps only the current attachment.
"Uploaded by" for a version is derived directly from the attachment's own `post_author` (no separate tracking field needed).

## Admin Interface

The plugin renders one top-level Kitgenix Hub page (`admin.php?page=kitgenix`, capability `manage_options` or, if WooCommerce is active, `manage_woocommerce`) plus one submenu page under it:

- **Kitgenix → Document Manager** (`admin.php?page=kitgenix-document-manager`, registered by `Settings_UI::register_menu()` at `admin_menu` priority 55, required capability `manage_options` on the `add_submenu_page()` call itself, though page access and every handler additionally gate on `Capabilities::user_can_manage()`).

The page is a single-page app-style screen (`Settings_UI::render_page()`) with a sticky topbar (brand, tab menu with a "Documents" dropdown, in-page settings search with `/` and Cmd/Ctrl+K shortcuts, a light/dark theme toggle persisted to `localStorage`, a link back to the Kitgenix Hub, and a responsive hamburger menu) and seven tab panels, each toggled client-side (`hidden` + inline `display:none`) and addressable via `?tab=` or a `#kitgenix-document-manager-tab-{slug}` hash:

### Documents tab
- **All Documents** card: search bar + Status/Visibility/File-type filters (client-side, see Features) over up to 200 published documents; a bulk-action toolbar (select-all checkbox, bulk-action `<select>`, a category dropdown that is only enabled for "Assign category", an Apply button disabled until a valid selection exists); a table with Select / Name / Category / Visibility (+ lifecycle badge) / Actions columns. Row actions: Open link, Copy link, Copy share link (if any), Copy shortcode, Edit (opens a modal pre-filled from `data-*` attributes), Delete.
  - Bulk actions (`bulk_action` select, posted to `kitgenix_document_manager_documents_bulk_action`): `visibility_public`, `visibility_private`, `visibility_restricted`, `assign_category` (requires `bulk_category_id`), `clear_category`, `archive_now` (stamps `archive_at_gmt` = now), `clear_schedule` (deletes all three schedule meta keys), `delete` (trash). "Set to Public" and "Archive now" prompt a JS confirmation first.
- **Add Document** card: Name (auto-slugifies into Slug unless the slug field has been edited), Slug, Description, an **Access preset** quick-fill (`public` / `logged_in` / `admins_only` — only pre-fills the fields below, nothing is saved until submit), Visibility select, Allowed roles (multi-select), Required capability, Allowed user IDs, Signed-share expiry/max-uses/URL (with Revoke/Regenerate), Available-from/Archive-at/Expire-at datetime fields + a live schedule-status badge, Category select, a file input **or** a "Select from Media Library" button (`wp.media` frame, mutually exclusive with the file input), Keep-version-history toggle, optional Version label, and a live-updating Analytics summary (views/downloads/recent 30-day/signed counts/last access). Submits to `kitgenix_document_manager_document_save`.
  - The same field set (in a modal, IDs prefixed `kitgenix_dm_modal_`) is used for editing an existing document; the modal is opened either by clicking "Edit" (JS reads the row's `data-*` attributes) or by navigating to `?kitgenix_action=edit&document_id=…` which pre-renders the same form server-side.
- **Batch Upload** card: a drag-and-drop dropzone plus a multi-file `<input type="file" multiple>`, shared Visibility/Category/Keep-version-history for every file created, submitted to `kitgenix_document_manager_documents_bulk_upload`. Each file becomes one new document titled from its filename (dashes/underscores → spaces, or "Document N" if the derived title is empty); a document that fails to create or upload is skipped (counted as "failed") without aborting the rest of the batch.

### Categories tab
List of existing `kitgenix_dm_cat` terms with hierarchy display, an "Add Category" form/modal (Name, Slug, Parent), and per-term Edit/Delete actions.

### Versions tab
A cross-document view of version history (restore/delete actions per version, "Delete all old versions" per document).

### Settings tab
**General Settings** card (posts to `options.php` via the WordPress Settings API, `settings_fields( 'kitgenix_document_manager_settings_group' )`):

| Field | Option key | Type | Default |
|---|---|---|---|
| Allowed file extensions | `allowed_extensions` | comma-separated text, normalized to an array | `pdf, doc, docx, xls, xlsx, ppt, pptx, txt` |
| Default visibility | `default_visibility` | select: public/private/restricted (only public/private normalize; see helpers) | `public` |
| Serving mode | `serving_mode` | select: attachment/inline | `attachment` |
| Keep version history by default | `keep_version_history` | toggle | `true` |
| Delete old versions when history is disabled | `delete_old_versions` | toggle | `false` |
| Private document behavior | `private_behavior` | select: login/403 | `login` |
| Delete documents and attachments on uninstall | `delete_data_on_uninstall` | toggle | `false` |

Below it, an **Import / Export** card: "Export settings & categories (.json)" (→ `kitgenix_document_manager_export_settings`, requires `manage_options`) and an import form (file input + `kitgenix_document_manager_import_settings`, requires `manage_options`). See Data Storage / Security for exactly what is and is not included.

### Frontend tab
**Frontend Card** settings (also via `options.php`, same settings group) controlling the default output of `[kitgenix_document_manager_document]`:

| Field | Option key | Type | Default |
|---|---|---|---|
| Button label | `card_button_label` | text | `View` |
| Button style | `card_button_style` | select: outline/solid | `outline` |
| Open "View" link in a new tab | `card_open_new_tab` | toggle | `false` |
| Show file type | `card_show_file_type` | toggle | `true` |
| Show file size | `card_show_file_size` | toggle | `true` |
| Show description | `card_show_description` | toggle | `false` |
| Preview image size (px) | `card_image_size` | number, 32–256 | `96` |
| File type/size alignment | `card_meta_alignment` | select: justify/left/center/right | `justify` |
| Button alignment | `card_button_alignment` | select: justify/left/center/right | `center` |
| Button text/background/border color (+ hover variants, 6 fields) | `card_button_text_color`, `card_button_bg_color`, `card_button_border_color`, `card_button_hover_text_color`, `card_button_hover_bg_color`, `card_button_hover_border_color` | hex color text (validated with `sanitize_hex_color()`) | `''` (theme default) |

Below it, a **Shortcode Builder** card: a Single/Multiple mode switch, a live admin-ajax search (`kitgenix_dm_search_docs`) to pick one or more documents by title, and a read-only output field with a "Copy shortcode" button.

### Log tab
A searchable, paginated (25/page) table of `Event_Log` entries (Time/Context/Outcome/Note), an "Auto-delete logs after" 7/14/30-day select (→ `kitgenix_document_manager_save_log_retention`), a "Clear log" button (→ `kitgenix_document_manager_clear_event_log`, confirmed via JS), and a static reference table explaining what each logged category means and whether it needs action.

### Support tab
A donate/support hero (PayPal donate link), review/docs/copy-plugin-link actions. No settings.

All settings-tab forms are also protected by the WordPress core Settings API nonce (`settings_fields()` + `options.php`); the plugin does not add a redundant custom nonce for these two forms specifically (documented in code as intentional, to avoid apparent "reverted" saves).

## Custom Post Types / Taxonomies

### Post type: `kitgenix_dm_doc` (`CPT::POST_TYPE`)

```php
register_post_type( 'kitgenix_dm_doc', [
    'labels'             => [ /* "Kitgenix Docs" family of labels */ ],
    'public'             => false,
    'publicly_queryable' => false,
    'show_ui'            => false,
    'show_in_menu'       => false,
    'show_in_admin_bar'  => false,
    'show_in_nav_menus'  => false,
    'show_in_rest'       => false,
    'has_archive'        => false,
    'rewrite'            => false,
    'supports'           => [ 'title' ],
    'capability_type'    => [ 'kitgenix_document', 'kitgenix_documents' ],
    'capabilities'       => [ /* every cap mapped to Capabilities::CAPABILITY */ ],
    'map_meta_cap'       => true,
    'menu_icon'          => 'dashicons-media-document',
] );
```

Every WordPress-standard capability (`edit_post`, `edit_posts`, `edit_others_posts`, `publish_posts`, `read_private_posts`, `delete_posts`, `delete_private_posts`, `delete_published_posts`, `delete_others_posts`, `edit_private_posts`, `edit_published_posts`, `create_posts`, plus singular `read_post`/`delete_post`) is mapped to the single custom capability `manage_kitgenix_document_manager_documents`. Because `show_ui`/`show_in_menu` are both `false`, WordPress does **not** generate a native `edit.php?post_type=kitgenix_dm_doc` list-table menu item — `Admin_Columns` and `Document_Metabox` still function if that screen is reached directly (e.g. via a direct edit link), but the plugin's own `Settings_UI` tabbed screen is the primary/intended interface.

Only the `title` support is declared — there is no post content editor, and the document's "description" is a separate postmeta field, not `post_content`.

A **legacy post type** constant `LEGACY_POST_TYPE = 'kitgenix_document_manager_document'` (too long for the `post_type` column pre-optimization) is migrated to `kitgenix_dm_doc` via `CPT::migrate_legacy_posts()`, called on both activation and the idempotent upgrade routine; it paginates 100 posts at a time via `WP_Query` and calls `wp_update_post()` to change `post_type`.

### Taxonomy: `kitgenix_dm_cat` (`CPT::TAXONOMY_CATEGORY`)

```php
register_taxonomy( 'kitgenix_dm_cat', [ 'kitgenix_dm_doc' ], [
    'hierarchical'      => true,
    'labels'            => [ /* "Document Categories" family */ ],
    'public'            => false,
    'show_ui'           => false,
    'show_admin_column' => false,
    'show_in_nav_menus' => false,
    'show_tagcloud'     => false,
    'show_in_rest'      => false,
    'rewrite'           => false,
    'query_var'         => false,
    'capabilities'      => [ 'manage_terms'=>CAP, 'edit_terms'=>CAP, 'delete_terms'=>CAP, 'assign_terms'=>CAP ],
] );
```

Fully hidden from WordPress core UI/REST/permalinks; managed exclusively through the plugin's own Categories tab (`wp_insert_term()`/`wp_update_term()`/`wp_delete_term()`/`wp_set_object_terms()`).

## File Handling

### Upload mechanism
`Uploader::upload_and_attach()` (see Features) delegates to WordPress core's `wp_handle_upload()`, but redirects the destination via a temporary `upload_dir` filter so files land under a dedicated subdirectory rather than the normal `YYYY/MM` media structure.

### Storage location
`wp-content/uploads/kitgenix-document-manager/` (`kitgenix_document_manager_upload_dir()` / `..._upload_subdir()` in `helpers.php`). This directory is created with `wp_mkdir_p()` on activation and on every upgrade-routine run (`kitgenix_document_manager_ensure_upload_dir()`), and "protected" (`kitgenix_document_manager_protect_upload_dir()`) by idempotently writing three files into it if they don't already exist:
- `.htaccess` — `Require all denied` (Apache 2.4) with a `mod_authz_core`-absent fallback (`Deny from all`).
- `web.config` — IIS `<authorization><deny users="*" /></authorization>`.
- `index.php` — an empty silence-is-golden stub to defeat directory listing.

These files only block *direct* web requests to that folder — the plugin itself still reads files from local disk with `fopen()`/`fread()`/`readfile()`, which is unaffected. The code comments explicitly note that **Nginx is not covered** (WordPress plugins cannot write Nginx server config) and recommend an equivalent `location` block be added manually.

Files selected from elsewhere in the Media Library (rather than uploaded fresh) are **not moved** — they stay at their original location/URL by design, and are protected instead via `Attachment_Guard` (REST/permalink blocking) rather than directory-level rules.

### Allowed file types
A **fixed, hard-coded allowlist** (`kitgenix_document_manager_extension_mime_map()` in `helpers.php`) is the ceiling for what can ever be enabled:

| Extension | MIME type |
|---|---|
| pdf | application/pdf |
| doc | application/msword |
| docx | application/vnd.openxmlformats-officedocument.wordprocessingml.document |
| xls | application/vnd.ms-excel |
| xlsx | application/vnd.openxmlformats-officedocument.spreadsheetml.sheet |
| ppt | application/vnd.ms-powerpoint |
| pptx | application/vnd.openxmlformats-officedocument.presentationml.presentation |
| txt | text/plain |

The site's configurable `allowed_extensions` setting is **intersected** with this map both when settings are saved (`Admin_Options::sanitize()` silently drops and warns about unsupported extensions via `add_settings_error()`) and again at upload time (`kitgenix_document_manager_allowed_mimes()`). Dangerous types — SVG, PHP, HTML, JS, and other executable/script formats — are structurally impossible to enable because they are never present in the map, regardless of what an admin types into the extensions field.

### Size limits
The plugin does not itself impose a file-size limit; it relies on PHP's `upload_max_filesize`/`post_max_size` and WordPress's own upload constraints via `wp_handle_upload()`. Upload failures caused by size (`no_tmp_file`, `upload_handler_error`) are caught, logged, and surfaced with a specific admin notice.

### Security validation on upload
1. Capability check (`Capabilities::user_can_manage()`).
2. Genuine-upload check (`is_uploaded_file()`).
3. Upload-directory writability check.
4. `wp_handle_upload()` with an explicit `mimes` allowlist (`test_form => false`, since these forms aren't the classic media-upload form).
5. Post-hoc extension re-check via `wp_check_filetype()` against the same allowlist; the file is deleted (`wp_delete_file()`/`unlink()`) if it slips through.
6. Attachment creation via `wp_insert_attachment()` (parented to the document) then `wp_generate_attachment_metadata()`.
Every failure is both a distinct `WP_Error` code and an `Event_Log` entry with a specific, user-readable cause (missing temp file, directory not writable, handler error, missing filepath post-processing, disallowed extension, DB insert failure) rather than one generic "upload failed" message.

### Download delivery mechanism
Files are **never served by direct URL to the uploads folder for protected documents** — they go through `Endpoint::maybe_serve_document()`, hooked to `template_redirect`, matched by the rewrite rule `^kitgenix-document-manager/([^/]+)/?$` → `index.php?kitgenix_document_manager_slug=$matches[1]`. Steps:
1. Resolve the document by slug (`get_page_by_path()`), require `post_status === 'publish'`, require a current attachment.
2. Evaluate access (see below); on denial, redirect to login (`auth_redirect()`) or emit 403/404/410 depending on the reason.
3. Resolve the local file path via `get_attached_file()`, verify it exists/is readable, and verify (via `realpath()` prefix comparison against the uploads `basedir`) that it has not escaped the uploads directory (path-traversal guard against poisoned attachment meta).
4. Determine MIME type via a fallback chain: `wp_check_filetype_and_ext()` → stored `post_mime_type` → extension lookup → PHP `mime_content_type()` → `application/octet-stream`.
5. Decide `Content-Disposition` (`inline` vs `attachment`) from the site's `serving_mode` setting, but only allow `inline` for MIME types a browser can reasonably render (`application/pdf`, `image/*`, `text/*`) — anything else is forced to `attachment` even if inline was requested.
6. Set security/caching headers: always `X-Content-Type-Options: nosniff` and `Accept-Ranges: bytes`; protected documents get `nocache_headers()` + `Cache-Control: private, no-store, max-age=0` + `Pragma: no-cache` + `Expires: 0` + `X-Robots-Tag: noindex, nofollow`; public documents get `Cache-Control: public, max-age=0, must-revalidate` plus `Last-Modified`/`ETag` (MD5 of attachment ID + mtime + size) with conditional 304 handling for `If-None-Match`/`If-Modified-Since`.
7. `Content-Disposition` filename is defensively stripped of `"`, CR, LF, with both a sanitized ASCII fallback and an RFC 5987 `filename*=UTF-8''...` value.
8. Single HTTP byte-range support (`resolve_range_request()`): parses `Range: bytes=start-end` (including suffix ranges), honors `If-Range` against the ETag/Last-Modified, rejects multi-range requests (falls back to a full 200 response) and out-of-bounds ranges (416 with `Content-Range: bytes */{size}`), and otherwise returns 206 with `Content-Range`. Range bodies are streamed via `stream_range()` in fixed 1MB chunks (`fopen`/`fseek`/`fread`/`echo`/`flush`) rather than loading the whole file into memory; non-range responses use `readfile()`.
9. `HEAD` requests exit after headers, before any body is sent.
10. After a successful (non-HEAD) response, a signed-request use-counter increment, an analytics event, and a `Lifecycle::maybe_record_transition()` check are performed, then `do_action( 'kitgenix_document_manager_document_served', ... )` fires.

### Access restriction logic

`kitgenix_document_manager_evaluate_document_access()` (in `helpers.php`) is the single source of truth used by the Endpoint, the shortcodes, the REST route, and `Attachment_Guard`. Order of evaluation:
1. **Schedule status** first (`available_from_gmt`/`archive_at_gmt`/`expires_at_gmt`) — a document that is scheduled/archived/expired is denied regardless of visibility, with the schedule's own HTTP status (404 scheduled, 410 archived/expired).
2. `current_user_can( Capabilities::CAPABILITY )` → always allowed (`granted_by: manage`).
3. A **valid signed share-link** on the current request → always allowed (`granted_by: signed_link`), see below.
4. **Public** visibility with no granular rules (no roles/capability/user-IDs configured) → allowed (`granted_by: public`).
5. Otherwise, login is required. If not logged in: `private_behavior` setting decides between a 401 (implying redirect-to-login at the Endpoint) or a 403.
6. If logged in: allowed if the user's ID is in `allowed_user_ids` (`granted_by: user`), or the user has the configured `required_capability` (`granted_by: capability`), or the user has one of the `allowed_roles` (`granted_by: role`).
7. If none matched, the filter `kitgenix_document_manager_user_has_document_access` is consulted (a `null`/non-bool return leaves the decision unchanged).
8. If **still** not allowed but the document has **no granular rules at all** and visibility isn't `public` (i.e. plain "Private"), being logged in is sufficient (`granted_by: login`).
9. Otherwise: forbidden (403).

**Signed share links**: `kitgenix_document_manager_validate_share_link_request()` checks `?kitgenix_dm_share=1&kitgenix_dm_expires=…&kitgenix_dm_sig=…` against an HMAC-SHA256 signature (`hash_hmac( 'sha256', "{post_id}|{attachment_id}|{slug}|{expires_ts}", wp_salt('auth') . '|' . per_document_secret )`, compared with `hash_equals()`), the document's currently-stored expiry (so an older link becomes invalid the moment a new expiry/secret is set — "secret_rotated"), and an optional max-use counter (`_kitgenix_document_manager_share_max_uses` / `..._share_use_count`, incremented via a direct atomic `UPDATE ... SET meta_value = meta_value + 1` SQL statement to reduce race conditions under concurrent downloads). Distinguishable rejection reasons (`link_missing_flag`, `link_expired`, `secret_rotated`, `signature_mismatch`, `link_use_limit_reached`) are logged with different severities (ordinary vs genuine-security-concern) via `Event_Log::get_category_and_note()`.
- "Revoke" (`kitgenix_document_manager_revoke_document_share_link()`) clears the expiry and use-counter, instantly invalidating the current link without touching the secret.
- "Regenerate" (`kitgenix_document_manager_regenerate_document_share_secret()`) deletes and re-derives the per-document secret plus resets the use-counter, invalidating **every** previously issued link for that document (even unexpired ones), because the signature depends on the secret.

## Hooks Reference

### Actions/filters the plugin hooks INTO (selected — not exhaustive of every WordPress core hook, but covers all plugin-registered callbacks)

| Hook | Type | Registered by | Purpose |
|---|---|---|---|
| `admin_menu` (priority 5) | action | main file | `kitgenix_ensure_admin_menu()` — shared Kitgenix top-level menu |
| `admin_menu` (priority 55) | action | `Settings_UI` | Register the Document Manager submenu (`Admin_Menu::init()` is defined but never called — dead code, see Key Classes table) |
| `admin_enqueue_scripts` (priority 5, and default) | action | main file, `Script_Handler` | Hub/admin-ui CSS registration; plugin admin CSS/JS enqueue |
| `wp_enqueue_scripts` | action | `Script_Handler` | Public CSS enqueue (conditional on shortcode presence) |
| `plugins_loaded` | action | main file | Core bootstrap |
| `init` | action | `CPT`, `Endpoint` | Register post type/taxonomy; register rewrite rules |
| `query_vars` | filter | `Endpoint` | Add `kitgenix_document_manager_slug` |
| `template_redirect` (priority 1 for guard, default for endpoint) | action | `Attachment_Guard`, `Endpoint` | Block attachment permalinks; serve stable-link documents |
| `rest_api_init` | action | `Rest` | Register the metadata REST route |
| `rest_request_before_callbacks` | filter | `Attachment_Guard` | Short-circuit single `/wp/v2/media/{id}` GET requests |
| `rest_attachment_query` | filter | `Attachment_Guard` | Exclude inaccessible attachments from media collection queries |
| `rest_prepare_attachment` | filter | `Attachment_Guard` | Strip `source_url`/`media_details`/etc. from a slipped-through response |
| `add_meta_boxes` | action | `Document_Metabox` | Register File/Visibility metaboxes |
| `save_post` | action | `Document_Metabox` | Persist visibility/access/schedule meta from the native editor |
| `admin_post_kitgenix_document_manager_upload_initial` / `..._replace_file` | action | `Document_Metabox` | Native-editor file upload/replace |
| `admin_notices` | action | `Document_Metabox`, `Settings_UI` | Notices for native editor and custom admin app |
| `admin_init` | action | `Admin_Options` | `register_setting()` |
| `manage_kitgenix_dm_doc_posts_columns` / `manage_kitgenix_dm_doc_posts_custom_column` / `post_row_actions` | filter/action | `Admin_Columns` | Native list-table columns/actions |
| `site_status_tests` | filter | `Site_Health` | Uploads-directory health test |
| `shortcode_atts` usage / `add_shortcode` | — | `Shortcode` | See Shortcodes section |
| `admin_post_kitgenix_document_manager_document_save` / `_delete` / `_documents_bulk_action` / `_documents_bulk_upload` / `_version_restore` / `_version_delete` / `_versions_delete_all` / `_category_save` / `_category_delete` / `_share_revoke` / `_share_regenerate` / `_export_settings` / `_import_settings` / `_clear_event_log` / `_save_log_retention` | action | `Settings_UI::init()` | See AJAX/REST/Admin sections |
| `wp_ajax_kitgenix_dm_search_docs` | action | `Settings_UI::init()` | Shortcode-builder document search |
| `update_option_kitgenix_document_manager_settings` | action | `Settings_UI::init()` | Logs a "settings-saved" event whenever the option is updated (any path, including import) |
| `kitgenix_document_manager_lifecycle_check` | action (cron) | `Lifecycle::init()` | Hourly lifecycle sweep |
| `kitgenix_document_manager_prune_event_log` | action (cron) | `Event_Log::init()` | Daily log pruning |

### Custom actions/filters the plugin FIRES for other code to use

| Hook | Type | Fired in | Signature / purpose |
|---|---|---|---|
| `kitgenix_document_manager_user_has_document_access` | filter | `helpers.php` → `kitgenix_document_manager_evaluate_document_access()` | `apply_filters( $hook, null, int $post_id, array $context )` → return `true`/`false` to override the built-in role/capability/user decision (only consulted after the built-in checks fail); `$context` includes `visibility`, `allowed_roles`, `required_capability`, `allowed_user_ids`, `surface` (`delivery`\|`listing`), `schedule`. |
| `kitgenix_document_manager_share_link_url` | filter | `helpers.php` → `..._get_document_signed_share_url()` | `apply_filters( $hook, string $url, int $post_id, int $expires_timestamp )` — customize a generated signed share URL. |
| `kitgenix_document_manager_rest_document_response` | filter | `class-rest.php` → `Rest::get_doc()` | `apply_filters( $hook, array $data, WP_Post $post, array $access, WP_REST_Request $request )` — customize an already-allowed REST metadata payload. |
| `kitgenix_document_manager_document_served` | action | `class-endpoint.php` → `Endpoint::maybe_serve_document()` | `do_action( $hook, int $document_id, int $attachment_id, string $event_type, array $access )` — fires after a file has been streamed (or a HEAD request answered). `$event_type` is `view` or `download`. |
| `kitgenix_document_manager_lifecycle_status_changed` | action | `class-lifecycle.php` → `Lifecycle::maybe_record_transition()` | `do_action( $hook, int $document_id, string $old_state, string $new_state )` — fires the first time an already-observed lifecycle state changes (never fires on first observation, to avoid noise on install/migration). |

## Shortcodes / Blocks / Widgets

No Gutenberg blocks and no widgets are registered — the plugin is shortcode-only.

### `[kitgenix_document_manager_link]`
Renders a plain `<a>` tag pointing at the document's stable URL, or an empty string if the slug is missing, not found, unpublished, or access-denied for the current viewer (a manager-capable viewer instead sees a small inline "not visible to other visitors" note referencing the lifecycle state — the shortcode never silently reveals a protected document's existence to a non-manager).

| Attribute | Default | Purpose |
|---|---|---|
| `slug` | `''` (required) | Document slug to link to. |
| `label` | document title | Link text; falls back to `get_the_title()` if empty. |

### `[kitgenix_document_manager_document]`
Renders one or more document "cards" (thumbnail/icon, title, optional description, optional file-type/size metadata rows, and an action button linking to the stable URL). Every visual default comes from the **Frontend settings** (see Admin Interface) unless overridden per-shortcode. Protected/unavailable documents are silently skipped for ordinary visitors (same manager-only inline notice as the link shortcode).

| Attribute | Default | Purpose |
|---|---|---|
| `slug` | `''` | Single document slug. |
| `slugs` | `''` | Comma-separated list of slugs — renders a grid (`kitgenix-document-manager-cards`) when more than one card is produced; overrides `slug` if both are given. |
| `button_label` | Frontend setting `card_button_label` (else "View") | Button text. |
| `button_style` | Frontend setting `card_button_style` | `outline` or `solid` (any other value is treated as `outline`). |
| `image_size` | Frontend setting `card_image_size` | Thumbnail/icon CSS size in px, clamped 32–256. |
| `meta_align` | Frontend setting `card_meta_alignment` | `justify`\|`left`\|`center`\|`right` for the file-type/size rows. |
| `button_align` | Frontend setting `card_button_alignment` | `justify`\|`left`\|`center`\|`right`. |
| `new_tab` | Frontend setting `card_open_new_tab` | Truthy strings (`1`, `true`, `yes`, `y`, `on`) add `target="_blank" rel="noopener noreferrer"`. |
| `show_type` | Frontend setting `card_show_file_type` | Show the file-extension/MIME "File Type" row. |
| `show_size` | Frontend setting `card_show_file_size` | Show the "File Size" row (human-readable via `size_format()`). |
| `show_description` | Frontend setting `card_show_description` | Show the document's description meta (whitespace-collapsed, escaped). |

Card button colors (text/background/border + hover variants) are pulled only from the Frontend settings (not shortcode-overridable) and emitted as inline CSS custom properties (`--kitgenix-document-manager-btn-*`) on the card element. Preview image resolution: `wp_get_attachment_image( …, 'thumbnail' )` → falls back to `wp_mime_type_icon()` → falls back to a plain placeholder `<div>`.

## Data Storage

### Custom database tables
**None.** The plugin uses only WordPress's own `wp_posts` (via the `kitgenix_dm_doc` CPT and its attachments), `wp_postmeta`, `wp_terms`/`wp_term_taxonomy`/`wp_term_relationships` (via `kitgenix_dm_cat`), and `wp_options`. The one direct SQL statement in the codebase is a single atomic `UPDATE wp_postmeta SET meta_value = meta_value + 1 WHERE …` for the share-link use counter (`kitgenix_document_manager_increment_document_share_use_count()`), executed through `$wpdb->prepare()`.

### Options (`wp_options`)

| Option name | Autoload | Purpose |
|---|---|---|
| `kitgenix_document_manager_settings` | default (register_setting) | Main settings array (see field tables in Admin Interface). |
| `kitgenix_document_manager_db_version` | `false` | Data-shape version used by the idempotent upgrade routine. |
| `kitgenix_document_manager_event_log` | `false` | Array of up to 100 `{time, context, outcome, note, code}` entries. |
| `kitgenix_document_manager_log_retention_days` | `false` | One of `7`/`14`/`30`; default `30`. |
| `kitgenix_hub_wporg_active_installs_v1` (transient) | — | Cached `plugins_api()` install counts for the Hub page, 1-day TTL. |
| `kitgenix_hub_wporg_ratings_v1` (transient) | — | Cached `plugins_api()` ratings for the Hub page, 1-day TTL. |
| `kitgenix_hub_wporg_media_v1` (transient) | — | Cached `plugins_api()` banner/icon URLs for the Hub page, 1-day TTL. |
| `kitgenix_document_manager_site_health` (transient) | — | Referenced/deleted on uninstall; not observed being set elsewhere in the read code (likely reserved/legacy). |

### Document postmeta keys (all on `kitgenix_dm_doc` posts, prefixed `_kitgenix_document_manager_`)

| Meta key (suffix shown, prefix `_kitgenix_document_manager_`) | Purpose |
|---|---|
| `current_attachment_id` | ID of the attachment currently served as "the file". |
| `versions` | Array of historical attachment IDs (includes the current one when versioning is on). |
| `version_meta` | Map of `attachment_id => {label, note}` for version annotations. |
| `visibility` | `public`\|`private`\|`restricted`. |
| `description` | Free-text description shown by the card shortcode / used in admin search. |
| `access_roles` | Array of allowed WordPress role slugs. |
| `access_capability` | A single required WordPress capability string. |
| `access_user_ids` | Array of allowed WordPress user IDs. |
| `versioning_enabled` | `1`/`0` — per-document override of the site default. |
| `share_secret` | Per-document HMAC signing secret (auto-generated, 40 chars, `wp_generate_password()`). |
| `share_expires_gmt` | `Y-m-d H:i:s` GMT expiry of the current signed link (absence = sharing off). |
| `share_max_uses` | Optional integer cap on signed-link uses (0/absent = unlimited). |
| `share_use_count` | Current use count for the signed link. |
| `available_from_gmt` / `archive_at_gmt` / `expires_at_gmt` | Lifecycle schedule timestamps (GMT, `Y-m-d H:i:s`). |
| `last_lifecycle_state` | Last lifecycle state observed by the `Lifecycle` cron (used to detect transitions). |
| `analytics` | `{views, downloads, signed_views, signed_downloads, last_access_gmt, last_access_type, daily:{Y-m-d:{...}}}`. |

No custom **usermeta** keys are used anywhere in the codebase.

### Attachment relationship
Both the "current" attachment and every historical version attachment are ordinary `attachment` posts with `post_parent` set to the document's post ID — this parent/child relationship is itself used as the authoritative link back to the owning document (`kitgenix_document_manager_attachment_document_id()`), which is how `Attachment_Guard` and the uninstall routine locate a document's files without a separate index.

### Import/Export payload
`handle_export_settings()` produces a JSON file (`kitgenix-document-manager-settings.json`) containing `{plugin, schema, exported, settings, categories:[{name,slug,parent}]}`. Deliberately **excluded**: documents, uploaded files, per-document access rules, signed-link secrets, and analytics — so import/export cannot be used to accidentally leak private content or restore access control that should be re-reviewed per-site.

## AJAX / REST Endpoints

### `wp_ajax_kitgenix_dm_search_docs` (admin-only AJAX)
- Method: GET (via `admin-ajax.php?action=kitgenix_dm_search_docs`).
- Permission: `Capabilities::user_can_manage()` (403 JSON error otherwise).
- Nonce: `nonce` GET param verified against `kitgenix_dm_search_docs` (localized to the page as `kitgenixDMAdmin.nonce`).
- Params: `q` (search string; empty → empty result set).
- Behavior: `get_posts()` on `kitgenix_dm_doc`, `post_status=publish`, `s=$q`, limited to 20, ordered by title.
- Response: `wp_send_json_success( { items: [ { slug, title }, ... ] } )`. Used only by the Shortcode Builder's live search.

### REST route
`GET /wp-json/kitgenix-document-manager/v1/doc/{slug}` (`Rest::register_routes()`):
- `permission_callback` is `__return_true` — **the route is public**; access control is enforced *inside* the callback via `kitgenix_document_manager_evaluate_document_access( ..., ['surface' => 'listing'] )`, not by REST permissions.
- `slug` arg: required, sanitized with `sanitize_title_with_dashes`.
- On not-found or access-denied: `WP_REST_Response` with `Cache-Control: private, no-store, max-age=0` and `{code: 'not_found'|<access reason>, status?: <schedule status>}` at the matching HTTP status (404/401/403/410 as decided by the access evaluation).
- On success: `{slug, title, updated (ISO 8601), visibility, schedule_status, lifecycle_state, stable_url}`, filterable via `kitgenix_document_manager_rest_document_response`. The actual check (`Rest::get_doc()`) is `visibility !== 'public' || is_signed_request`: public documents are cacheable by intermediaries; anything reached via a signed link, or whose visibility meta isn't literally `public`, gets the same `private, no-store` header as the error case. (A document whose visibility is `public` but that also carries granular role/capability/user rules — not producible through the admin UI, but possible by editing the meta directly — would not get the `no-store` header even though access was actually granted by a granular rule, since the check only inspects visibility and `is_signed_request`, not how access was granted.)

### `admin-post.php` actions (all require `Capabilities::user_can_manage()` unless noted; all verify a dedicated `wp_nonce_field()`/`wp_verify_nonce()` or `check_admin_referer()` pair)

| Action | Handler | Method | Notes |
|---|---|---|---|
| `kitgenix_document_manager_upload_initial` | `Document_Metabox::handle_upload_initial` | POST (native editor) | Initial file upload for a document with no current file yet. |
| `kitgenix_document_manager_replace_file` | `Document_Metabox::handle_replace_file` | POST (native editor) | Replace the current file (version bookkeeping per settings). |
| `kitgenix_document_manager_document_save` | `Settings_UI::handle_document_save` | POST (custom UI Add/Edit form & modal) | Create/update a document: title, slug, description, visibility, access rules, share expiry/max-uses, schedule dates (with ordering validation: archive/expiry must be after available-from), category, and either a new file upload or an existing Media Library selection. 4-byte-character (emoji) title guard for non-utf8mb4 databases. |
| `kitgenix_document_manager_document_delete` | `Settings_UI::handle_document_delete` | GET (nonce'd link) | Moves a document to Trash (`wp_trash_post`) — does not delete attachments. |
| `kitgenix_document_manager_documents_bulk_action` | `Settings_UI::handle_documents_bulk_action` | POST | See Features/Admin Interface for the 8 supported actions. |
| `kitgenix_document_manager_documents_bulk_upload` | `Settings_UI::handle_documents_bulk_upload` | POST (multipart) | Batch-creates one document per uploaded file. |
| `kitgenix_document_manager_version_restore` | `Settings_UI::handle_version_restore` | GET | Sets an existing version attachment back to "current". |
| `kitgenix_document_manager_version_delete` | `Settings_UI::handle_version_delete` | GET | Deletes one non-current version attachment. |
| `kitgenix_document_manager_versions_delete_all` | `Settings_UI::handle_versions_delete_all` | GET | Deletes all non-current version attachments for a document. |
| `kitgenix_document_manager_category_save` | `Settings_UI::handle_category_save` | POST | Create/update a `kitgenix_dm_cat` term. |
| `kitgenix_document_manager_category_delete` | `Settings_UI::handle_category_delete` | GET | Delete a term. |
| `kitgenix_document_manager_share_revoke` | `Settings_UI::handle_share_revoke` | GET | Invalidate the current signed link (keep the secret). |
| `kitgenix_document_manager_share_regenerate` | `Settings_UI::handle_share_regenerate` | GET | Rotate the signing secret (invalidate every previously issued link). |
| `kitgenix_document_manager_export_settings` | `Settings_UI::handle_export_settings` | GET | Requires `manage_options` specifically (not just `user_can_manage()`); streams a JSON download. |
| `kitgenix_document_manager_import_settings` | `Settings_UI::handle_import_settings` | POST (multipart) | Requires `manage_options`; validates the `plugin` field in the uploaded JSON before applying. |
| `kitgenix_document_manager_clear_event_log` | `Settings_UI::handle_clear_event_log` | GET | Requires `manage_options`; empties the log option. |
| `kitgenix_document_manager_save_log_retention` | `Settings_UI::handle_save_log_retention` | POST | Requires `manage_options`; clamps to 7/14/30. |

## Cron / Scheduled Events

| Hook | Recurrence | Scheduled by | Callback | Purpose |
|---|---|---|---|---|
| `kitgenix_document_manager_lifecycle_check` | `hourly` (first run `time() + HOUR_IN_SECONDS`) | `Lifecycle::schedule()` (called on activation and on every upgrade-routine run) | `Lifecycle::run_check()` | Queries up to 500 published documents that have any schedule meta key set, and for each calls `maybe_record_transition()` to detect/announce a lifecycle-state change even if nobody visits the document's link. |
| `kitgenix_document_manager_prune_event_log` | `daily` (first run `time() + DAY_IN_SECONDS`) | `Event_Log::schedule()` (same triggers as above) | `Event_Log::prune()` | Removes log entries older than the configured retention window (also applied inline on every `Event_Log::record()` call, so this is belt-and-braces). |

Both are unscheduled (`wp_unschedule_event()` + `wp_clear_scheduled_hook()`) on plugin deactivation, and the lifecycle hook is additionally cleared during `uninstall.php` (the prune hook is not explicitly cleared in `uninstall.php`, but deactivation already removes it before uninstall can run, since WordPress requires deactivation before deletion).

## Frontend Behavior

There is no plugin-owned frontend template, archive, or single-document page — a document only ever appears on the frontend via:
1. The **stable link** itself (`/kitgenix-document-manager/{slug}/`), which `Endpoint` intercepts on `template_redirect` and answers directly (streams the file or an error status) — no theme template is ever rendered for this URL.
2. The two **shortcodes**, embedded by an editor into any post/page content, rendering inline HTML (a link or one/many cards).

`Script_Handler::enqueue_public()` only registers/enqueues `assets/css/public.css`, and only enqueues it (vs. merely registering it) when the current request `is_singular()` and the post's `post_content` contains the `kitgenix_document_manager_document` shortcode (`has_shortcode()`). The `Shortcode` class itself also defensively calls `ensure_public_styles()` before rendering a card, in case the shortcode appears somewhere `Script_Handler`'s singular/content check wouldn't catch (e.g., injected via a widget or block that isn't the literal post content). There is no dedicated public JS (`public.js` is an empty stub) — the card/link markup is static HTML with no client-side behavior.

## Assets

| File | Enqueued on | Purpose |
|---|---|---|
| `assets/css/admin.css` | Plugin settings screen only (`Script_Handler::enqueue_admin`) | Plugin-specific admin layout: page wrapper, 2-column grid, documents sidebar, muted-text helper, etc. Builds on top of `kitgenix-admin-ui.css`. |
| `assets/css/kitgenix-admin-ui.css` | Every `is_admin()` request (registered at `admin_enqueue_scripts` priority 5 in the main file) + explicitly enqueued on the settings/editor screens | The shared Kitgenix design-system stylesheet: topbar, tab menus, cards, tables, forms, toggle switches, modals, badges, color tokens, light/dark theme rules. Imports Google Fonts (Inter, Manrope). Shared verbatim across Kitgenix plugins. |
| `assets/css/kitgenix-hub.css` | Only on `page=kitgenix` / `toplevel_page_kitgenix` (main file's `admin_enqueue_scripts` closure) | Styling for the shared Hub/landing page (plugin cards grid, badges, social links). |
| `assets/css/public.css` | Frontend, conditionally (see Frontend Behavior) | Document card/grid styling (`kitgenix-document-manager-card*`, `kitgenix-document-manager-cards`), CSS custom properties for thumbnail size and button colors, responsive breakpoint at 768px. |
| `assets/js/admin.js` | Plugin settings screen and the native document editor screen (`Script_Handler::enqueue_admin`) | Plugin-specific behavior: tab activation via hash (`bindTabs`, largely superseded by the shared `kitgenix-admin-tabs.js` controller but kept for the native-editor case), auto-slug from title, the Edit-document modal (open/populate/close, version list rendering, share-link controls, analytics display), the Media Library picker (`wp.media`), the Category modal, copy-to-clipboard, the Shortcode Builder (admin-ajax search + live shortcode string builder), Access-preset quick-fill, Documents-table search/filter, bulk-action toolbar state, and the batch-upload dropzone. Localized data: `kitgenixDMAdmin = { ajaxUrl, nonce }`. |
| `assets/js/kitgenix-admin-tabs.js` | Same screens, loaded before `admin.js`-equivalent files across all Kitgenix plugins | Shared: URL/hash-synced tab switching, in-page settings search filtering visible rows/cards, a mobile hamburger menu, topbar dropdown menus, a search-panel toggle, a light/dark theme toggle persisted in `localStorage`, WP admin-notice relocation/normalization (`normalizeNotices`/`armNoticeObserver`), and a sticky-topbar offset calculator. |
| `assets/js/kitgenix-admin-components.js` | Same screens | Shared: toast notifications, generic modal open/close/focus-trap helpers, collapsible sections, copy-to-clipboard, and generic table search + client-side pagination helpers (`data-kitgenix-table-search` / `data-kitgenix-table-paginate`). |
| `assets/js/public.js` | Not actually enqueued anywhere in the read code | Header-comment-only placeholder; contains no functional JavaScript. |

## Internationalization

- Text domain: `kitgenix-document-manager` (declared in the plugin header; every translatable string in the codebase consistently uses this domain).
- Domain Path: `/languages`.
- `languages/kitgenix-document-manager.pot` is present (generated via WP-CLI 2.12.0, `POT-Creation-Date: 2026-08-30`). **No `.po` or `.mo` files are shipped** — translations rely on WordPress.org's own translation infrastructure for the hosted plugin.
- The plugin does **not** call `load_plugin_textdomain()`; a code comment states this is intentional/unnecessary for a WordPress.org-hosted plugin whose translations load automatically.
- Translator comments (`/* translators: ... */`) are used correctly ahead of every `sprintf()`/`_n()` call that takes a placeholder.

## Security

### Nonce usage
Every state-changing `admin-post.php` handler verifies a nonce — either a form-embedded `wp_nonce_field()` checked with `wp_verify_nonce()` (document save/bulk-action/bulk-upload/category-save forms), a `wp_nonce_url()`-generated link checked the same way (delete/version-restore/version-delete/versions-delete-all/category-delete/share-revoke/share-regenerate), or the simpler `check_admin_referer()` helper (export/import/clear-log/save-log-retention). The AJAX search handler verifies a dedicated `kitgenix_dm_search_docs` nonce passed as a GET param. The native-editor metabox save (`save_post`) verifies `kitgenix_document_manager_save_meta` before touching `$_POST`.

### Capability checks
- Plugin-wide gate: custom capability `manage_kitgenix_document_manager_documents`, granted to Administrator and (if WooCommerce is active) Shop Manager on activation/every admin bootstrap (`Capabilities::add_caps()` also runs on every `plugins_loaded` while `is_admin()`, so it self-heals after updates).
- `Capabilities::user_can_manage()` additionally accepts plain `manage_options`, and `manage_woocommerce` when WooCommerce is active — used as the gate on almost every admin-post/ajax handler and on metabox rendering.
- Export/Import/Clear-log/Save-log-retention specifically require `manage_options` (not the broader `user_can_manage()`), a deliberately tighter bar for data-portability and log-management actions.
- The CPT's own `map_meta_cap` capabilities all resolve to the same custom capability, so even a direct `edit.php?post_type=kitgenix_dm_doc` visit is gated consistently.

### Sanitization / escaping patterns
- Consistent use of `sanitize_text_field()`, `sanitize_key()`, `sanitize_title_with_dashes()`, `sanitize_hex_color()`, `sanitize_textarea_field()`, `absint()`/`(int)` casts, and dedicated helper sanitizers (`kitgenix_document_manager_sanitize_roles()`, `..._sanitize_user_ids()`, `..._normalize_extensions()`, `..._normalize_visibility()`).
- All `$_GET`/`$_POST`/`$_FILES` superglobal reads are wrapped in `isset()` + `wp_unslash()` + a sanitizer, generally with an inline `phpcs:ignore` comment explaining why the raw superglobal access is safe at that point (nonce already verified, or a wrapper class is doing the sanitizing).
- Output escaping: `esc_html()`/`esc_html__()`/`esc_html_e()`, `esc_attr()`/`esc_attr__()`, `esc_url()`, `esc_js()`, `wp_kses()`/`wp_kses_post()` with explicit allowed-tag lists for any admin-composed HTML (Hub page badges/actions), and `wp_json_encode()` for the version-history JSON blob embedded as a `data-versions` attribute.
- The one place raw output is intentionally unescaped is streamed **binary file content** in `Endpoint::stream_range()`/`readfile()` (explicitly commented `phpcs:ignore ... raw binary file content`) and a handful of hand-authored, constant inline SVG icon strings (also explicitly commented as not user input).

### File upload security specifically
See File Handling above: fixed extension→MIME allowlist intersected at both settings-save time and upload time, post-upload re-validation with deletion on failure, dedicated upload subdirectory with `.htaccess`/`web.config`/`index.php` hardening, and `Attachment_Guard`'s REST/permalink blocking for files that live outside that directory (Media-Library-selected files).

### Other notable hardening
- Path-traversal check on every stable-link request (`realpath()` prefix comparison against the uploads basedir) before streaming.
- `Content-Disposition` filename values are stripped of quote/CR/LF characters before being placed in a header.
- REST metadata responses for anything non-public are marked `Cache-Control: private, no-store` so intermediary caches/CDNs never retain them.
- Signed-link signatures are compared with `hash_equals()` (timing-safe), not `===`.
- The plugin explicitly documents (in `readme.txt`) that Media-Library-selected files are **not** fully hidden — their original uploads URL may still be directly reachable depending on server configuration, since the plugin does not move them.

## Uninstall / Cleanup

### Deactivation (`register_deactivation_hook`)
Unschedules both cron hooks (`kitgenix_document_manager_lifecycle_check`, `kitgenix_document_manager_prune_event_log`) and does a soft `flush_rewrite_rules( false )`. **No data is deleted.**

### Uninstall (`uninstall.php`, guarded by `defined( 'WP_UNINSTALL_PLUGIN' )`)
Always performed, regardless of the `delete_data_on_uninstall` setting:
- Deletes the options `kitgenix_document_manager_settings`, `kitgenix_document_manager_event_log`, `kitgenix_document_manager_db_version`.
- Deletes the transient `kitgenix_document_manager_site_health`.
- Unschedules the lifecycle cron event/hook.

Conditionally, **only if** `delete_data_on_uninstall` was `true` in the (already-read) settings array:
- Every `kitgenix_dm_doc` post (any status, via `get_posts( post_status => 'any' )`) has its current attachment (`wp_delete_attachment( $id, true )`, force-delete — removes the file from disk too) and every historical version attachment deleted, then the document post itself is force-deleted (`wp_delete_post( $id, true )`).

If `delete_data_on_uninstall` is left at its default (`false`), **documents, their files, and version history all survive uninstallation** — only plugin configuration/log data is removed. This matches the FAQ text in `readme.txt`. Analytics data (stored in document postmeta) is only removed as a byproduct of the document itself being deleted in the opt-in path; there is no separate analytics-only cleanup toggle.

The Hub-page transients (`kitgenix_hub_wporg_active_installs_v1`, `..._ratings_v1`, `..._media_v1`) are **not** cleaned up by this plugin's uninstall routine (they are shared/generic Hub caching, not document-manager-specific state, and will simply expire on their own 1-day TTL).

## Known Limitations / TODOs

No `TODO`/`FIXME`/`XXX` comments were found anywhere in the codebase — the code is written in a "finished feature, documented limitation" style instead. Limitations that are explicitly acknowledged in code comments or the readme:

- **Nginx is not covered** by the upload-directory protection files (`.htaccess`/`web.config` are Apache/IIS-only); an Nginx site must add an equivalent `location` rule manually, since a WordPress plugin cannot write Nginx server configuration.
- **Media-Library-selected files are not moved or fully hidden.** Their original uploads URL may remain directly reachable depending on server configuration; only REST/attachment-permalink exposure is actively blocked (`Attachment_Guard`), not the raw file URL itself.
- **`languages/kitgenix-document-manager.pot`'s `Project-Id-Version` may lag the plugin's actual version** — check this against the current `KITGENIX_DOCUMENT_MANAGER_VERSION` constant when translating, since it is not automatically regenerated on every release.
- **The Documents-tab table is capped at 200 documents** (`numberposts => 200` in `render_documents_tab()`) with purely client-side search/filter/no pagination beyond that cap — a site with more than 200 published documents will not see all of them listed in the admin table (though every document remains individually reachable by its stable link/shortcode).
- **The Attachment_Guard "inaccessible IDs" query is bounded to 2000 posts** (`posts_per_page => 2000` in `get_inaccessible_document_ids_for_viewer()`) and computed per-request (static cache only, not persisted) — a very large library with many non-public documents could see this guard become a non-trivial per-request cost on REST media collection queries.
- **The share-link use-count increment is "best-effort atomic"**, not fully race-proof: the code comment explicitly states the direct SQL increment "reduces (not eliminates)" the race window under concurrent downloads of the same signed link, so a max-use limit could theoretically be exceeded by a handful of simultaneous requests.
- **`assets/js/public.js` is an empty stub** (comment header only) and is not enqueued by any code path found — it appears to be a placeholder kept for parity with other Kitgenix plugins' asset layout rather than an oversight, but it currently does nothing.
- **No WP-CLI commands, no Gutenberg blocks, no widgets** are provided — shortcodes are the only frontend integration surface.
- **No REST write endpoints** — the REST API is read-only metadata (`GET /doc/{slug}`); all document management is admin-UI/admin-post only.
- The plugin's own native CPT list table (`edit.php?post_type=kitgenix_dm_doc`) is fully wired up (`Admin_Columns`, `Document_Metabox`) but is **not exposed in any WordPress admin menu** (`show_in_menu => false`), so it is only reachable via a direct/edit link generated by the plugin itself (e.g. "Edit" from the custom Documents table) — this is evidently intentional (the custom `Settings_UI` screen is the designed entry point) but worth noting for anyone expecting a standard post-type submenu.
