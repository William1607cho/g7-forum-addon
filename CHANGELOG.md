# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.1] - 2026-09-17

### Security

- **Board read permission is now enforced on every add-on endpoint.** The
  visibility guard checked existence, board active state, blinded/deleted status
  and secret posts, but never the board's own read permission
  (`sirsoft-board.{slug}.posts.read`). As a result a guest could call
  `GET /posts/{id}/meta` on a post in a board they cannot read and get `200`
  (post existence, pin/lock flags, reaction counts and — on a forum board — the
  accepted answer's content and author). The guard now performs the same check
  `sirsoft-board`'s route middleware does, before the blinded/secret checks, and
  answers the same way: **`401` for guests, `403` for signed-in users** (never
  `401` to a signed-in user, which the front-end would treat as an expired
  session). This applies to `/meta`, lock/unlock, reactions and
  accept/unaccept, which all share the guard.

### Fixed

- `GET /boards/{slug}/list-meta` now applies the same board read-permission check
  (guest `401` / member `403`). Previously a forum board without guest read
  access would still return participants and last-activity times.
- Unchanged: missing posts / inactive boards stay `404`, and secret posts stay
  author-only (`403` for everyone else).

## [1.1.0] - 2026-09-11

### Added

- **Accepted-answer content box** — the `board/show` widget now shows the full,
  formatted content of the accepted answer (bold, lists, block quotes, tables —
  anything `g7-comment-editor` can produce) together with the author's avatar,
  name and timestamp, right where the "accepted answer present" summary used to
  be a text-only line. `/meta` gained an `accepted_reply` field
  (`{ id, content, author, created_at, created_at_formatted }`) alongside the
  existing `accepted_reply_id`. Rendering reuses the exact same pipeline as
  ordinary comment bodies (a `text` binding, escaped by the template engine,
  then upgraded client-side by `g7-comment-editor`'s existing whitelist
  sanitizer) instead of any raw-HTML path, so it inherits the same XSS defense
  rather than opening a second one. The box's colors are a fixed green palette
  for now (see Known issues).

- **Board-list participants + last activity** — the board index (list) page
  gets a new "Participants" column (up to 5 recent commenters/author, avatar
  stack) and, on forum boards only, the "Created" column is relabeled "Last
  activity" and shows the most recent comment/post timestamp instead of the
  post's creation date. Backed by a new batched endpoint,
  `GET boards/{slug}/list-meta?post_ids=...` (one query pair for a whole page,
  no N+1), gated to forum boards and re-checking per-post visibility
  (secret/blinded/deleted) before including a row.

- **Replies default-expanded on forum boards** — on forum boards, a comment's
  reply thread now starts expanded instead of collapsed; the "N replies"
  toggle still works normally (and still defaults to collapsed everywhere
  else). Implemented by conditioning the existing `_local`-state expand/collapse
  expressions on `board.type === 'forum'` at layout-compose time — no new
  client state, no dependency on how fast the post data itself loads.

### Fixed

- **Accepted-answer box not clearing on comment delete** — deleting the
  accepted comment already cleared the backing state immediately server-side,
  but the widget box stayed on screen until the next full page load, because
  the shared post/comment delete confirmation dialog only re-fetched the post
  data source, not the forum sidecar (`forum_meta`) the box reads from. Fixed
  by locating that dialog's existing "refetch post on comment delete" action
  by its structural signature and adding a sibling step for `forum_meta`,
  again through the `after_apply` layout hook — no core template file
  touched.

### Changed

- **Comment-editor dependency raised to `>= 1.1.0`.** The accepted-answer box
  can display tables, and table markup (`<table>`/`<tr>`/`<td>`/…) is only
  preserved by `g7-comment-editor`'s sanitizer starting at its `1.1.0`
  release — on `1.0.0` a table inside an accepted answer would be stripped
  down to nothing. If you already run `g7-comment-editor >= 1.1.0` for its own
  sake, this changes nothing for you.

### Known issues

- **Accepted-answer box colors are fixed** (a green palette hard-coded in
  `BoardShowWidgetListener::acceptedReplyBox()`) — there is no settings UI to
  change them yet.
- **Board-list sort order is unaffected** by "last activity" — the column
  display changes, but posts are still paginated/sorted by the core's existing
  rules (`sirsoft-board` exposes no hook to change list ordering), so a board
  is not actually "sorted by most recent activity."
- **Removal order** is still not enforced by the core (carried over from
  1.0.0): the reverse-dependents guard
  (`PluginRepository::findActiveByPluginDependency`) does not recognise
  plugin → plugin dependencies, so `g7-comment-editor` can be deactivated while
  `g7-forum-addon` is still active without a warning. Remove in order:
  `g7-forum-addon` first, then `g7-comment-editor`.

## [1.0.0] - 2026-09-11

### Added

- Initial public release. A Gnuboard7 plugin that adds a `forum` board type to
  `sirsoft-board` and layers forum features on top **without modifying
  `sirsoft-board` or the template** — only through its own tables, its own API
  (`/api/plugins/g7-forum-addon/*`), and the `core.layout_extension.after_apply`
  filter hook.

- **Forum board type** — `install()` adds a `forum` row to `board_types`, kept alive
  across re-seeds by a `seed.sirsoft-board.board_types.translations` filter listener;
  `uninstall()` removes it or refuses if a board still uses it.

- **Pin (notice)** — reuses the core `board_posts.is_notice` flag unchanged; the
  widget shows a "📌 Pinned" badge from `/meta`.

- **Thread lock** — site admins lock / unlock a forum post
  (`POST .../posts/{id}/lock` · `/unlock`, admin-gated). A locked thread rejects new
  comments and replies on the server via two `sirsoft-board.comment.*` filter hooks
  (`store_validation_rules` → 422 with a message, and `filter_create_data` →
  `PostNotCommentableException` as a final gate). The visitor page replaces the
  comment form with a "🔒 locked" notice and shows an admin lock toggle in the
  widget. No admin exception in this release — a locked thread is locked for
  everyone.

- **Reactions** — five emoji reactions (👍 ❤️ 😂 😮 😢) on **posts and comments**
  (`POST .../{targetType}/{id}/reactions`, `targetType` ∈ `posts` \| `comments`,
  sanctum-auth). One reaction per user per target, enforced by a
  `(target_type, target_id, user_id)` unique constraint: the same emoji toggles off,
  a different one replaces. `/meta` returns per-post and per-comment summaries
  (`{ counts, total, mine }`). A five-button bar is spliced into the widget for the
  post and after each rendered comment body. Table: `g7_forum_addon_reactions`.

- **Best answer** — the post author or a site admin marks one comment as accepted
  (`POST .../posts/{postId}/comments/{commentId}/accept` · `/unaccept`,
  sanctum-auth). Replies (depth > 0) are eligible. One per post; `accept` always
  swaps. Auto-cleared when the accepted comment is deleted — immediately via a
  `sirsoft-board.comment.after_delete` listener, and defensively at `/meta` read
  time (`resolveForMeta()` covers cascade deletes and blinding). Stored in
  `g7_forum_addon_post_meta.accepted_reply_id`; no new migration. A badge + Accept /
  Unaccept buttons are spliced after each comment body, and an
  "accepted answer present" summary is shown in the widget.

- **Front-end** — `BoardShowWidgetListener` transforms the composed `board/show`
  layout tree at `core.layout_extension.after_apply`: splices the widget, injects
  the `forum_meta` 2-call data source, and adds the lock / reaction / best-answer UI
  described above. Every injected node is gated by a `board.type === 'forum'` runtime
  `if`, so nothing renders on non-forum boards. `sirsoft-basic` and
  `g7-comment-editor` are not modified.

### Dependencies

- Module `sirsoft-board >= 1.1.1`.
- Plugin `g7-comment-editor >= 1.0.0` — must be installed and activated **before**
  this plugin (the CLI does not cascade-install dependencies).

### Known issues

- **Removal order** is not enforced by the core: the reverse-dependents guard
  (`PluginRepository::findActiveByPluginDependency`) does not recognise
  plugin → plugin dependencies, so `g7-comment-editor` can be deactivated while
  `g7-forum-addon` is still active without a warning. The forward install / activate
  gate works. Remove in order: `g7-forum-addon` first, then `g7-comment-editor`.
