=== Kitgenix Document Manager ===
Contributors: kitgenix
Donate link: https://www.paypal.com/donate/?hosted_button_id=KALF36K6JJ9B2
Tags: document management, document library, secure downloads, file sharing, access control
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 2.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Manage WordPress documents with stable links, access control, signed sharing, versions, scheduling and local analytics.

== Description ==

**Kitgenix Document Manager** is a WordPress document management and document library plugin for publishing, protecting, replacing, sharing and tracking downloadable files. It adds a managed document layer around WordPress attachments so a document can keep one stable URL while the physical file behind it changes over time.

This is useful for policy documents, product manuals, technical downloads, specification sheets, price lists, application forms, certificates, reports, staff resources, membership files and other documents that need more control than a raw Media Library URL.

A published document uses a stable route similar to:

`/kitgenix-document-manager/{slug}/`

Replace the current attachment later and the document route can remain the same, so old links in pages, emails, QR codes, bookmarks and external documentation do not need to be replaced.

Built by [Kitgenix](https://kitgenix.com/).

= Document Management Features =

* Stable managed URLs independent of the current physical attachment URL.
* Public, Private and Restricted visibility modes.
* Access rules using WordPress roles, capabilities and specific user IDs.
* Time-limited signed share links for people without a WordPress account.
* Optional maximum-use limits on signed links.
* Revoke and signing-secret regeneration controls.
* Available-from, archive and expiry scheduling.
* Optional document version history.
* Restore or delete historical versions.
* Hierarchical document categories.
* Batch upload and bulk administration tools.
* Existing Media Library attachment selection.
* Frontend document-card and text-link shortcodes.
* Admin Shortcode Builder for locating documents and generating markup.
* Local aggregate document views/download analytics.
* REST metadata access subject to document permissions.
* Single HTTP byte-range support for compatible downloads/media seeking.
* Conditional ETag/Last-Modified responses for eligible public files.
* Attachment access guards for protected managed files.
* Site Health test for the dedicated document upload directory.
* Settings/category portability without exporting protected files or share secrets.
* Capped local event log for operational diagnostics.

= Stable WordPress Document Links =

The plugin separates the logical document from the current file. Pages and users link to the document's stable endpoint; the document record then decides which attachment is current and whether the requester is allowed to receive it.

This model is particularly useful for frequently revised documents. A "Product Manual" or "Terms and Conditions" link can remain consistent while administrators replace the actual PDF or Office file as revisions are released.

= Public, Private and Restricted Documents =

Each document has a visibility mode and can also carry more granular rules. Access can be evaluated using WordPress roles, a required capability, explicitly allowed user IDs or a valid signed share link.

The same access decision is applied across the managed delivery endpoint and the plugin's own output paths. The code also includes guards designed to prevent protected managed attachments being casually exposed through Media REST responses or normal attachment permalinks to a visitor who does not have document access.

For unauthenticated protected requests, the configured behaviour can send the visitor to login or return a forbidden response according to the site setting.

= Signed File Sharing =

A signed URL can grant temporary access to a protected document without creating a WordPress account for the recipient. Signed links can have an expiry time and an optional maximum-use count.

Administrators can revoke a share link or regenerate the document's signing secret. The signature is bound to document-specific values including the current attachment and document slug, so changing the underlying file affects the validity context rather than creating a permanent backdoor to every future version.

= Lifecycle Scheduling =

Documents can have an **Available from** time, an **Archive at** time and an **Expires at** time. These fields allow a file to become available automatically or to stop normal access without an administrator having to remember the exact campaign/compliance deadline.

The lifecycle layer can represent states such as Draft, Scheduled, Live, Restricted, Archived or Expired. An hourly WordPress cron event checks scheduled documents so state transitions can be observed even when the document is not being requested continuously.

= Version History =

When versioning is enabled, replacing a document can retain the superseded attachment as a historical version. Version records can include a label or note, and authorised users can restore an older file or delete old versions.

Stores/sites that do not want historical copies can disable version retention and configure replacement behaviour accordingly.

= Document Library Organisation =

Documents are stored in a private plugin post type and can be grouped with the plugin's hierarchical document-category taxonomy. The plugin's own admin screens provide search and filters for document status, visibility and file type, together with batch upload and bulk actions.

Bulk operations can change visibility, assign or clear categories, archive documents, clear lifecycle schedules or move selected documents to the trash depending on the action chosen.

= Frontend Shortcodes =

A lightweight link can be rendered with:

`[kitgenix_document_manager_link slug="my-document"]`

A card can be rendered with:

`[kitgenix_document_manager_document slug="my-document"]`

The card shortcode can also display several comma-separated document slugs and supports presentation attributes for button labels/styles, image size, alignment, target behaviour and the visibility of file metadata/description.

Protected documents are not revealed by the shortcode to visitors who fail the document's access rules.

= Local Document Analytics =

The plugin records aggregate counts for views/downloads, signed-link usage and recent daily activity in WordPress metadata. It also retains the most recent access timestamp/type for administrative reporting.

The analytics implementation does not create visitor profiles and does not intentionally store visitor IP addresses, user agents, referrers or tracking cookies. Older daily buckets are pruned while cumulative totals remain available.

= File Delivery, Range Requests and Caching =

Managed files are served through WordPress after access checks. The endpoint supports single byte-range requests, which can help resumable downloads and seeking in compatible browser-displayed content.

Eligible public responses can use ETag and Last-Modified revalidation. Protected responses are handled with private/no-store style cache controls so a shared intermediary is not encouraged to cache restricted content.

= REST and Site Health =

A plugin REST route can return permitted document metadata by slug. Permission/access checks still apply; it is not intended as an anonymous route for enumerating protected documents.

The Site Health integration checks the dedicated Document Manager uploads directory and can flag directory/writability problems that would prevent file management from working correctly.

= Settings Portability =

Administrators can export/import plugin configuration and document-category structure as JSON. This portability workflow deliberately does not package the document files themselves, signed-link secrets or analytics history.

= Typical Uses =

* Product manuals and technical PDFs that receive frequent revisions.
* Policy, compliance and governance documents with expiry dates.
* Member-only or staff-only downloads.
* Customer documents restricted by WordPress account/role.
* Temporary secure sharing with an external recipient.
* Download libraries organised by document category.
* Stable links for QR codes, printed brochures and long-lived email templates.

== Installation ==

1. Install and activate **Kitgenix Document Manager** through WordPress, or upload the plugin folder to `/wp-content/plugins/`.
2. Go to **Kitgenix → Document Manager**.
3. Add a document and upload a permitted file, or select an existing Media Library attachment.
4. Choose its visibility and, if required, configure role/capability/user access, signed sharing, version history, and lifecycle dates.
5. Copy the stable document link or use one of the included shortcodes on a page or post.
6. If a stable document URL returns 404 after activation or migration, visit **Settings → Permalinks** and save the page once to refresh rewrite rules.

== Frequently Asked Questions ==

= Does replacing a document change its public link? =

No. The stable document URL points to the document record rather than directly to a particular attachment. Replacing the current file keeps the same stable URL.

= Can I restrict documents to logged-in users? =

Yes. Set the document to a protected visibility mode. You can also narrow access using roles, a required capability, or specific user IDs.

= Can I share a protected file with somebody who does not have an account? =

Yes. Generate a signed share link with an expiry date. You can optionally set a maximum-use count, revoke the link, or regenerate the signing secret to invalidate previously issued links.

= Can documents automatically go live or expire? =

Yes. Each document can have an available-from time, archive time, and expiry time. Access checks enforce those lifecycle dates across the stable URL, shortcode output, and plugin REST endpoint.

= Can I keep old versions when I replace a file? =

Yes. Version history can retain old attachments. Saved versions can have optional labels/notes and can later be restored or deleted.

= Can I display several documents together? =

Yes. Use the `slugs` attribute on `[kitgenix_document_manager_document]` with a comma-separated list of document slugs.

= Does the plugin support PDFs? =

Yes. PDF is allowed by default and can be served inline when the global serving mode is set to inline. Browser support determines whether a compatible file is displayed or downloaded.

= Is WooCommerce required? =

No. The plugin works without WooCommerce. If WooCommerce is active, the Shop Manager role is granted the Document Manager capability on activation so store managers can be permitted to manage documents.

= Are existing Media Library files fully hidden from their original URL? =

Not necessarily. Existing Media Library attachments remain in their original location. The plugin guards their WordPress REST-media exposure and attachment permalink when access is denied, but an already-known raw uploads URL may still be directly reachable depending on server configuration. See **Security and File Protection** above.

= What is included in settings export/import? =

Plugin settings and document categories. Documents, uploaded files, analytics, and signed-link secrets are intentionally excluded.

= What happens when I uninstall the plugin? =

Plugin settings/log data are removed during uninstall. Document posts and managed attachments are retained by default. If **Delete data on uninstall** is enabled before uninstalling, the plugin also deletes its document records and associated current/version attachments.

== REST API and Developer Hooks ==

The plugin registers this read-only metadata route:

`GET /wp-json/kitgenix-document-manager/v1/doc/{slug}`

For an allowed request, the response includes the document slug, title, modified date, visibility, schedule status, lifecycle state, and stable URL. The endpoint applies Document Manager access checks before returning metadata.

Available extension points include:

* `kitgenix_document_manager_user_has_document_access` – provide a custom access decision after the built-in role/capability/user checks have been evaluated.
* `kitgenix_document_manager_share_link_url` – filter a generated signed share URL.
* `kitgenix_document_manager_rest_document_response` – customise an allowed REST metadata response.
* `kitgenix_document_manager_document_served` – runs after a document file has been successfully streamed.
* `kitgenix_document_manager_lifecycle_status_changed` – runs when the effective lifecycle state changes after an existing state has previously been observed.

Internal storage uses the private `kitgenix_dm_doc` post type and the hierarchical `kitgenix_dm_cat` taxonomy. These implementation details are provided for developers and should not be treated as permanent public URLs.

== External Services ==

The plugin contains two external-service/network behaviours that can occur in wp-admin. They are not used to upload or process your document files.

= WordPress.org Plugin API =

The shared Kitgenix Hub can request public information about Kitgenix plugins from WordPress.org through WordPress core's `plugins_api()` function. These requests are used for information shown in the Kitgenix admin interface and are cached locally with WordPress transients.

Data sent: plugin slugs required to look up public plugin-directory information. The plugin does not intentionally include document contents, document access rules, Document Manager analytics, or visitor information in these API lookups.

Service: https://wordpress.org/plugins/
Terms: https://wordpress.org/about/terms/
Privacy: https://wordpress.org/about/privacy/

= Google Fonts =

The Kitgenix admin interface stylesheet imports the Inter and Manrope font families from Google Fonts. When an authorised administrator opens a plugin screen that loads this stylesheet, the administrator's browser may contact `fonts.googleapis.com` and `fonts.gstatic.com` to retrieve font CSS/files. As with ordinary web requests, Google may receive technical request information such as the requesting IP address and browser/network headers.

Google Fonts is used for admin-interface typography only; it is not required for document delivery and the plugin does not send document files to Google Fonts.

Service: https://fonts.google.com/
Google Fonts privacy information: https://developers.google.com/fonts/faq/privacy
Google Terms: https://policies.google.com/terms
Google Privacy Policy: https://policies.google.com/privacy

The plugin includes links to Kitgenix documentation, support, community/social pages, WordPress.org reviews/support, and PayPal donation pages. Those destinations are only contacted if an administrator chooses to open the relevant link; Document Manager does not automatically upload document data to Kitgenix.

== Privacy ==

Kitgenix Document Manager stores its operational data in the site's own WordPress database and uploads directory.

Document analytics are aggregate counters and timestamps. The plugin's analytics implementation does not store visitor IP addresses, user agents, referrers, cookies, or a per-visitor identifier.

The local activity log stores a timestamp, event context, outcome, a plain-English note, and an optional event code. It is capped at 100 entries. The log does not intentionally store IP addresses or document file contents.

Document access configuration may contain WordPress role names, a required capability, and WordPress user IDs selected by the site administrator. Version attribution uses the WordPress attachment author's existing account/display-name information.

Signed sharing stores a per-document secret, expiry information, maximum-use setting, and use counter in WordPress post meta. The signing secret is not included in the settings/category export.

See **External Services** for the network connections made by the admin interface.

== Screenshots ==

1. Document library management with stable links, lifecycle status, visibility, file information, search, and filters.
2. Add/Edit Document controls for description, visibility, access presets, roles, capabilities, specific users, signed sharing, schedules, and versioning.
3. Batch Upload for creating multiple document records with shared visibility, category, and versioning defaults.
4. General Settings for allowed file types, default visibility, serving mode, version behaviour, and import/export.
5. Frontend settings.
6. Shortcode Builder for document links/cards and card presentation options.
7. Frontend document-card output with preview/icon, title, optional description, file metadata, and action button.

== Support and Development ==

Documentation and support resources are available from [Kitgenix](https://kitgenix.com/) and from the plugin's WordPress.org support forum.

If the plugin is useful to your site, you can also use the Donate link at the top of this readme to support continued maintenance and development.

== Upgrade Notice ==

= 2.0.0 =
Adds signed-link limits/revocation, lifecycle states and scheduling events, redesigned administration, HTTP Range downloads, stronger attachment-access protection, and additional security hardening. Review the server-protection notes if you use Nginx or existing Media Library files.

== Changelog ==

= 2.0.0 (31 August 2026) =

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
