# YouTube Script: Kitgenix Document Manager

**Suggested video title:** Stop Sharing Raw PDF Links in WordPress: Secure Document Management with Stable Links (Kitgenix Document Manager)

**Alternative titles:**
- How to Protect, Schedule and Update WordPress Documents Without Breaking Links
- Member-Only Downloads, Expiring Share Links and Version History in WordPress

**Estimated runtime:** 11 to 13 minutes (about 2,150 spoken words). To bring it under 11 minutes, trim Section 10 (For Developers) and Tip 6.

**Plugin version covered:** 2.1.0 (requires WordPress 6.0+ and PHP 8.1+)

---

## 1. Hook

[ON SCREEN: A WordPress Media Library item open, with the "File URL" field highlighted: `/wp-content/uploads/2026/03/staff-handbook.pdf`.]

Here's a link to a PDF on a WordPress site. Now, who can open it?

[ON SCREEN: The same URL pasted into a private/incognito browser window. The PDF opens instantly.]

Anyone. Anyone it gets forwarded to, anyone who finds it in a search result, anyone who guesses the folder name. There's no login, no expiry, no way to take it back.

[ON SCREEN: Split screen: an email, a printed brochure with a QR code, and a web page, all pointing to `handbook-v1.pdf`. A new file `handbook-v2.pdf` appears and red "404" stamps land on each one.]

And the day you update that handbook? You upload a new file, it gets a new URL, and every link in every email, page and QR code is now pointing at the old version, or at nothing at all.

If that sounds familiar, stick around. We're going to fix both of those problems in about ten minutes.

## 2. Intro

[ON SCREEN: Kitgenix logo animation, then the plugin banner for Kitgenix Document Manager.]

Hi, we're Kitgenix, and this is Kitgenix Document Manager, a free WordPress plugin for publishing, protecting, replacing, sharing and tracking downloadable documents.

[ON SCREEN: Quick montage of use cases with labels: "Membership downloads", "Staff resources", "Course workbooks", "Product manuals", "Policies & contracts".]

It's built for membership sites, internal teams, course creators, and any business that hands out manuals, policies, contracts, price lists or guides, and needs more control than a raw Media Library link can give them.

Here's the promise in one sentence: every document gets one stable link that never breaks, and you decide exactly who can open it, and when.

## 3. Why We Built This

[ON SCREEN: Diagram: "Media Library file" on the left with an arrow straight to "Anyone with the URL".]

WordPress's Media Library is great for images. But for documents, it has a gap. Once a file is uploaded, it lives at a fixed public address. There's no built-in concept of "only members can open this", no "this goes live on Monday", no "this stops working at the end of the quarter", and no way to swap the file without changing the address.

[ON SCREEN: Second diagram: "Stable link" in the middle, with "Document record" behind it deciding (1) which file is current and (2) whether this visitor is allowed in.]

So we separated the *document* from the *file*. People link to the document. The document then decides which file is current right now, and whether the person asking is allowed to have it. Everything in this video builds on that one idea.

## 4. Installing & Activating

[ON SCREEN: WordPress admin, Plugins → Add New. Search "Kitgenix Document Manager". Click Install, then Activate.]

Installing is the standard WordPress routine. Head to Plugins, Add New, search for "Kitgenix Document Manager", install, and activate.

[ON SCREEN: The left admin menu. Hover the "Kitgenix" menu and click "Document Manager".]

You'll find it under the Kitgenix menu, then Document Manager. If you run other Kitgenix plugins they all live together under this same menu.

[ON SCREEN: The Document Manager screen with its topbar tabs: Documents, Categories, Versions, Settings, Frontend, Log, Support.]

Everything happens from this one screen. Along the top you've got Documents, Categories, Versions, Settings, Frontend, the Log, and Support.

[ON SCREEN: Callout box: "Stable link returning a 404? Go to Settings → Permalinks and click Save once."]

One quick tip before we start: if a document link ever returns a 404 straight after activating, go to Settings, Permalinks, and just click Save once. That refreshes WordPress's rewrite rules.

## 5. Uploading Your First Document

[ON SCREEN: Documents tab, scroll down to the "Add Document" card.]

Let's add our first document. On the Documents tab, scroll down to the Add Document card.

[ON SCREEN: Type "Member Starter Guide" into Name. The Slug field fills itself in as "member-starter-guide".]

Give it a name. Watch the slug field: it fills itself in as you type. That slug is important, and we'll come back to it in a second. You can also add a short description, which can show up on the frontend later.

[ON SCREEN: Click the file input and choose `starter-guide.pdf`. Then briefly show the alternative "Select from Media Library" button.]

Now attach the file. You can upload a new one, or pick something that's already in your Media Library. Out of the box the plugin accepts PDF, Word, Excel, PowerPoint and plain text files.

[ON SCREEN: Callout: "Sensitive document? Upload it fresh rather than picking from the Media Library."]

A quick but important note. Files you upload here go into the plugin's own protected folder. Files you pick from the Media Library stay exactly where they already are. The plugin blocks them from WordPress's media API and attachment pages, but if someone already knows that old raw URL, it may still work depending on your server. So for anything genuinely sensitive, upload it fresh.

[ON SCREEN: Click "Save". The new document appears in the All Documents table at the top. Hover the row and click "Copy link". Show the URL: `yoursite.com/kitgenix-document-manager/member-starter-guide/`.]

Save it, and here it is in the All Documents list. Click "Copy link" and look at the address: your site, then `kitgenix-document-manager`, then the slug.

That's your **stable link**. It doesn't point at a file. It points at the document. Which means later, when you upload a new version of this guide, this exact link keeps working and simply serves the new file. Put it in emails, print it on a QR code, bookmark it. It won't break.

## 6. Access Control Walkthrough

[ON SCREEN: Open the Edit modal for "Member Starter Guide". Highlight the Visibility dropdown showing Public / Private / Restricted.]

Now for the part that makes this more than a link shortener: who's actually allowed in. Every document has a visibility setting with three options.

[ON SCREEN: Select "Public". Caption: "Anyone with the link".]

**Public** means anyone with the link can open it. Perfect for a brochure or a public price list.

[ON SCREEN: Select "Private". Caption: "Any logged-in user".]

**Private** means the visitor has to be logged in. Any logged-in account will do.

[ON SCREEN: Switch to the Settings tab, highlight "Private document behavior" with options "login" and "403".]

What happens to someone who isn't logged in is up to you. In Settings, "Private document behavior" either sends them to the login page or shows them a "forbidden" response.

[ON SCREEN: Back to the modal. Select "Restricted". Highlight the three fields: Allowed roles, Required capability, Allowed user IDs.]

**Restricted** is where it gets precise. You've got three ways to say who's allowed: pick one or more **roles**, like Editor or a membership role; require a specific **capability**; or list specific **user IDs** for named individuals. A visitor only needs to match one of those rules to get in.

[ON SCREEN: Callout: "Restricted with no rules filled in = any logged-in user. Add at least one rule."]

One thing to watch: if you choose Restricted but leave all three fields empty, it behaves like Private, so any logged-in user gets in. Always fill in at least one rule.

[ON SCREEN: Click the "Access preset" dropdown above the fields: Public, Logged-in Users, Administrators Only.]

If you want a shortcut, the **Access preset** dropdown fills these fields in for common setups: Public, Logged-in Users, or Administrators Only. It only pre-fills, so you can still adjust everything before you save.

[ON SCREEN: Scroll to "Signed share link expiry". Pick a date one week ahead, set "Max uses" to 5, and save. A signed URL appears with Copy, Revoke and Regenerate buttons.]

But what about someone who doesn't have an account at all? An accountant, a client, a supplier? That's what **signed share links** are for. Set an expiry date, and the plugin generates a special signed URL that lets the holder in until that time. Optionally, cap it with a maximum number of uses. Here we've said five.

[ON SCREEN: Highlight "Revoke", then "Regenerate", with captions: "Kill this link" and "Kill every link ever issued".]

And you stay in control. **Revoke** switches off the current link immediately. **Regenerate** rotates the document's signing secret, so every link you've ever issued for that document stops working, even ones that hadn't expired yet.

## 7. Scheduling & Lifecycle

[ON SCREEN: In the edit modal, highlight the three date fields: Available from, Archive at, Expires at, and the live schedule-status badge beside them.]

Next, timing. Every document has three optional dates: **Available from**, **Archive at**, and **Expires at**.

[ON SCREEN: Set "Available from" to next Monday 9:00. The badge changes to "Scheduled".]

Set "Available from" and the document goes live on its own at that time. Great for a launch, an exam paper, or next year's price list. Set "Archive at" or "Expires at" and it stops being served automatically. No more remembering to take down last season's promo or an out-of-date policy.

[ON SCREEN: Callout: "Archive/expiry dates must come after the available-from date."]

The plugin checks scheduled documents every hour in the background, so changes are picked up and logged even if nobody is clicking the link.

[ON SCREEN: The All Documents table, with coloured lifecycle badges on different rows. Each state appears as it's mentioned.]

All of this rolls up into a **lifecycle badge** on every row, so you can see where each document stands at a glance:

- **Live**: it's available, and anyone with the link can open it.
- **Restricted**: it's available, but only to the people your Private or Restricted rules allow.
- **Scheduled**: it isn't live yet. Visitors get a "not found" response until the go-live time.
- **Archived** and **Expired**: its time is up. Visitors get a "gone" response.
- **Unavailable**: there's no file attached yet, so there's nothing to serve.

[ON SCREEN: Callout: "Schedules are checked first. Even an admin-shared signed link won't open a Scheduled or Expired document."]

One important detail: the schedule is checked before anything else. A signed share link won't open a document that's scheduled, archived or expired.

## 8. Sharing Documents on Your Site

[ON SCREEN: The block editor on a page called "Resources". Add a Shortcode block.]

You can share the stable link directly, but most of the time you'll want documents on your pages. There are two shortcodes for that.

[ON SCREEN: Type `[kitgenix_document_manager_link slug="member-starter-guide"]`. Preview shows a plain text link.]

The first is the **link** shortcode. Give it the slug and you get a simple text link. Add a `label` attribute if you want different wording.

[ON SCREEN: Type `[kitgenix_document_manager_document slug="member-starter-guide"]`. Preview shows a card with an icon, title, file type, file size and a "View" button.]

The second is the **document card**: an icon or preview, the title, optional file type, size and description, and a button. Swap `slug` for `slugs` with a comma-separated list, and you get a neat responsive grid of cards.

[ON SCREEN: Logged-out browser window on the same page. The protected document's card simply isn't there.]

Here's the clever bit: both shortcodes respect your access rules. If a visitor isn't allowed to see a document, it simply doesn't appear for them. Nothing hints that it exists. As an admin, you'll see a small note instead, so you know why it's hidden.

[ON SCREEN: Frontend tab. Show the card settings (button label, Outline/Solid style, alignment, colours), then the Shortcode Builder: search a title, click it, click "Copy shortcode".]

Don't want to type shortcodes? On the Frontend tab, set your card defaults, like button label, outline or solid style, alignment and colours, then use the **Shortcode Builder**: search for a document, pick it, copy the shortcode.

[ON SCREEN: Back on the Documents tab. Type into the search box, then use the Status, Visibility and File Type filters. Then select several rows and open the Bulk actions menu.]

Back in the admin, the All Documents list has a search box and filters for Status, Visibility and File Type. You can tick several documents and use **bulk actions** to change visibility, assign a category, archive them now, clear their schedules, or move them to the trash. You'll also see each document's view and download counts. They're simple totals, with no tracking of individual visitors.

## 9. Updating a Document (Versioning)

[ON SCREEN: Open the edit modal for "Member Starter Guide". Highlight the "Keep version history" toggle and the "Version label" field.]

Now the moment that started all of this: the file needs updating.

[ON SCREEN: Choose `starter-guide-v2.pdf`, type "2026 edition" into Version label, and save.]

Open the document, choose the new file, and optionally give it a version label, like "2026 edition". Save.

[ON SCREEN: Paste the same stable link into a browser. The new v2 PDF opens.]

And the stable link? Exactly the same. Same address, now serving the new file. Every email, every page, every QR code is instantly up to date.

[ON SCREEN: Versions tab showing the document with two entries, who replaced each one and when, plus "Restore" and "Delete" buttons.]

If "Keep version history" is on, the old file isn't thrown away. On the Versions tab you can see every version, who uploaded it and when, and restore an older one with a click, or clean up old versions you no longer need. If you'd rather not keep old copies, switch history off for that document.

[ON SCREEN: Callout: "Replaced the file? Issue a fresh signed share link."]

One thing worth knowing: signed share links are tied to the specific file they were issued for. So after a replacement, send out a fresh signed link. That's deliberate, so an old link can't quietly become a key to every future version.

## 10. For Developers

[ON SCREEN: A terminal or browser showing `GET /wp-json/kitgenix-document-manager/v1/doc/member-starter-guide` returning JSON with slug, title, updated, visibility, schedule_status, lifecycle_state and stable_url.]

A quick word for developers. There's a read-only REST route at `/wp-json/kitgenix-document-manager/v1/doc/` followed by the slug. It returns a document's metadata: its title, last-updated date, visibility, schedule and lifecycle state, and its stable URL. That's handy for headless front ends or JavaScript widgets.

It applies the same access checks as everything else, so a protected document's details aren't handed to someone who isn't allowed to see them. It doesn't return the file itself; the file always goes through the stable link.

[ON SCREEN: List of hook names on screen.]

There are also hooks for custom access decisions, for reacting when a document is served, and for reacting when a document's lifecycle state changes. They're all listed in the documentation.

## 11. Real-World Example

[ON SCREEN: Title card: "Example: A paid members' guide, plus a one-week promo link".]

Let's put it all together. Say you run a membership site, and you've written "The Complete Pricing Playbook" for paying members only.

[ON SCREEN: Add Document. Name: "Pricing Playbook". Slug auto-fills: "pricing-playbook". Upload `pricing-playbook.pdf`. Category: "Member Resources".]

Step one: add the document, upload the PDF fresh so it's stored in the protected folder, and file it under a "Member Resources" category.

[ON SCREEN: Visibility: Restricted. Allowed roles: select the paid-member role from the list (labelled e.g. "Premium Member").]

Step two: set visibility to Restricted, and under Allowed roles, pick the role your membership plugin gives paying members. Free accounts and logged-out visitors are now locked out.

[ON SCREEN: Available from: the 1st of next month, 9:00. Badge shows "Scheduled".]

Step three: you're announcing it on the first of the month, so set "Available from" to 9am that day. Until then, it's simply not there.

[ON SCREEN: Members-area page with `[kitgenix_document_manager_document slug="pricing-playbook"]`. Then the same page viewed as a premium member (card visible) and as a free member (card absent).]

Step four: drop the card shortcode onto your members' area page. Paying members see a tidy download card. Everyone else sees nothing.

[ON SCREEN: Signed share link expiry: seven days after launch. Max uses: 100. Copy the signed URL and paste it into a newsletter draft.]

Step five: for launch week, you want to give non-members a taste. Set a signed share link that expires in seven days, capped at a hundred uses, and drop it into your newsletter. Non-members can open it without an account, and when the week is up, or the hundred uses are gone, it stops working.

[ON SCREEN: Two months later. Edit modal: upload `pricing-playbook-v2.pdf`, version label "Q3 update". Same stable link opens v2 for a premium member.]

And two months later, when you revise the playbook? Upload the new version, label it "Q3 update", and every member link keeps working, now pointing to the new edition.

## 12. Tips & Best Practices

[ON SCREEN: Numbered tips appear one at a time.]

Let's finish with a few tips.

[ON SCREEN: Tip 1: "Choose good slugs up front".]

One: choose your slugs carefully at the start. The slug is the permanent part of the link, so keep it short, clear, and free of version numbers. Use "staff-handbook", not "staff-handbook-v3".

[ON SCREEN: Tip 2: "Upload sensitive files fresh, and check your server".]

Two: upload sensitive documents directly rather than reusing Media Library files. And if your site runs on Nginx, add a rule to block direct access to the plugin's uploads folder. Apache and IIS are protected automatically, but WordPress plugins can't change Nginx settings.

[ON SCREEN: Tip 3: "Restricted = at least one rule".]

Three: Restricted documents should always have at least one role, capability or user ID set.

[ON SCREEN: Tip 4: "Short expiries + max uses for signed links".]

Four: keep signed links short-lived, and use max uses for anything sensitive. If a link leaks, Revoke it. If you're not sure how many are out there, Regenerate.

[ON SCREEN: Log tab, showing the searchable table with Time, Context, Outcome and Note columns, the retention selector (7/14/30 days), and the reference table.]

Five: keep an eye on the **Log** tab. It records things like failed uploads with the actual reason, rejected share links (expired, rotated or tampered), lifecycle changes and settings saves, all in plain English. It keeps the most recent hundred entries and can auto-delete older ones after 7, 14 or 30 days.

[ON SCREEN: Tip 6: "Moving to a new site? Settings → Import/Export".]

And six: if you're setting up another site, Settings has an Import/Export card for your settings and categories. It deliberately leaves out documents, files, access rules, share secrets and analytics, so private content can't end up in an export by accident.

## 13. Recap & Call to Action

[ON SCREEN: Recap checklist animating in: Stable links ✓, Public / Private / Restricted ✓, Signed share links ✓, Scheduling ✓, Version history ✓, Shortcodes ✓, REST metadata ✓, Event Log ✓.]

So let's recap. With Kitgenix Document Manager, every document gets a stable link that survives file updates. You control access with Public, Private, or Restricted by role, capability or specific user, and you can hand outsiders a signed link that expires on its own. Documents can go live and retire on schedule, old versions are kept if you want them, and two shortcodes put it all on your pages without exposing anything to the wrong people.

[ON SCREEN: WordPress.org plugin page and the kitgenix.com URL.]

It's free on WordPress.org. Install it, add your first document, and replace that raw PDF link today. You'll find the documentation and support at kitgenix.com.

[ON SCREEN: Subscribe button animation, plus end-screen cards for other Kitgenix plugin videos.]

If this helped, give the video a like and subscribe for more WordPress walkthroughs from us. And if the plugin saves you a headache, a quick review on WordPress.org really helps. Thanks for watching, and we'll see you in the next one.
