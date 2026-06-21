=== Kitgenix Document Manager ===
Contributors: kitgenix
Donate link: https://buymeacoffee.com/kitgenix
Tags: documents, downloads, file manager, private files, stable links
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.1.3
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

Manage document downloads with stable links, granular access, lifecycle scheduling, and usage analytics.

== Description ==

Kitgenix Document Manager lets admins upload documents and share a stable link like:

/kitgenix-document-manager/{slug}/

When you replace the file, the link stays the same and serves the new version.

You can protect documents with login-only or restricted access rules, issue expiring signed share links for external recipients, schedule go-live/archive/expiry dates, and review view/download analytics without changing the public URL.

== Key Features ==

* Stable links: replace the file without changing the URL
* Admin documents table with search, copy link, copy share link, and “Open link” actions
* Bulk document actions: change visibility, assign categories, or move multiple documents to Trash in one step
* Copy shortcode quick action so editors can grab the correct embed snippet without opening another screen
* Quick edit modal (popup) for editing documents without leaving the table
* Document Categories: create categories and assign documents for easier organization
* Visibility controls: Public, Private, or Restricted documents
* Granular access rules: limit a document by role, capability, or specific WordPress user IDs
* Signed share links: expiring URLs for secure external distribution without creating a user account
* Lifecycle controls: available-from, archive-at, and expire-at dates enforced across stable links, shortcodes, and REST metadata
* Usage analytics: per-document view/download counts, signed-share activity, last-access timestamps, and 30-day rollups
* Private behavior: redirect to login or return 403
* Serving mode: Inline (browser) or Download (attachment)
* Optional version history per document, including restore/delete for older versions
* Bulk version cleanup: delete all old versions for a document (Versions tab + modal action)
* Select from Media Library: optionally pick an existing Media Library file instead of uploading
* Batch uploads: drag and drop multiple files to create document entries with shared defaults
* Shortcode Builder with AJAX-powered document search for generating single-document or multi-document embeds
* Document card shortcode with preview image, file type, file size, optional description, and configurable button styling
* REST API endpoint for fetching stable document metadata by slug
* Site Health test that checks the plugin upload directory exists and is writable
* Safer serving: correct Content-Type where possible, security headers, and conditional caching for public docs

== Usage ==

1. Go to **Kitgenix → Document Manager**
2. Add a document (name + optional slug) and upload the initial file
3. Copy the stable URL from the table and share it
4. Configure visibility, role/capability/user access, signed share expiry, and optional go-live/archive/expiry dates as needed
5. To update a document later, use **Edit** (modal) and upload a replacement file
6. Copy the signed share link when you need temporary external access without a login
7. Review per-document analytics to see recent views and downloads
8. If **Keep version history** is enabled for that document, older files will appear in the Versions panel (restore/delete)
9. Use **Batch Upload** when you need to ingest multiple files at once with shared visibility, category, and versioning defaults
10. Use bulk actions above the documents table when you need to update visibility, recategorize, or trash several documents together
11. Use **Copy shortcode** or the **Shortcode Builder** when you want to embed document links or cards in content

Categories:

* Use the **Categories** tab to add/edit/delete categories
* Assign a category when adding/editing a document

Note: Inline viewing works best for PDFs and images. Office documents (DOCX/XLSX/etc.) typically download because browsers can’t render them natively.

== Installation ==

1. Upload the plugin folder to /wp-content/plugins/
2. Activate the plugin through the 'Plugins' menu.
3. Go to Kitgenix -> Document Manager.

== Settings ==

Settings are available under the **Settings** tab:

* Allowed file extensions
* Default visibility (Public/Private/Restricted)
* Serving mode:
	* Inline (browser)
	* Download (attachment)
* Versioning defaults (keep history by default, delete old versions when history is disabled)
* Private document behavior (login redirect or 403)
* Delete all plugin data on uninstall (optional)

Per-document controls in the **Documents** screen and native CPT metabox include:

* Role, capability, and user ID access rules
* Signed share link expiry and generated signed URLs
* Available-from, archive-at, and expire-at lifecycle controls
* View/download analytics with recent activity summaries

Frontend card options are available under the **Frontend** tab:

* Default document card button label
* Button style (Outline or Solid)
* Preview image size
* File type/file size alignment
* Button alignment
* Button colors (normal + hover)
* Option to open the “View” button in a new tab
* Toggle showing file type and file size
* Toggle showing document description
* Shortcode Builder (generate a shortcode for one or multiple documents)

== Frontend Embeds ==

Use the built-in shortcodes when you want documents to appear directly inside posts, pages, or WooCommerce content:

* `[kitgenix_document_manager_link slug="my-doc"]` for a simple stable link
* `[kitgenix_document_manager_document slug="my-doc"]` for a document card with preview image, metadata, button styling, and optional description
* `[kitgenix_document_manager_document slugs="doc-a,doc-b,doc-c"]` to render multiple cards inline

== Security & Caching ==

* Private, restricted, and signed-share document responses are not cached (no-store)
* Restricted access can be enforced by login state, role, capability, specific user IDs, or a valid signed share URL
* Public documents use conditional caching (ETag/Last-Modified) so browsers/CDNs can revalidate efficiently (including 304 Not Modified responses)
* Responses include security hardening headers like `X-Content-Type-Options: nosniff`
* Private documents include `X-Robots-Tag: noindex, nofollow`

== External Services ==

This plugin includes a shared “Kitgenix hub” component in wp-admin which may fetch publicly available plugin metadata from WordPress.org using the WordPress core `plugins_api()` function (WordPress.org Plugins API).

* When it runs: only in wp-admin (Kitgenix plugin admin pages)
* Data sent: plugin slug(s) (no personal data)
* Data received: publicly available plugin information (e.g. active installs, ratings)
* Caching: responses are cached locally using transients for ~1 day:
	* `kitgenix_hub_wporg_active_installs_v1`
	* `kitgenix_hub_wporg_ratings_v1`
	* `kitgenix_hub_wporg_media_v1`

== Developer Notes (Internal Reference) ==

This section documents internal identifiers used by the plugin (useful for developers, debugging, and advanced users).

= Options (Settings) =

* Option name: `kitgenix_document_manager_settings` (constant: `KITGENIX_DOCUMENT_MANAGER_OPTION_NAME`)
* Settings group: `kitgenix_document_manager_settings_group`
* Keys (defaults):
	* `allowed_extensions`: `["pdf","doc","docx","xls","xlsx","ppt","pptx","txt"]` (stored as an array; comma-separated strings are accepted and normalized)
	* `default_visibility`: `public` (public|private|restricted)
	* `serving_mode`: `attachment` (attachment|inline)
	* `keep_version_history`: `true`
	* `delete_old_versions`: `false`
	* `private_behavior`: `login` (login|403)
	* `delete_data_on_uninstall`: `false`
	* `card_button_label`: `"View"`
	* `card_button_style_source`: `"custom"` (custom|theme)
	* `card_button_style`: `"outline"` (outline|solid)
	* `card_open_new_tab`: `false`
	* `card_show_file_type`: `true`
	* `card_show_file_size`: `true`
	* `card_show_description`: `false`
	* `card_button_full_width`: `false`
	* `card_image_size`: `96` (px)
	* `card_meta_alignment`: `"justify"` (justify|left|center|right)
	* `card_button_alignment`: `"center"` (justify|left|center|right)
	* `card_button_text_color`: `""` (hex or blank)
	* `card_button_bg_color`: `""` (hex or blank)
	* `card_button_border_color`: `""` (hex or blank)
	* `card_button_hover_text_color`: `""` (hex or blank)
	* `card_button_hover_bg_color`: `""` (hex or blank)
	* `card_button_hover_border_color`: `""` (hex or blank)

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
* `_kitgenix_document_manager_available_from_gmt` (go-live time in GMT)
* `_kitgenix_document_manager_archive_at_gmt` (archive time in GMT)
* `_kitgenix_document_manager_expires_at_gmt` (expiry time in GMT)
* `_kitgenix_document_manager_versioning_enabled` (0|1)
* `_kitgenix_document_manager_versions` (array of attachment IDs)
* `_kitgenix_document_manager_analytics` (view/download counters, signed-share counters, and daily buckets)

= Stable Link Endpoint =

* Base path: `/kitgenix-document-manager/{slug}/`
* Query var: `kitgenix_document_manager_slug`
* Signed-share query args: `kitgenix_dm_share`, `kitgenix_dm_expires`, `kitgenix_dm_sig`

= REST API =

* `GET /wp-json/kitgenix-document-manager/v1/doc/{slug}`
	* Returns: `slug`, `title`, `updated` (ISO 8601), `visibility`, `schedule_status`, `stable_url`
	* Protected, scheduled, archived, or expired documents return the same access state the stable link endpoint enforces

= Shortcode =

* `[kitgenix_document_manager_link slug="my-doc" label="Download"]`
	* If `label` is omitted, the document title is used.
	* Protected, scheduled, archived, or expired documents render nothing when the current visitor cannot access them.

* `[kitgenix_document_manager_document slug="my-doc" button_label="View"]`
	* Renders a small preview (thumbnail/icon), the document title, file type, file size, and a “View” button.
	* Protected, scheduled, archived, or expired documents render nothing when the current visitor cannot access them.
	* Optional attributes: `button_style_source="custom"|"theme"`, `button_style="outline"|"solid"`, `button_full_width="0"|"1"`, `image_size="96"`, `meta_align="justify"|"left"|"center"|"right"`, `button_align="justify"|"left"|"center"|"right"`, `show_description="0"|"1"`, `new_tab="0"|"1"`, `show_type="0"|"1"`, `show_size="0"|"1"`.
	* To render multiple documents inline, use `slugs` instead of `slug`, e.g. `[kitgenix_document_manager_document slugs="doc-a,doc-b,doc-c"]`.
	* The shortcode also accepts comma-, space-, pipe-, or semicolon-separated slug lists in either `slug` or `slugs`.

= Filters =

* `kitgenix_document_manager_user_has_document_access`
	* Allows custom access systems to grant or deny a logged-in user after the built-in role/capability/user-ID checks run.

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
* Filter: `post_row_actions` (adds “Replace File” row action)
* Filter: `site_status_tests` (registers Site Health test)
* Shortcode: `kitgenix_document_manager_link`

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

== Frequently Asked Questions ==

= Where are files stored? =

When you upload a document inside Document Manager, files are stored in:

wp-content/uploads/kitgenix-document-manager/

If you select an existing file from the Media Library, the plugin uses that attachment’s existing location inside your normal WordPress uploads directory.

= Why does my file download even in “Inline (browser)” mode? =

Browsers can only display some file types inline (PDFs, images, and many text types). For formats that the browser can’t render (e.g. DOCX/XLSX), the file will download.

= My stable link returns 404. What should I do? =

Go to **Settings → Permalinks** and click **Save Changes** to flush rewrite rules.

= Can I restore an older file? =

Yes. Enable **Keep version history** for that document, then use **Edit** to open the modal and restore/delete older versions from the Versions panel.

= Can I upload or update documents in bulk? =

Yes. The Documents tab now includes a **Batch Upload** panel for drag-and-drop multi-file ingest plus bulk actions above the table for category changes, visibility updates, and moving several documents to Trash at once.

= Can I embed document cards or multiple documents on a page? =

Yes. Use `[kitgenix_document_manager_document]` for a styled card and the `slugs` attribute when you want to render multiple documents in one block.

= Is there a REST API endpoint for documents? =

Yes. The plugin registers `GET /wp-json/kitgenix-document-manager/v1/doc/{slug}` and respects private-document access rules.

== Screenshots ==

1. Documents tab with search, stable links, copy actions, and quick edit.
2. Quick edit modal with file replacement, visibility, and version controls.
3. Categories and Versions tabs for organisation and cleanup.
4. Frontend tab with shortcode builder and document-card controls.
5. Public document card output with preview image, metadata, and button.

== Upgrade Notice ==

= 1.1.3 =
Recommended for all websites.

== Changelog ==
= 1.1.3 (26 May 2026) =
* Compatibility: Confirmed compatibility with WordPress 7.0.
* New: Added a Log tab to the admin settings page. Records recent plugin activity (settings saves and key operations) with timestamps, context labels, and plain-English notes to aid troubleshooting.
* Fix: Resolved a missing class file include that would have caused a fatal error when visiting the Log tab.
* Fix: Activity log data is now fully cleaned up when the plugin is uninstalled.

= 1.1.2 (26 May 2026) =
* Dev: Skipped to be in line with other Kitgenix Plugins

= 1.1.1 (26 May 2026) =
* Dev: Skipped to be in line with other Kitgenix Plugins

= 1.1.0 (7 May 2026) =
* New: Added bulk document actions for moving multiple documents to Trash, changing visibility, and assigning or clearing categories from the Documents tab.
* New: Added drag-and-drop batch uploads that create one document per file with shared category, visibility, and version-history defaults.
* New: Added batch upload status notices so editors can see how many files were created and whether any files were skipped.
* New: Added restricted document access with per-document role, capability, and user-ID grants plus expiring signed share links.
* New: Added go-live, archive, and expiry controls that hide unavailable documents from stable links, shortcodes, and the REST metadata endpoint.
* New: Added document analytics for views, downloads, signed-share activity, and last-access timestamps, including 30-day rollups in the admin UI.
* Improvement: Extended the Documents tab, quick edit modal, native metabox, and admin columns to manage restricted access, lifecycle rules, and signed-share URLs in one place.

= 1.0.0 (18 March 2026) =
* New: Initial release.
* New: Added a document card shortcode for embedding a document preview with file type/size and a “View” button (includes button label/new tab/field toggles and outline/solid button style).
* New: Added a stable link endpoint: /kitgenix-document-manager/{slug}/ (file can be replaced without changing the URL).
* New: Added a Document Manager admin area under the Kitgenix menu with tabs for Documents, Categories, Versions, Settings and Support.
* New: Added an admin documents table with search plus quick actions (copy link, open link, edit, replace file).
* New: Added a quick edit modal for editing documents without leaving the documents table.
* New: Added Document Categories (create/manage categories and assign them to documents).
* New: Added per-document visibility (Public/Private) plus private behavior options (redirect to login or return 403).
* New: Added file serving modes (inline vs download/attachment) with safer inline handling.
* New: Added optional per-document version history, including restore and delete for older versions.
* New: Added bulk version cleanup tools (delete all old versions for a document via the Versions tab and modal actions).
* New: Added Media Library selection support (choose an existing Media Library file instead of uploading).
* New: Added settings for allowed file extensions plus sensible defaults for visibility, serving mode and versioning.
* Security: Added safer file serving with traversal checks, security headers, and conditional caching for public documents (ETag/Last-Modified) while private documents are not cached (no-store).
* New: Added a Site Health test for the Kitgenix Document Manager uploads directory.
* Cleanup: Added uninstall behavior that removes plugin settings, with an option to delete all Document Manager data (documents and related attachments/versions).
* UI: Improved the Kitgenix admin header layout and social icon links (compact icon buttons) across settings and the Kitgenix hub.
* Fix: Admin notices now display above the Kitgenix header using the WordPress standard notice area.
* Fix: Added defensive notice normalization to prevent notices being relocated into the header by other scripts.
* UI: Admin tables inside Kitgenix pages now use Kitgenix styling for a more consistent branded look.
* Fix: Added spacing between adjacent action links/buttons (e.g., Edit/Delete).
* Security: Normalised metabox and admin-post request handling so POST values are read only after nonce verification, and upload arrays are sanitized before use.
* Fix: Stream documents with validated file paths and safe response headers instead of reading file contents into memory for output.
* Fix: Escaped shared Kitgenix hub card media output for WordPress coding standards compliance.
* Maintenance: Updated the plugin Author URI to the public Kitgenix WordPress.org profile and replaced the old custom admin-menu icon CSS with the native Dashicons icon.
