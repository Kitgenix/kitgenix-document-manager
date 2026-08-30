=== Kitgenix Document Manager ===
Contributors: kitgenix
Donate link: https://www.paypal.com/donate/?hosted_button_id=KALF36K6JJ9B2
Tags: document management, file manager, pdf, downloads, access control, file sharing
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 2.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Plugin URI: https://wordpress.org/plugins/kitgenix-document-manager/
Author: Kitgenix
Author URI: https://kitgenix.com/
Author Plugin URI: https://kitgenix.com/plugins/kitgenix-document-manager
Documentation URI: https://kitgenix.com/plugins/kitgenix-document-manager/documentation
Support URI: https://wordpress.org/support/plugin/kitgenix-document-manager/
Author Support URI: https://kitgenix.com/plugins/kitgenix-document-manager/support
Feature Request URI: https://kitgenix.com/plugins/kitgenix-document-manager/feature-request

Document management with stable, unbreakable links, granular access control, scheduling, and usage analytics.

== Description ==

Kitgenix Document Manager gives you a dedicated library for PDFs, Office files, and other documents, separate from the standard Media Library, with a permanent link for every file.

Upload a document and it gets a stable URL such as:

`/kitgenix-document-manager/{slug}/`

Replace the underlying file later – a new policy version, an updated price list, a corrected report – and the link never changes. Anyone who already has the URL, bookmarked it, or received it in an email keeps working. There are no broken links and nothing to re-share.

This is built for site owners, membership sites, HR and compliance teams, and any WordPress site that needs to publish or restrict downloadable files without relying on plain Media Library links that break the moment a file is replaced.

= Key Features =

* **Stable links** – replace a file at any time without changing its public URL
* **Visibility controls** – set each document to Public, Private, or Restricted
* **Granular access rules** – restrict a document by WordPress role, capability, or specific user IDs
* **Signed share links** – generate an expiring URL so people without a WordPress account can access a document, with an optional maximum-use count, one-click revoke, and a secret-rotation "Regenerate" action
* **Lifecycle scheduling** – set go-live, archive, and expiry dates so a document publishes and retires automatically, enforced on the stable link, shortcodes, and REST endpoint alike
* **Usage analytics** – per-document view/download counts, signed-share activity, last-access timestamps, and 30-day rollups
* **Version history (optional)** – keep, restore, or delete older versions of a file, with an optional label/note and a record of who replaced it and when
* **Document categories** – group documents into a hierarchical taxonomy for easier organisation
* **Batch upload** – drag and drop multiple files at once to create document entries with shared visibility, category, and versioning defaults
* **Bulk actions** – update visibility, recategorise, archive, clear a schedule, or move several documents to Trash in one step
* **Media Library integration** – attach an existing Media Library file to a document instead of uploading a new one
* **REST API** – fetch a document's stable metadata (title, visibility, lifecycle state, URL) by slug
* **Site Health integration** – a built-in check confirms the plugin's uploads directory exists and is writable

= Managing Documents =

Everything happens under **Kitgenix → Document Manager**, in a tabbed admin screen: Documents, Categories, Versions, Settings, Frontend, Log, and Support.

* The Documents table shows every document with its stable link, current version, file type/size, and visibility, plus search and Status/Visibility/File Type filters
* A unified lifecycle badge (Draft, Unavailable, Scheduled, Live, Restricted, Archived, or Expired) shows each document's effective state at a glance
* A quick edit modal lets you update visibility, access rules, scheduling, and versioning without leaving the table
* "Copy shortcode" and "Copy link" actions let editors grab the right embed code or URL without opening another screen
* An access-preset control (Public / Logged-in Users / Administrators Only) quickly fills in the role, capability, and user-ID fields, which can still be adjusted before saving
* Settings and categories (not documents, files, or analytics) can be exported to and imported from a JSON file, for moving configuration between sites
* The Log tab records admin actions and delivery events in a searchable, paginated table, with a one-click "Clear log" action

= Displaying Documents on Your Site =

Two shortcodes are available for embedding documents in posts, pages, or widgets:

* `[kitgenix_document_manager_link slug="my-doc"]` – a simple text link to the stable URL. Add `label="Download"` to set custom link text; otherwise the document title is used.
* `[kitgenix_document_manager_document slug="my-doc"]` – a document card with a preview thumbnail, title, optional description, file type/size, and a button linking to the stable URL. Use `slugs="doc-a,doc-b,doc-c"` instead of `slug` to render several cards together.

Card attributes include `button_label`, `button_style` (outline or solid), `image_size`, `meta_align`, `button_align`, `show_description`, `new_tab`, `show_type`, and `show_size` – all of which also have site-wide defaults under the **Frontend** settings tab, along with button colour controls. A built-in Shortcode Builder with document search generates the correct shortcode for you.

Documents a visitor cannot access render nothing on the front end, so a private or restricted document's existence is never disclosed to the public. Editors who can manage documents see a small inline note instead, explaining why.

Note: inline viewing works best for PDFs, images, and plain text. Office documents (DOCX, XLSX, etc.) typically download rather than open in the browser, because most browsers cannot render them natively.

= Access Control & Security =

* Visibility is set per document as Public, Private, or Restricted, with optional role, capability, and specific-user restrictions layered on top
* Private-document behaviour is configurable: redirect visitors to log in, or return a 403 response
* Signed share links grant temporary access to a document without requiring a WordPress account. Each link can have an optional maximum-use count and can be revoked or regenerated (invalidating all previously issued links) at any time
* The signed-link signature is checked using a constant-time comparison (`hash_equals()`) and separately validates expiry and use-count before granting access
* A document's underlying file cannot be reached through WordPress's own REST media endpoints (`/wp/v2/media/{id}`) or its attachment permalink when the document is Private, Restricted, scheduled, archived, or expired – this applies whether the file was uploaded through the plugin or selected from the existing Media Library
* Private, Restricted, and signed-share responses are never cached (`Cache-Control: private, no-store`); Public documents use conditional caching (ETag/Last-Modified, including 304 responses) so browsers and CDNs can revalidate efficiently
* HTTP Range requests are supported for resuming downloads and seeking within large PDFs or media files
* The plugin's dedicated uploads subfolder receives an `.htaccess` (Apache), `web.config` (IIS), and empty `index.php` to block direct web requests; files can also stream in fixed-size chunks from disk rather than being loaded entirely into memory
* Uploads are restricted to a configurable, audited allow-list of file extensions (PDF, Word, Excel, PowerPoint, and plain text by default); dangerous formats such as SVG and PHP are not permitted

= Developer Features =

* REST endpoint: `GET /wp-json/kitgenix-document-manager/v1/doc/{slug}` returns slug, title, last-updated time, visibility, schedule status, lifecycle state, and stable URL, applying the same access rules as the stable link
* Filter `kitgenix_document_manager_user_has_document_access` – plug in a custom access system alongside the built-in role/capability/user-ID checks
* Filter `kitgenix_document_manager_share_link_url` – adjust a signed share URL after it has been generated and signed
* Filter `kitgenix_document_manager_rest_document_response` – extend the REST metadata payload
* Action `kitgenix_document_manager_document_served` – fires after a document has been streamed to a requester, with the event type (view/download) and full access-decision data
* Action `kitgenix_document_manager_lifecycle_status_changed` – fires when a document's lifecycle state changes (e.g. scheduled → live), including via an hourly background check for documents nobody has visited
* Full internal reference (option keys, post meta, admin-post actions, hooks, and asset handles) is documented below for developers extending the plugin

= Compatibility =

* Works alongside WooCommerce: Shop Managers are automatically granted document-management access in addition to Administrators
* Adds a shared "Kitgenix" top-level admin menu used by other Kitgenix plugins, so all installed Kitgenix plugins are reachable from one place

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it through **Plugins → Add New** in your WordPress admin.
2. Activate the plugin through the **Plugins** menu.
3. Go to **Kitgenix → Document Manager**.
4. Add your first document and upload a file. Configure default file types, visibility, and serving mode under the **Settings** tab as needed.
5. If stable links return a 404, visit **Settings → Permalinks** and click **Save Changes** to flush rewrite rules.

== External Services ==

This plugin includes a shared "Kitgenix hub" component in wp-admin which may fetch publicly available plugin metadata from WordPress.org using the WordPress core `plugins_api()` function (WordPress.org Plugins API).

* When it runs: only in wp-admin (Kitgenix plugin admin pages)
* Data sent: plugin slug(s) (no personal data)
* Data received: publicly available plugin information (e.g. active installs, ratings)
* Caching: responses are cached locally using transients for ~1 day:
	* `kitgenix_hub_wporg_active_installs_v1`
	* `kitgenix_hub_wporg_ratings_v1`
	* `kitgenix_hub_wporg_media_v1`

WordPress.org's Plugins API is governed by the WordPress.org Terms of Service (https://wordpress.org/about/terms/) and Privacy Policy (https://wordpress.org/about/privacy/). No document data, file contents, or visitor information is ever sent as part of these requests.

== Developer Notes (Internal Reference) ==

This section documents internal identifiers used by the plugin (useful for developers, debugging, and advanced users).

= Options (Settings) =

* Option name: `kitgenix_document_manager_settings` (constant: `KITGENIX_DOCUMENT_MANAGER_OPTION_NAME`)
* Keys (defaults):
	* `allowed_extensions`: `["pdf","doc","docx","xls","xlsx","ppt","pptx","txt"]` (stored as an array; comma-separated strings are accepted and normalized)
	* `default_visibility`: `public` (public|private|restricted)
	* `serving_mode`: `attachment` (attachment|inline)
	* `keep_version_history`: `true`
	* `delete_old_versions`: `false`
	* `private_behavior`: `login` (login|403)
	* `delete_data_on_uninstall`: `false`
	* `card_button_label`: `"View"`
	* `card_button_style`: `"outline"` (outline|solid)
	* `card_open_new_tab`: `false`
	* `card_show_file_type`: `true`
	* `card_show_file_size`: `true`
	* `card_show_description`: `false`
	* `card_image_size`: `96` (px)
	* `card_meta_alignment`: `"justify"` (justify|left|center|right)
	* `card_button_alignment`: `"center"` (justify|left|center|right)
	* `card_button_text_color` / `card_button_bg_color` / `card_button_border_color` / `card_button_hover_text_color` / `card_button_hover_bg_color` / `card_button_hover_border_color`: `""` (hex or blank)

= Data Model =

* Custom Post Type: `kitgenix_dm_doc`
* Taxonomy (categories): `kitgenix_dm_cat`
* Capability: `manage_kitgenix_document_manager_documents` (assigned to Administrators and Shop Managers on activation)

= Post Meta =

* `_kitgenix_document_manager_current_attachment_id` (current attachment ID)
* `_kitgenix_document_manager_description` (optional document description shown on cards)
* `_kitgenix_document_manager_visibility` (`public`|`private`|`restricted`)
* `_kitgenix_document_manager_access_roles` (array of allowed WordPress roles)
* `_kitgenix_document_manager_access_capability` (optional required WordPress capability)
* `_kitgenix_document_manager_access_user_ids` (array of allowed user IDs)
* `_kitgenix_document_manager_share_expires_gmt` (signed share expiry in GMT)
* `_kitgenix_document_manager_share_secret` (per-document signing secret)
* `_kitgenix_document_manager_share_max_uses` (optional maximum signed-link uses; 0/absent = unlimited)
* `_kitgenix_document_manager_share_use_count` (times the current signed link has been used to actually access the file)
* `_kitgenix_document_manager_available_from_gmt` (go-live time in GMT)
* `_kitgenix_document_manager_archive_at_gmt` (archive time in GMT)
* `_kitgenix_document_manager_expires_at_gmt` (expiry time in GMT)
* `_kitgenix_document_manager_versioning_enabled` (0|1)
* `_kitgenix_document_manager_versions` (array of attachment IDs – unchanged format for backward compatibility)
* `_kitgenix_document_manager_version_meta` (array keyed by attachment ID => `['label' => string, 'note' => string]`; additive, never required)
* `_kitgenix_document_manager_last_lifecycle_state` (internal; last-observed lifecycle state slug, used only to detect transitions for the lifecycle hook)
* `_kitgenix_document_manager_analytics` (view/download counters, signed-share counters, and daily buckets)

= Stable Link Endpoint =

* Base path: `/kitgenix-document-manager/{slug}/`
* Query var: `kitgenix_document_manager_slug`
* Signed-share query args: `kitgenix_dm_share`, `kitgenix_dm_expires`, `kitgenix_dm_sig`

= REST API =

* `GET /wp-json/kitgenix-document-manager/v1/doc/{slug}`
	* Returns: `slug`, `title`, `updated` (ISO 8601), `visibility`, `schedule_status`, `lifecycle_state`, `stable_url`
	* Protected, scheduled, archived, or expired documents return the same access state the stable link endpoint enforces
	* Responses for anything other than a fully public document send `Cache-Control: private, no-store` so intermediary caches/CDNs never retain them

= Shortcodes =

* `[kitgenix_document_manager_link slug="my-doc" label="Download"]`
	* If `label` is omitted, the document title is used.
	* Protected, scheduled, archived, or expired documents render nothing when the current visitor cannot access them.

* `[kitgenix_document_manager_document slug="my-doc" button_label="View"]`
	* Renders a small preview (thumbnail/icon), the document title, file type, file size, and a "View" button.
	* Protected, scheduled, archived, or expired documents render nothing when the current visitor cannot access them.
	* Optional attributes: `button_style="outline"|"solid"`, `image_size="96"`, `meta_align="justify"|"left"|"center"|"right"`, `button_align="justify"|"left"|"center"|"right"`, `show_description="0"|"1"`, `new_tab="0"|"1"`, `show_type="0"|"1"`, `show_size="0"|"1"`.
	* To render multiple documents inline, use `slugs` instead of `slug`, e.g. `[kitgenix_document_manager_document slugs="doc-a,doc-b,doc-c"]`.

= Filters & Actions (Developer API) =

* `kitgenix_document_manager_user_has_document_access` (filter: `$allowed, $post_id, $context`)
	* Allows custom access systems to grant or deny a logged-in user after the built-in role/capability/user-ID checks run. `$allowed` is `null` by default; returning a boolean overrides the decision. `$context` includes `visibility`, `allowed_roles`, `required_capability`, `allowed_user_ids`, `surface`, and `schedule`.
* `kitgenix_document_manager_share_link_url` (filter: `$url, $post_id, $expires_timestamp`)
	* Runs whenever a signed share URL is generated. Fired only after the URL is fully built and signed – a filter cannot be used to grant access to a document it wouldn't otherwise have.
* `kitgenix_document_manager_document_served` (action: `$document_id, $attachment_id, $event_type, $access`)
	* Fires immediately after a document's file has been streamed to the requester (or prepared for a HEAD request). `$event_type` is `view` or `download`; `$access` is the full access-decision array.
* `kitgenix_document_manager_rest_document_response` (filter: `$data, $post, $access, $request`)
	* Extend the payload returned by `GET /kitgenix-document-manager/v1/doc/{slug}`. Runs only after the built-in access check has already allowed the request.
* `kitgenix_document_manager_lifecycle_status_changed` (action: `$document_id, $old_state, $new_state`)
	* Fires when a document's effective lifecycle state changes (e.g. `scheduled` -> `live`, `live` -> `archived`/`expired`). Detected both when a document is visited and via an hourly background check, so it also fires for documents nobody has viewed since the transition.

= Admin Actions (admin-post.php) =

The Document Manager screen uses admin-post actions, including:

* `kitgenix_document_manager_document_save`
	* Nonce field: `kitgenix_document_manager_document_nonce`
	* Nonce action: `kitgenix_document_manager_document_save`
* `kitgenix_document_manager_document_delete` (nonce query parameter `nonce`)
* `kitgenix_document_manager_documents_bulk_action`
	* Nonce field: `kitgenix_document_manager_bulk_action_nonce`
	* Nonce action: `kitgenix_document_manager_documents_bulk_action`
* `kitgenix_document_manager_documents_bulk_upload`
	* Nonce field: `kitgenix_document_manager_bulk_upload_nonce`
	* Nonce action: `kitgenix_document_manager_documents_bulk_upload`
* `kitgenix_document_manager_version_restore` (nonce query parameter `nonce`)
* `kitgenix_document_manager_version_delete` (nonce query parameter `nonce`)
* `kitgenix_document_manager_versions_delete_all` (nonce query parameter `nonce`)
* `kitgenix_document_manager_category_save`
	* Nonce field: `kitgenix_document_manager_category_nonce`
	* Nonce action: `kitgenix_document_manager_category_save`
* `kitgenix_document_manager_category_delete` (nonce query parameter `nonce`)
* `kitgenix_document_manager_share_revoke` (nonce query parameter `nonce`) – clears the current signed share link
* `kitgenix_document_manager_share_regenerate` (nonce query parameter `nonce`) – rotates the signing secret, invalidating all previously issued links
* `kitgenix_document_manager_export_settings` – downloads a JSON export of settings + categories (requires `manage_options`)
* `kitgenix_document_manager_import_settings` – imports settings + categories from a JSON export (requires `manage_options`)
* `kitgenix_document_manager_clear_event_log` – clears all entries from the Log tab (requires `manage_options`)

WordPress action hook names for the above screen actions:

* `admin_post_kitgenix_document_manager_document_save`
* `admin_post_kitgenix_document_manager_document_delete`
* `admin_post_kitgenix_document_manager_documents_bulk_action`
* `admin_post_kitgenix_document_manager_documents_bulk_upload`
* `admin_post_kitgenix_document_manager_version_restore`
* `admin_post_kitgenix_document_manager_version_delete`
* `admin_post_kitgenix_document_manager_versions_delete_all`
* `admin_post_kitgenix_document_manager_category_save`
* `admin_post_kitgenix_document_manager_category_delete`
* `admin_post_kitgenix_document_manager_share_revoke`
* `admin_post_kitgenix_document_manager_share_regenerate`
* `admin_post_kitgenix_document_manager_export_settings`
* `admin_post_kitgenix_document_manager_import_settings`
* `admin_post_kitgenix_document_manager_clear_event_log`

Admin AJAX:

* `wp_ajax_kitgenix_dm_search_docs` (used by the Shortcode Builder autocomplete)

The (hidden) CPT edit screen metabox uses:

* `kitgenix_document_manager_upload_initial` (nonce field `kitgenix_document_manager_file_nonce`)
* `kitgenix_document_manager_replace_file` (nonce field `kitgenix_document_manager_file_nonce`)
* Visibility/versioning meta save nonce:
	* Nonce field: `kitgenix_document_manager_meta_nonce`
	* Nonce action: `kitgenix_document_manager_save_meta`

= Hooks, Filters & Assets (Internal) =

This list is intended as a comprehensive map of WordPress integration points used by the plugin.

* `plugins_loaded` (bootstrap init)
* `init` (register CPT/taxonomy; add rewrite rules)
* Filter: `query_vars` (adds query var `kitgenix_document_manager_slug`)
* `template_redirect` (serves stable link responses)
* `rest_api_init` (registers REST route)
* `admin_menu` (registers Kitgenix menu + Document Manager submenu)
* `admin_head` (outputs Kitgenix admin menu icon CSS)
* `admin_init` (registers Settings API option)
* `admin_enqueue_scripts` (enqueues Kitgenix hub CSS; enqueues Document Manager admin assets)
* `add_meta_boxes` (adds the CPT file/visibility metaboxes)
* `save_post` (saves visibility, versioning, access, signed-share, and scheduling meta)
* `admin_post_kitgenix_document_manager_upload_initial` (metabox upload)
* `admin_post_kitgenix_document_manager_replace_file` (metabox replace)
* `admin_notices` (metabox success/error notices via `kitgenix_dm_notice`)
* Filter: `manage_kitgenix_dm_doc_posts_columns` (adds list table columns)
* `manage_kitgenix_dm_doc_posts_custom_column` (renders list table columns)
* Filter: `post_row_actions` (adds "Replace File" row action)
* Filter: `site_status_tests` (registers Site Health test)
* Filter: `rest_request_before_callbacks` (blocks single-item REST media requests for inaccessible documents)
* Filter: `rest_attachment_query` (excludes inaccessible attachments from REST media collection queries)
* Filter: `rest_prepare_attachment` (strips file-revealing fields from inaccessible attachment REST responses)
* `template_redirect` (blocks the classic attachment permalink for inaccessible documents)
* Shortcodes: `kitgenix_document_manager_link`, `kitgenix_document_manager_document`

Other admin UI identifiers:
- Admin list-table column key: `kitgenix_dm_actions`
- Version restore notice query arg: `kitgenix_version_restored=1`

Admin asset handles (wp-admin):

* Styles:
	* `kitgenix-hub` (Kitgenix hub page)
	* `kitgenix-admin-ui` (shared Kitgenix admin UI)
	* `kitgenix-document-manager-admin` (Document Manager settings screen)
* Scripts:
	* `kitgenix-admin-tabs` (shared Kitgenix tabs UI)
	* `kitgenix-document-manager-admin` (Document Manager admin JS)

= Site Health (Internal) =

* Registers Site Health test ID: `kitgenix_document_manager_uploads` (checks uploads directory exists and is writable)

= Notes (Internal) =

* Uninstall deletes a legacy transient `kitgenix_document_manager_site_health` for backwards compatibility, even though v1 does not set it.
* WP-Cron hook `kitgenix_document_manager_lifecycle_check` runs hourly (scheduled on activation/upgrade, cleared on deactivation and uninstall) to detect lifecycle-state transitions for documents that have a go-live/archive/expiry date set, bounded to 500 documents per run.
* Option `kitgenix_document_manager_db_version` tracks the installed data-shape version for the idempotent upgrade routine; it is safe to run on every request (a single cached option read) and only performs work when behind the current version.
* The plugin's dedicated uploads subfolder (`wp-content/uploads/kitgenix-document-manager/`) receives an `.htaccess`, `web.config`, and empty `index.php` the first time it is created/verified, blocking direct web requests to that folder on Apache/IIS. This does not affect normal serving, which reads the file from local disk rather than requesting it over HTTP. Nginx hosts should add an equivalent `location` block manually.

== Frequently Asked Questions ==

= Where are uploaded documents stored? =

Files uploaded through Document Manager are stored in `wp-content/uploads/kitgenix-document-manager/`. If you attach an existing Media Library file to a document instead, the plugin uses that attachment's existing location.

= What file types can I upload? =

By default: PDF, DOC, DOCX, XLS, XLSX, PPT, PPTX, and TXT. The allowed list is configurable under Settings, but is always checked against the plugin's built-in extension-to-MIME allow-list, so unsupported or unsafe formats (such as SVG or PHP files) cannot be added.

= Why does my file download instead of opening in the browser? =

Browsers can only display certain file types inline – PDFs, images, and plain text. For formats browsers can't render, such as DOCX or XLSX, the file downloads even when Serving mode is set to Inline.

= Can I restrict a document to specific users or roles? =

Yes. Each document supports access rules by role, capability, or specific WordPress user IDs, in addition to its Public/Private/Restricted visibility setting.

= Can I share a document with someone who doesn't have a WordPress account? =

Yes. Generate a signed share link – an expiring URL that grants temporary access without requiring a login. You can set an optional maximum-use count, and revoke or regenerate the link at any time.

= Can I schedule when a document becomes available or stops being available? =

Yes. Each document can have a go-live date, an archive date, and an expiry date. These are enforced on the stable link, shortcodes, and REST endpoint, and an hourly background check also catches transitions for documents nobody has visited.

= Can I restore a previous version of a document? =

Yes, if **Keep version history** is enabled for that document. Use the quick edit modal to restore or delete older versions from the Versions panel.

= Can I upload multiple documents at once? =

Yes. The Documents tab includes a Batch Upload panel for drag-and-drop multi-file ingest, plus bulk actions for changing visibility, category, or lifecycle status across several documents at once.

= Does this plugin have a REST API? =

Yes. `GET /wp-json/kitgenix-document-manager/v1/doc/{slug}` returns a document's metadata and respects the same access rules as the stable link.

= Does the plugin track document views and downloads? =

Yes. Built-in analytics record per-document view/download counts, signed-share activity, last-access timestamps, and 30-day rollups.

= Does this plugin send any data to external services? =

The plugin itself does not send document data anywhere. A shared "Kitgenix hub" screen in wp-admin may request publicly available plugin metadata (such as active install counts and ratings) from the WordPress.org Plugins API, which involves sending only a plugin slug – never document content or visitor data. See "External Services" above for details.

= What happens to my documents if I uninstall the plugin? =

By default, uninstalling removes the plugin's settings and activity log but leaves your documents, files, and analytics untouched. If you enable **Delete all plugin data on uninstall** under Settings, uninstalling also permanently deletes all documents and their uploaded files.

== Screenshots ==

1. The Documents table, showing each document's stable link, current version, file details, visibility, and lifecycle status, with search and Status/Visibility/File Type filters above it.
2. The Add/Edit Document panel with visibility and access control settings, including the role, capability, and user ID fields and the Public/Logged-in/Administrators access presets.
3. Generating a signed share link for a document, with the maximum-use count field and the Revoke and Regenerate actions for controlling external access.
4. The lifecycle scheduling controls for setting a document's go-live, archive, and expiry dates.
5. The Batch Upload panel, showing the drag-and-drop area for adding multiple files at once with shared visibility, category, and versioning defaults.
6. The Settings tab, showing the allowed file types, default visibility, serving mode, and the settings/category JSON import and export controls.
7. The Frontend tab's Shortcode Builder alongside the document card style options, including button style, colours, and preview image size.
8. A document card rendered on the front end via the `[kitgenix_document_manager_document]` shortcode, showing the preview image, title, file type, file size, and button.

== Credits ==
Built with ❤︎ by @kitgenix - https://kitgenix.com

== Upgrade Notice ==

= 2.0.0 =
Major update: signed share link controls (max uses, revoke, regenerate), unified document lifecycle states with status/visibility/type filters, a redesigned admin UI, resumable downloads via HTTP Range support, and hardened protection against attachment REST/permalink access to Private, Restricted, or scheduled documents. Recommended for all websites.

== Changelog ==

= 2.0.0 (25 August 2026) =

* New: Signed share links now support an optional maximum-use count (0 = unlimited), a one-click "Revoke link" action that immediately invalidates the current link, and a "Regenerate" action that rotates the signing secret so all previously issued links stop working, even if they have not yet expired. These controls are available from the Documents table, edit modal, and native document editor.
* New: Document versions can now include an optional label or note, such as "2024 policy update", when replacing a file. The Versions list now also shows who replaced each version and when.
* New: Added a unified document lifecycle state – Draft, Unavailable, Scheduled, Live, Restricted, Archived, or Expired – displayed as a badge on the Documents table.
* New: Added Status, Visibility, and File Type filters to the Documents table alongside the existing search box.
* New: Document lifecycle state is now included in the REST metadata endpoint.
* New: Added an hourly background lifecycle check that detects state transitions such as a scheduled document becoming live even when nobody visits its link, triggering the new `kitgenix_document_manager_lifecycle_status_changed` hook and a Log tab entry.
* New: Added an "Access preset" quick-fill control with Public, Logged-in Users, and Administrators Only options above the existing role, capability, and user-ID access fields on Add/Edit document forms and modals. Presets only pre-fill the existing fields and can still be adjusted before saving.
* New: Added "Archive now" and "Clear lifecycle schedule" bulk actions.
* New: Added confirmation prompts before bulk-setting documents to Public or bulk-archiving documents, helping prevent accidental visibility or lifecycle changes.
* New: Added a Settings → Import/Export card for transferring plugin settings and categories between sites using JSON files. Documents, uploaded files, and analytics are intentionally excluded so private content cannot accidentally be bundled into an export.
* New: Added the `kitgenix_document_manager_share_link_url` developer hook for filtering generated signed-share URLs.
* New: Added the `kitgenix_document_manager_document_served` developer hook for responding when a document is successfully delivered.
* New: Added the `kitgenix_document_manager_rest_document_response` developer hook for customising REST metadata responses.
* New: Added the `kitgenix_document_manager_lifecycle_status_changed` developer hook for responding to document lifecycle transitions.
* New: Redesigned the admin interface around the shared Kitgenix design system with a sticky topbar and grouped Documents navigation for All Documents, Categories, and Versions.
* New: Added in-page settings search with "/" and Cmd/Ctrl+K keyboard shortcuts.
* New: Added a light/dark theme toggle for the admin interface.
* New: Added quick links to the Kitgenix Hub and other Kitgenix plugins from the shared admin topbar.
* New: Added a responsive mobile menu for Kitgenix admin navigation on narrower screens.
* New: Added Image Optimizer to the Kitgenix Hub and updated the MultiStore listing to its new "MultiStore for WooCommerce" name.
* Improved: Stable document links now support HTTP Range requests for resumable downloads, PDF/video seeking, and download managers.
* Improved: Valid single-range requests now return HTTP 206 responses with the appropriate `Content-Range` header.
* Improved: Malformed or multi-range requests safely fall back to a full response, while out-of-bounds ranges return HTTP 416.
* Improved: Large document files continue to stream from local disk in fixed-size chunks rather than being loaded entirely into PHP memory.
* Improved: Reordered the Documents tab so the All Documents list is displayed full-width at the top, with Add Document and Batch Upload moved into stacked full-width cards below instead of the previous cramped two-column layout.
* Improved: Redesigned settings and document forms with toggle switches replacing checkboxes, clearer field labels and descriptions, and consistent card headers throughout the admin interface.
* Improved: Unified toast notifications, collapsible cards, copy-to-clipboard controls, and table search behaviour through the shared Kitgenix component library.
* Improved: The Log tab now displays entries in a searchable, paginated table containing time, context, outcome, and a plain-English note instead of a plain text block.
* Improved: Failed document uploads and replacements now report the specific underlying cause, including missing permissions, disk or filesystem permission problems, disallowed file types, and database errors, instead of displaying one generic "upload failed" message.
* Improved: Upload and replacement failures are now recorded in the Log tab for easier diagnosis.
* Improved: Rejected signed document links now distinguish between expired links, links issued before a security-key rotation, and genuinely tampered or invalid links.
* Improved: Expired and pre-rotation links are treated as routine conditions requiring a new link, while invalid or tampered links are logged as genuine security concerns.
* Improved: Added a reference table to the Log tab explaining what each recorded category means and whether administrator action is required.
* Improved: The Support tab is now three focused cards – a donate card with a collapsible monthly-amount picker, a "what your support funds" summary, and a "get involved" panel for reviews and plugin links – replacing the previous stack of donate, trust, and community cards.
* Improved: Moved the Log tab directly before Support in the admin navigation for a more logical workflow.
* Fix: Resolved an issue where edit-document and edit-category modals could become out of sync between their visible state and the underlying hidden attribute.
* Fix: The document card shortcode no longer renders an empty or broken card when a document has no attached file.
* Fix: Resolved a markup nesting issue that could cause the Documents tab to render without its sidebar and shift the page layout to the left.
* Fix: Number inputs throughout the settings screens are no longer cramped to a fixed 50px width.
* Security: Document files are now protected from direct access through WordPress REST media endpoints such as `/wp/v2/media/{id}` and attachment permalinks, preventing these routes from bypassing the plugin's Private, Restricted, signed-link, and scheduling access controls.
* Security: Added a dedicated document-media guard that blocks unauthorised single-item REST requests, excludes inaccessible attachments from REST collection queries, strips file-revealing fields as defence in depth, and returns a 404 response for protected attachment permalinks.
* Security: REST and attachment-permalink protection applies to both files uploaded directly through Document Manager and files selected from the existing WordPress Media Library, without moving files or changing their existing URLs.
* Security: Added `.htaccess`, `web.config`, and an empty `index.php` file to the plugin's dedicated uploads directory to prevent direct web access and directory listing on Apache, IIS, and compatible web servers.
* Security: Documents continue to stream securely from local disk through the plugin rather than being proxied directly through the protected uploads directory.
* Security: Nginx installations should configure an equivalent server-level `location` rule because WordPress plugins cannot modify Nginx server configuration directly.
* Security: The "Allowed file extensions" setting is now intersected with the plugin's fixed, audited extension-to-MIME allowlist when settings are saved.
* Security: Administrators are now warned when unsupported file extensions are entered or removed from the configured allowlist.
* Security: Dangerous file formats, including SVG, PHP, and other executable types, remain excluded from the permitted document upload types.
* Security: Hardened stable-link `Content-Disposition` headers against quote, carriage-return, and line-feed characters.
* Security: Added explicit `Accept-Ranges` and `X-Content-Type-Options` headers to document responses.
* Security: REST metadata responses for Private, Restricted, or signed-link-only documents now include `Cache-Control: private, no-store`, preventing intermediary caches and CDNs from retaining sensitive document metadata.
* Removed: Removed the "theme button style" and "full width button" options from the document card shortcode and Frontend settings. Document card buttons now use either Outline or Solid styling.
* Dev: Renamed frontend document card CSS classes from `kgx-dm-*` to `kitgenix-document-manager-*` for consistency with other Kitgenix plugins. Sites with custom CSS targeting the previous class names will need to update their selectors.
* Dev: Refreshed the Kitgenix logo and icon asset set used across the plugin admin interface, Kitgenix Hub, and WordPress admin menu icon.
