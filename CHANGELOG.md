# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Pin a post from the widget (`pin` / `unpin`).** A board manager can now pin a
  forum post straight from the post widget, without opening the edit form.

  A pin **is** the core notice flag. The add-on stores nothing of its own: the
  new `POST posts/{id}/pin` and `POST posts/{id}/unpin` endpoints judge the
  permission and then call `sirsoft-board`'s `PostService::updatePost()` with a
  **single key, `is_notice`**. Everything the core already does for a notice —
  top of page 1 of the list, SEO cache invalidation and regeneration, the
  activity log entry — follows for free, and there is no second source of truth
  to drift from the list ordering.

  `is_secret`, `content`, `title` and `status` are protected by **not putting
  their keys in the array**, not by writing their old values back: the core
  repository passes the array straight to `$post->update()`, so a column that is
  not in the array is not in the UPDATE statement. (Because `content` is absent,
  the model's `saving` hook also skips recomputing the body thumbnail.)

  Both endpoints are idempotent and answer with the resulting state
  (`data.is_notice`). Replies (`parent_id` set) are refused with 422 — the core
  notice query requires `parent_id IS NULL`, so pinning a reply would store a
  value that never shows up anywhere.

  `updated_at` does change. Forum list ordering is unaffected: `ActivityTime`
  only ever looks at `created_at`.

### Changed

- **Emoji reactions are now up/down votes.** The five emoji reactions
  (like / love / haha / wow / sad) are replaced by exactly two, `up` and `down`.
  Sending any other value — including an old emoji name — is refused with 422.

  One vote per person per target is unchanged and still enforced by the same
  unique constraint: clicking the other side switches the vote, clicking the same
  side again cancels it. The widget shows the up and down counts as two separate
  numbers, always visible (including `0`); there is no net score.

  **No schema change and no migration.** `reaction` is a free-form string column
  and the allowed values live only in `ReactionStore::REACTIONS`. Both sites held
  zero reaction rows at the time of the change, so there was nothing to convert.
  A site that upgrades with existing emoji rows would keep those rows, but they
  would no longer be counted or displayed.

- **You can no longer vote on your own post or comment.** The server refuses it
  with 403, and the widget disables — rather than hides — the two buttons for the
  author, so the author can still read the counts. Judgement uses values the core
  already ships (`post.data.is_owner`, `comment.is_author`).

- **Locking a thread now takes board-manager permission, not site-admin.** The
  lock and unlock endpoints used to sit behind the `admin` middleware, so only a
  site administrator could use them, and the button was shown on
  `currentUser.is_admin`. Both sides now use
  `sirsoft-board.{slug}.manager` — the same identifier the core already puts in
  `post.data.abilities.can_manage` — so pin and lock behave alike inside one
  widget. Site administrators keep the ability: the admin role is granted every
  leaf permission, `{slug}.manager` included.

### Fixed

- **The widget is no longer empty on a secret post for people who can read it.**
  Until now the add-on applied its own rule — "author only" — to secret posts, so
  a board manager or a holder of `posts.read-secret` could read the post body
  perfectly well while every add-on endpoint answered 403 and the widget rendered
  as nothing.

  Both places that judged this (`Support\PostVisibilityGuard` for a single post,
  `Support\ForumListMetaProvider` for the board-list batch) now delegate to the
  core's single source of truth, `SecretContentGate::canView()`, keeping the core
  order: author → verified password → view token → `posts.read-secret` →
  `manager`. The two paths can no longer disagree with each other or with the
  core.

  This is also why a secret thread can now be locked and pinned: a manager passes
  the visibility guard, so no separate branch was needed.

  Note for anyone extending this plugin: these two classes now touch the core
  `Post` Eloquent model, which the rest of the plugin deliberately avoids. The
  gate requires a `Post` and resolves the board slug from either the route or a
  **loaded `board` relation**, failing closed when it can find neither — and the
  add-on's routes carry no `{slug}`. So the relation is always eager-loaded
  before the gate is called.

### Removed

- **The widget's placeholder line is gone.** "Forum widgets will appear here
  (reactions · tags · subscribe · lock)" was a fixed string with no condition on
  it, left over from the scaffold. Everything in the widget now renders real
  data.

## [1.2.0] - 2026-09-20

### Added

- **Forum boards are always sorted by last activity.** The post list of a
  `forum` board is now ordered by last activity, newest first, **server-side and
  before pagination**, so a thread that just received a comment comes back to the
  top of page 1 instead of staying wherever its creation date put it.

  "Activity" is the post's own creation time, or the creation time of its most
  recent **non-deleted** comment, whichever is later. Editing a post or comment
  is not activity, and neither is a reaction or accepting an answer; deleting a
  comment drops out of the calculation immediately. Ties are broken by post id,
  descending. Every post has a value without any new column, table or backfill —
  a post with no comments falls back to its own creation time.

  The definition now lives in exactly one place, `Support\ActivityTime`, used
  both by the sort and by the "Last activity" column the board list already
  displayed, so the two cannot drift apart.

### Changed

- **`sort_by` / `sort_order` and the board's default ordering are ignored on
  `forum` boards.** This is a visible behaviour change for anyone running this
  plugin: on a forum board, recent activity is the premise of the screen rather
  than one option among several, and the list already labels its date column
  "Last activity". Other board types are completely unaffected and keep using
  whatever `sirsoft-board` does today.

  Two consequences worth knowing about:

  - **The admin post list for a forum board is sorted by activity too**, because
    it goes through the same `sirsoft-board` repository method. Sorting that
    screen by title or author has no effect on forum boards.
  - **Any other caller of the core list API sees the same order** — server-side
    rendering for crawlers, and any integration reading
    `GET /api/modules/sirsoft-board/boards/{slug}/posts`.

  **Pinned (notice) posts are not affected.** `sirsoft-board` fetches them in a
  separate query and places them at the top of page 1; this plugin does not touch
  that path.

- `PostRepositoryInterface` is now rebound to
  `Repositories\ActivitySortedPostRepository`, which extends `sirsoft-board`'s
  `PostRepository` and overrides one method. Non-forum boards are handed straight
  back to the parent implementation, and no core source is copied: the sort spec
  is swapped at the point where the parent hands the already-built query to the
  core deferred-join paginator. `sirsoft-board` and the template remain
  unmodified, as before.

### Known limitation

- The sort key is a correlated subquery over `board_comments`, evaluated for
  every root post of the board before the page is cut. It is backed by
  `idx_board_comments_post_deleted_created` and is cheap for boards of a few
  thousand posts; beyond that a materialised column would be needed, which is not
  possible without modifying `sirsoft-board`.

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
