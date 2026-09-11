# g7-forum-addon

[![Release](https://img.shields.io/github/v/release/William1607cho/g7-forum-addon?sort=semver)](https://github.com/William1607cho/g7-forum-addon/releases)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](./LICENSE)

A [Gnuboard7](https://sir.kr/) plugin that adds a **forum-type board** to
`sirsoft-board` and layers forum features on top of it — pinned posts, best-answer
acceptance, thread locking, emoji reactions — plus the plumbing (reply targeting,
soft-delete) that `sirsoft-board` already provides.

`sirsoft-board` and the visitor template (`sirsoft-basic`) are **never modified**.
The add-on works only through its own tables, its own API
(`/api/plugins/g7-forum-addon/*`), and the `core.layout_extension.after_apply`
filter hook, which splices widgets into the final `board/show` layout tree.

- **[사용법 (한국어) 아래로 이동](#사용법-한국어)**

## Features

| Feature | What it does |
|---|---|
| **Forum board type** | `install()` adds a `forum` row to `board_types` (kept alive across re-seeds by a `seed.sirsoft-board.board_types.translations` filter listener). `uninstall()` removes it, or refuses if a board still uses it. |
| **Pin (notice)** | Reuses the core `board_posts.is_notice` flag unchanged; the widget shows a "📌 Pinned" badge from `/meta`. |
| **Thread lock** | A site admin can lock / unlock a forum post. A locked thread rejects new comments and replies **on the server** (two `sirsoft-board.comment.*` filter hooks), and the visitor page replaces the comment form with a "🔒 locked" notice. |
| **Reactions** | Five emoji reactions (👍 ❤️ 😂 😮 😢) on **posts and comments**. One reaction per user per target — clicking the same one removes it, a different one replaces it (enforced by a DB unique constraint). Logged-in users only. |
| **Best answer** | The post author (or a site admin) marks one comment — top-level or a reply — as the accepted answer. One per post; re-accepting swaps it. Auto-cleared if the accepted comment is deleted. |
| **Reply targeting / soft-delete** | Provided by `sirsoft-board` itself; the forum board type inherits them, no code. |

Not yet implemented: edit history, tags, mentions + notifications, subscriptions.

## API

All endpoints re-check visibility with the same rules `sirsoft-board` applies
(secret / blinded / deleted / board active) and gate on the board being a
`forum` type.

| Method / path | Auth | Notes |
|---|---|---|
| `GET  /api/plugins/g7-forum-addon/posts/{id}/meta` | optional | `{ reactions, comment_reactions, tags, subscribed, accepted_reply_id, is_notice, locked }`. `accepted_reply_id` is self-healed to `null` if the target comment is gone. |
| `POST /api/plugins/g7-forum-addon/posts/{id}/lock` · `/unlock` | admin | Site admin only. |
| `POST /api/plugins/g7-forum-addon/{targetType}/{id}/reactions` | sanctum | `targetType` ∈ `posts` \| `comments`, body `{ reaction }`. One toggle endpoint for add / swap / remove. |
| `POST /api/plugins/g7-forum-addon/posts/{postId}/comments/{commentId}/accept` · `/unaccept` | sanctum | Post author or site admin. |

## How the front-end works

`BoardShowWidgetListener` subscribes to `core.layout_extension.after_apply` and,
on `board/show` only, transforms the fully-composed layout tree:

- Splices a forum widget before the post action-button row, and injects a
  `forum_meta` data source that calls `/meta` (a 2-call layout).
- **Lock:** ANDs a "not locked" condition onto the comment form's `if`, and splices
  a "🔒 locked" notice in its place; adds a `🔒 Locked` badge and an admin
  lock / unlock toggle to the widget.
- **Reactions:** a five-button reaction bar in the widget (for the post) and one
  spliced right after each rendered comment body `<P>`.
- **Best answer:** a "✅ Accepted answer" badge + Accept / Unaccept buttons (author
  or admin only) spliced after each comment body, plus an "accepted answer present"
  summary in the widget.

All widget nodes carry a `board.type === 'forum'` runtime `if`, so nothing renders
on non-forum boards. `sirsoft-basic` and `g7-comment-editor` are not touched — the
comment-side UI is spliced into the layout by this plugin.

## Requirements

- Gnuboard7 `>= 7.0.10`
- Module **`sirsoft-board >= 1.1.1`**
- Plugin **[`g7-comment-editor >= 1.0.0`](https://github.com/William1607cho/g7-comment-editor)**
  — the forum board's comment box uses it for CKEditor. **This must be installed and
  activated first** (see Installation).

Version constraints in `plugin.json` are not enforced by the core install / activate
hard gate (it only checks that the dependency is *active*); keep them accurate for
humans reading the manifest.

## Installation

### From GitHub (CLI)

The CLI `plugin:install` does **not** cascade-install dependencies. Install
`g7-comment-editor` first.

```bash
# 1) dependency first
#    (drop https://github.com/William1607cho/g7-comment-editor into plugins/g7-comment-editor)
php artisan plugin:install g7-comment-editor
php artisan plugin:activate g7-comment-editor

# 2) this plugin
#    (drop this repo into plugins/g7-forum-addon)
php artisan plugin:install g7-forum-addon
php artisan plugin:activate g7-forum-addon

# 3) rebuild caches
php artisan hooks:cache
php artisan template:cache-clear
php artisan cache:clear
```

If you skip step 1, `plugin:install g7-forum-addon` fails hard (it cannot be forced
past):

```
❌ Dependency "g7-comment-editor" is not installed or is inactive.
```

### From the admin UI

Admin → Plugins → g7-forum-addon → Install. The install modal has
`g7-comment-editor` pre-checked, so it is installed and activated alongside.

## Uninstalling

```bash
php artisan plugin:uninstall g7-forum-addon               # keep data (only removes the board_types.forum row)
php artisan plugin:uninstall g7-forum-addon --delete-data # also DROPs the add-on tables
```

Uninstall is refused while a board still uses the `forum` type — change or delete
that board first.

> **Removal order — important (core bug).** While this plugin is active you can
> still `plugin:deactivate` / `plugin:uninstall` its dependency `g7-comment-editor`
> without a warning: the core's reverse-dependents guard
> (`PluginRepository::findActiveByPluginDependency`) does not recognise
> plugin → plugin dependencies. The **forward** gate works fine (you can't install
> or activate in the wrong order), but you must remove in the right order yourself:
> **`g7-forum-addon` first, then `g7-comment-editor`.**

## Tables

| Table | Purpose |
|---|---|
| `g7_forum_addon_post_meta` | one lazily-created row per forum post — `is_locked`, `accepted_reply_id` |
| `g7_forum_addon_reactions` | post / comment reactions, unique on `(target_type, target_id, user_id)` |

(With `DB_PREFIX` set, the physical table names carry the prefix twice, as with
other Gnuboard7 plugins.)

## <a name="사용법-한국어"></a>사용법 (한국어)

`sirsoft-board` 에 **포럼형(`forum`) 게시판 유형**을 추가하고, 그 위에 고정 · 잠금 ·
리액션 · 베스트답글을 얹는 애드온입니다. **`sirsoft-board` 와 템플릿(`sirsoft-basic`) 은
전혀 수정하지 않으며**, 애드온 전용 테이블 · 자체 API · `core.layout_extension.after_apply`
필터 훅으로만 동작합니다.

- **잠금**: 사이트 관리자가 포럼 게시글을 잠그면 새 댓글·답글이 **서버에서** 거부되고,
  방문자 화면에서는 댓글 폼이 "🔒 잠긴 게시글" 안내로 바뀝니다.
- **리액션**: 게시글·댓글에 5종 이모지(👍 ❤️ 😂 😮 😢). 사용자당 대상 1개(재클릭=취소,
  다른 종류=교체, DB 유니크 제약). 로그인 사용자만.
- **베스트답글**: 글 작성자(또는 사이트 관리자)가 댓글 하나를 "채택된 답변"으로 지정.
  답글(대댓글)도 가능, 게시글당 1개, 채택 댓글 삭제 시 자동 해제.
- **고정 · 답글 대상 지목 · 소프트삭제**: `sirsoft-board` 기본 기능을 그대로 사용.

**설치 순서 주의**: 의존 플러그인 `g7-comment-editor (>= 1.0.0)` 를 **먼저** 설치·활성화한
뒤 이 플러그인을 설치하세요(CLI 는 자동 동반 설치가 없음). 제거는 반드시 역순
(`g7-forum-addon` → `g7-comment-editor`) — 코어의 역방향 dependents 가드가
플러그인→플러그인 의존을 인식하지 못하는 버그가 있어, 순서를 운영자가 직접 지켜야 합니다.

## Acknowledgments

- Modelled on the "leave the target untouched, post-process from an add-on" approach
  used by `g7-ckeditor5-superpack`. No code is copied.

## License

MIT © 2026 William Cho. See [LICENSE](LICENSE).
