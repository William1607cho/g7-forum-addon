# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
