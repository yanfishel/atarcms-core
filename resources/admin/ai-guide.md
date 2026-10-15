# AtarCMS admin: a guide for AI agents

This document describes the admin of an AtarCMS site for an AI agent that works in it through a browser on behalf of
a signed-in editor. The first part explains how the admin works; the last part, "This site", is generated for this
site and for the signed-in user: it lists the content types with their fields, the taxonomies, the menus, the
languages and the screens this user may open. Sections of this guide about screens the user has no permission for
are left out. All addresses are paths on the site's own host.

## General rules

- Every screen works without JavaScript: links are plain links and every action is a form. With JavaScript some
  actions open in a dialog (a header, a scrolling body and a footer with the buttons) instead of a separate page;
  the fields and buttons are the same.
- Success and error messages appear as toasts in the stack at the top of the main area (a polite live region).
  Field validation errors appear next to their fields (the input is marked `aria-invalid` and described by its error
  line), and the form shows a summary alert above it; when a form has tabs, a tab with errors shows their count.
  The one other status line is the autosave status of the entry form's action bar ("Saved N minutes ago").
- Every form that changes something is a POST with a hidden `_csrf` token; use the forms on the pages instead of
  building requests yourself.
- Deleting always asks first: a "Delete…" link opens a confirmation (a page, or a dialog with JavaScript) whose
  button sends the POST.
- Buttons that exist but are refused here (for example deleting a section that still has subsections) are shown
  locked: `aria-disabled="true"` with the reason as their `title`.
- The interface language and the content language: on a site with more than one language the top bar has a language
  menu. In the linked mode it switches the interface and the content together (one language per screen); in the
  separate mode it switches only the content language and the interface language is chosen on the Profile.
  Lists and forms of translatable content show the content language's items. `?locale={code}` on an admin address
  opens it in that language and also remembers it as the user's content language for the next screens.

## Layout

- The sidebar on the left (on the right in a right-to-left interface) holds the main menu, in groups: the
  Dashboard, Content (one item per content type), Structure (sections, menus, taxonomies), Files (the media
  library), Modules, and Settings (site settings, users, activity history). Items the user may not open are not
  shown. Below 768px the sidebar is a drawer opened by the menu button at the start of the top bar.
- The top bar holds the breadcrumbs, then the inbox of the contact form (for users who read messages), the colour
  theme menu, the content language menu (multilingual sites only) and the account menu (Profile, Sign out).
- Each screen starts with a page header: its title and its actions (for example "Add new").

## Content entries

The content types of this site are listed in "This site" below; these addresses use `{collection}` for a type's name.

- `/admin/content/{collection}`: the list of a content type's entries in the content language: a table with the
  title, status and dates, sortable headers, filters (status, a taxonomy term, a text search: `?status=`, `?term=`,
  `?q=`), and pagination (`?page=N`). A title opens the entry's form.
- A single content type (one entry per language, such as a home or contacts page) has no list: its address opens the
  entry's form, or the new form when the entry does not exist yet.
- `/admin/content/{collection}/new`: the form of a new entry. `/admin/content/{collection}/{id}`: the form of an
  existing entry.
- `/admin/content/{collection}/order`: the order of a hierarchical content type's entries (a tree with moves and
  drag and drop), offered as "Order" on its list.
- `/admin/content/{collection}/{id}/delete`: the delete confirmation. Fixed pages (singles and preset pages) cannot
  be deleted; otherwise deleting follows the editing rules below (someone else's entry, or one that is not a draft,
  needs the matching right).
- `/admin/content/{collection}/{id}/revisions`: "Earlier versions" of an entry.

### The entry form

- The form has tabs. The main column: "Content" (the title and most fields), "Media" (photos, galleries, files and
  videos) and one tab per slides field. The side column: "Settings" (sections or other terms, tags, the status, the
  publication date, the parent and order of hierarchical entries) and "SEO" (the meta title, the meta description and
  the address, `slug`). Fields of the content type are posted as `fields[{name}]`; the built-in inputs are `title`,
  `status`, `published_at`, `parent_id`, `position`, `meta_title`, `meta_description` and `slug`. Not every form has
  all of them: the meta title and description exist only for content types with a public address, and a single has
  no `slug`.
- A "plain" single (such as a home page) shows only its fields (plus its terms, when the type has taxonomies): no
  title, status, date or SEO inputs. It is always published, so saving it publishes the change.
- A preset page (a fixed page such as a privacy policy) has no status input: it is always published, and its address
  cannot change.
- Status: "Draft" (visible only in the admin), "Published" (visible on the site) or "Scheduled" (goes live on the
  publication date). Only users with the right to publish see the publication date.
- Who may edit: a user edits their own entries, and other people's only with the right to edit others' entries;
  saving an entry that is published or scheduled also needs the right to publish. So a user without those rights (an
  author) edits only their own drafts. Deleting follows the same rules.
- The action bar at the bottom: the save status on the start side, then Delete…, Earlier versions, Preview and Save
  (the primary button). Enter in a text input saves.
- Preview (only for content types with a public address) opens the unsaved form, rendered by the site's theme, in a
  separate browser tab; nothing is saved.
- Autosave: a new entry or a stored draft, while "Draft" is chosen, saves itself 30 seconds after the last change and
  when the page is left. The status line then reads "Saved just now" / "Saved N minutes ago". Published and scheduled
  entries are never autosaved: press Save. Leaving a page with unsaved changes that are not autosaved asks first.
- Rich-text fields use a visual editor (CKEditor); without JavaScript they are a plain textarea of HTML. Media fields
  pick files from the media library in a dialog ("Choose"). Tags fields are a tag box; without JavaScript a plain
  input of comma-separated tags.
- Translations: entries of a translatable content type exist once per site language, linked as translations of each
  other. "Copy for translation" copies an entry into another language as a draft (a plain single or a preset page is
  copied published) to be translated and saved. In the separate language mode the saved entry's form header has a
  "Translations" switch with one segment per site language: an existing translation links to its form, a missing one
  is a "copy for translation" button. In either mode, switching the content language in the top bar while an entry
  is open goes to its translation; when it has none yet, a toast offers "Copy for translation".

### Earlier versions

Saves, creations, translation copies and restores store versions of the entry (a save that changed nothing may store
none); an autosave keeps one running version per author between manual saves. Only the newest versions are kept
(20 by default). The Earlier versions screen lists them (time, author, title); "Restore" saves the version's title,
address, fields, SEO texts and terms back as a new version, keeping the current status and publication.

## Sections
<!-- requires: taxonomies.manage -->

When the site has sections (the structure of the site and its main menu), `/admin/sections` is their tree: each row
has "Order articles", "Add a subsection", Edit and Delete (Delete is locked while the section has subsections or
articles), and the page header has "Add section". Drag and drop or the move buttons reorder the tree; each move saves
at once. A section's name opens its articles. `/admin/sections/{id}/order` orders the articles shown on a section's
page. A section's form has the tabs Main and SEO.

## Taxonomies
<!-- requires: taxonomies.manage -->

When the site has taxonomies (they are listed in "This site"), `/admin/taxonomies/{taxonomy}` lists the terms
(categories, tags…) of a taxonomy in the content language, with "Add new"; `/admin/taxonomies/{taxonomy}/new`,
`/admin/taxonomies/{taxonomy}/{id}` and `/admin/taxonomies/{taxonomy}/{id}/delete` are the term's forms; a
hierarchical taxonomy has `/admin/taxonomies/{taxonomy}/order`. Terms are kept per language, linked as translations;
"Copy for translation" copies a term into another language.

## Menus
<!-- requires: menus.manage -->

When the theme has menus besides the sections (they are listed in "This site"), `/admin/menus` opens the first menu
location, `/admin/menus/{location}` a location's menu in the content language: a tree of items with "Add item", and
per row "Add a subitem", Edit and Delete (in a dialog with JavaScript). An item links an entry, a term (a section, a
category…) or an address; its "Inside" select chooses the parent. Drag and drop or the move buttons reorder the
tree; each move saves at once.

## Media library
<!-- requires: media.upload -->

`/admin/media` is the library: the folder tree beside the files of the current folder (`?folder={id}`). Uploading
(the drop zone or its file input) puts the files into the folder shown and reports each result as a toast. Selecting
files allows moving them to another folder and, for users with the right to delete files, deleting them (deleting
files and folders needs that right; an author can upload and move but not delete). `/admin/media/{id}` edits a file's
alternative texts (per language) and shows where it is used; `/admin/media/folders/new` and
`/admin/media/folders/{id}` create and edit folders. Entry forms choose files from the same library in a dialog,
where files can also be uploaded.

## Messages
<!-- requires: messages.manage -->

`/admin/messages` is the inbox of the site's forms (the contact form), opened from the top bar's inbox icon, which
shows the number of unread messages. With more than one form, links above the list filter it by form (`?form=`).
`/admin/messages/{id}` shows a message and marks it read; it offers Call and Reply links where the sender left a
phone or an e-mail, "Mark as unread" and Delete….

## Site settings
<!-- requires: settings.manage -->

`/admin/settings` is one form with the tabs General, SEO and Integrations and an Images card (the logo, the site
icon and the sharing image). Fields marked with a language code are kept per language (the content language of the
top bar); the others are site-wide. One Save stores both.

## Users
<!-- requires: users.manage -->

`/admin/users` lists the users with "Add new" (`/admin/users/new`); `/admin/users/{id}` edits a user's name, role and
status (active or disabled), and `/admin/users/{id}/delete` deletes one. There are no passwords: users sign in with a
sign-in link or Google. Nobody changes their own role or status or deletes themselves.

## Activity history
<!-- requires: audit.view -->

`/admin/audit` lists who did what and when (sign-ins, saves, deletions, settings changes…), newest first, 50 per
page.

## Profile

`/admin/profile` (the account menu) shows the signed-in user and stores their own preferences: the admin colour and,
in the separate language mode, the interface language.
