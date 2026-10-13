# Changelog

## 2.1.0 – 13 October 2026

- **New:** Added a configurable log auto-delete setting (7/14/30 days) to automatically remove old event log entries.
- **New:** Added a "Request a Feature" button to the Need Help? sidebar, linking directly to this plugin's page on the Kitgenix roadmap (https://roadmap.kitgenix.com).
- **New:** Added a "Request a Feature" banner below the plugin grid on the Kitgenix Hub page, linking to the shared Kitgenix roadmap.

## 2.0.0 – 22 August 2026

- **New:** Signed share links now support an optional maximum-use count (0 = unlimited), a one-click "Revoke link" action that immediately invalidates the current link, and a "Regenerate" action that rotates the signing secret so all previously issued links stop working, even if they have not yet expired. These controls are available from the Documents table, edit modal, and native document editor.
- **New:** Document versions can now include an optional label or note, such as "2024 policy update", when replacing a file. The Versions list now also shows who replaced each version and when.
- **New:** Added a unified document lifecycle state – Draft, Unavailable, Scheduled, Live, Restricted, Archived, or Expired – displayed as a badge on the Documents table.
- **New:** Added Status, Visibility, and File Type filters to the Documents table alongside the existing search box.
- **New:** Document lifecycle state is now included in the REST metadata endpoint.
- **New:** Added an hourly background lifecycle check that detects state transitions such as a scheduled document becoming live even when nobody visits its link, triggering the new `kitgenix_document_manager_lifecycle_status_changed` hook and a Log tab entry.
- **New:** Added an "Access preset" quick-fill control with Public, Logged-in Users, and Administrators Only options above the existing role, capability, and user-ID access fields on Add/Edit document forms and modals. Presets only pre-fill the existing fields and can still be adjusted before saving.
- **New:** Added "Archive now" and "Clear lifecycle schedule" bulk actions.
- **New:** Added confirmation prompts before bulk-setting documents to Public or bulk-archiving documents, helping prevent accidental visibility or lifecycle changes.
- **New:** Added a Settings → Import/Export card for transferring plugin settings and categories between sites using JSON files. Documents, uploaded files, and analytics are intentionally excluded so private content cannot accidentally be bundled into an export.
- **New:** Added the `kitgenix_document_manager_share_link_url` developer hook for filtering generated signed-share URLs.
- **New:** Added the `kitgenix_document_manager_document_served` developer hook for responding when a document is successfully delivered.
- **New:** Added the `kitgenix_document_manager_rest_document_response` developer hook for customising REST metadata responses.
- **New:** Added the `kitgenix_document_manager_lifecycle_status_changed` developer hook for responding to document lifecycle transitions.
- **New:** Redesigned the admin interface around the shared Kitgenix design system with a sticky topbar and grouped Documents navigation for All Documents, Categories, and Versions.
- **New:** Added in-page settings search with "/" and Cmd/Ctrl+K keyboard shortcuts.
- **New:** Added a light/dark theme toggle for the admin interface.
- **New:** Added quick links to the Kitgenix Hub and other Kitgenix plugins from the shared admin topbar.
- **New:** Added a responsive mobile menu for Kitgenix admin navigation on narrower screens.
- **New:** Added Image Optimizer to the Kitgenix Hub and updated the MultiStore listing to its new "MultiStore for WooCommerce" name.
- **Improved:** Stable document links now support HTTP Range requests for resumable downloads, PDF/video seeking, and download managers.
- **Improved:** Valid single-range requests now return HTTP 206 responses with the appropriate `Content-Range` header.
- **Improved:** Malformed or multi-range requests safely fall back to a full response, while out-of-bounds ranges return HTTP 416.
- **Improved:** Large document files continue to stream from local disk in fixed-size chunks rather than being loaded entirely into PHP memory.
- **Improved:** Reordered the Documents tab so the All Documents list is displayed full-width at the top, with Add Document and Batch Upload moved into stacked full-width cards below instead of the previous cramped two-column layout.
- **Improved:** Redesigned settings and document forms with toggle switches replacing checkboxes, clearer field labels and descriptions, and consistent card headers throughout the admin interface.
- **Improved:** Unified toast notifications, collapsible cards, copy-to-clipboard controls, and table search behaviour through the shared Kitgenix component library.
- **Improved:** The Log tab now displays entries in a searchable, paginated table containing time, context, outcome, and a plain-English note instead of a plain text block.
- **Improved:** Failed document uploads and replacements now report the specific underlying cause, including missing permissions, disk or filesystem permission problems, disallowed file types, and database errors, instead of displaying one generic "upload failed" message.
- **Improved:** Upload and replacement failures are now recorded in the Log tab for easier diagnosis.
- **Improved:** Rejected signed document links now distinguish between expired links, links issued before a security-key rotation, and genuinely tampered or invalid links.
- **Improved:** Expired and pre-rotation links are treated as routine conditions requiring a new link, while invalid or tampered links are logged as genuine security concerns.
- **Improved:** Added a reference table to the Log tab explaining what each recorded category means and whether administrator action is required.
- **Improved:** The Support tab is now three focused cards – a donate card with a collapsible monthly-amount picker, a "what your support funds" summary, and a "get involved" panel for reviews and plugin links – replacing the previous stack of donate, trust, and community cards.
- **Improved:** Moved the Log tab directly before Support in the admin navigation for a more logical workflow.
- **Fix:** Resolved an issue where edit-document and edit-category modals could become out of sync between their visible state and the underlying hidden attribute.
- **Fix:** The document card shortcode no longer renders an empty or broken card when a document has no attached file.
- **Fix:** Resolved a markup nesting issue that could cause the Documents tab to render without its sidebar and shift the page layout to the left.
- **Fix:** Number inputs throughout the settings screens are no longer cramped to a fixed 50px width.
- **Security:** Document files are now protected from direct access through WordPress REST media endpoints such as `/wp/v2/media/{id}` and attachment permalinks, preventing these routes from bypassing the plugin's Private, Restricted, signed-link, and scheduling access controls.
- **Security:** Added a dedicated document-media guard that blocks unauthorised single-item REST requests, excludes inaccessible attachments from REST collection queries, strips file-revealing fields as defence in depth, and returns a 404 response for protected attachment permalinks.
- **Security:** REST and attachment-permalink protection applies to both files uploaded directly through Document Manager and files selected from the existing WordPress Media Library, without moving files or changing their existing URLs.
- **Security:** Added `.htaccess`, `web.config`, and an empty `index.php` file to the plugin's dedicated uploads directory to prevent direct web access and directory listing on Apache, IIS, and compatible web servers.
- **Security:** Documents continue to stream securely from local disk through the plugin rather than being proxied directly through the protected uploads directory.
- **Security:** Nginx installations should configure an equivalent server-level `location` rule because WordPress plugins cannot modify Nginx server configuration directly.
- **Security:** The "Allowed file extensions" setting is now intersected with the plugin's fixed, audited extension-to-MIME allowlist when settings are saved.
- **Security:** Administrators are now warned when unsupported file extensions are entered or removed from the configured allowlist.
- **Security:** Dangerous file formats, including SVG, PHP, and other executable types, remain excluded from the permitted document upload types.
- **Security:** Hardened stable-link `Content-Disposition` headers against quote, carriage-return, and line-feed characters.
- **Security:** Added explicit `Accept-Ranges` and `X-Content-Type-Options` headers to document responses.
- **Security:** REST metadata responses for Private, Restricted, or signed-link-only documents now include `Cache-Control: private, no-store`, preventing intermediary caches and CDNs from retaining sensitive document metadata.
- **Removed:** Removed the "theme button style" and "full width button" options from the document card shortcode and Frontend settings. Document card buttons now use either Outline or Solid styling.
- **Dev:** Renamed frontend document card CSS classes from `kgx-dm-*` to `kitgenix-document-manager-*` for consistency with other Kitgenix plugins. Sites with custom CSS targeting the previous class names will need to update their selectors.
- **Dev:** Refreshed the Kitgenix logo and icon asset set used across the plugin admin interface, Kitgenix Hub, and WordPress admin menu icon.

## 1.1.3 – 26 May 2026

- **New:** Added a Log tab to the admin settings page. Records recent plugin activity (settings saves and key operations) with timestamps, context labels, and plain-English notes to aid troubleshooting.
- **Fix:** Resolved a missing class file include that would have caused a fatal error when visiting the Log tab.
- **Fix:** Activity log data is now fully cleaned up when the plugin is uninstalled.
- **Compatibility:** Confirmed compatibility with WordPress 7.0.

## 1.1.2 – 26 May 2026

- **Dev:** Skipped to be in line with other Kitgenix Plugins.

## 1.1.1 – 26 May 2026

- **Dev:** Skipped to be in line with other Kitgenix Plugins.

## 1.1.0 – 7 May 2026

- **New:** Added bulk document actions for moving multiple documents to Trash, changing visibility, and assigning or clearing categories from the Documents tab.
- **New:** Added drag-and-drop batch uploads that create one document per file with shared category, visibility, and version-history defaults.
- **New:** Added batch upload status notices so editors can see how many files were created and whether any files were skipped.
- **New:** Added restricted document access with per-document role, capability, and user-ID grants plus expiring signed share links.
- **New:** Added go-live, archive, and expiry controls that hide unavailable documents from stable links, shortcodes, and the REST metadata endpoint.
- **New:** Added document analytics for views, downloads, signed-share activity, and last-access timestamps, including 30-day rollups in the admin UI.
- **Improvement:** Extended the Documents tab, quick edit modal, native metabox, and admin columns to manage restricted access, lifecycle rules, and signed-share URLs in one place.

## 1.0.0 – 18 March 2026

- **New:** Initial release.
- **New:** Added a document card shortcode for embedding a document preview with file type/size and a “View” button, including button label, new-tab behaviour, field toggles, and Outline/Solid button styles.
- **New:** Added a stable document link endpoint at `/kitgenix-document-manager/{slug}/`, allowing the underlying file to be replaced without changing its public URL.
- **New:** Added a Document Manager admin area under the Kitgenix menu with tabs for Documents, Categories, Versions, Settings, and Support.
- **New:** Added an admin Documents table with search and quick actions for copying links, opening links, editing documents, and replacing files.
- **New:** Added a quick edit modal for editing documents without leaving the Documents table.
- **New:** Added Document Categories for creating and managing categories and assigning them to documents.
- **New:** Added per-document visibility controls for Public and Private documents, with configurable private behaviour to redirect to login or return HTTP 403.
- **New:** Added file-serving modes for inline viewing or forced download/attachment, with safer inline handling.
- **New:** Added optional per-document version history, including restore and delete actions for older versions.
- **New:** Added bulk version-cleanup tools for deleting all old versions of a document through the Versions tab and modal actions.
- **New:** Added Media Library selection support so existing WordPress Media Library files can be attached to documents instead of uploading new files.
- **New:** Added settings for allowed file extensions and sensible defaults for visibility, serving mode, and versioning.
- **New:** Added a Site Health test for the Kitgenix Document Manager uploads directory.
- **UI:** Improved the Kitgenix admin header layout and social icon links with compact icon buttons across plugin settings and the Kitgenix Hub.
- **UI:** Admin tables inside Kitgenix pages now use Kitgenix styling for a more consistent branded appearance.
- **Fix:** Admin notices now display above the Kitgenix header using the standard WordPress notice area.
- **Fix:** Added defensive notice normalization to prevent notices being relocated into the header by other scripts.
- **Fix:** Added spacing between adjacent action links/buttons such as Edit/Delete.
- **Fix:** Document streaming now uses validated file paths and safe response headers instead of reading complete file contents into PHP memory before output.
- **Fix:** Escaped shared Kitgenix Hub card media output for WordPress coding standards compliance.
- **Security:** Added safer document serving with path-traversal checks, security headers, and conditional caching for public documents using ETag and Last-Modified headers, while private documents use `no-store`.
- **Security:** Normalised metabox and admin-post request handling so POST values are read only after nonce verification, and uploaded file arrays are sanitised before use.
- **Cleanup:** Added uninstall behaviour that removes plugin settings, with an optional setting to delete all Document Manager data including documents and related attachments/versions.
- **Maintenance:** Updated the plugin Author URI to the public Kitgenix WordPress.org profile and replaced the old custom admin-menu icon CSS with the native Dashicons icon.