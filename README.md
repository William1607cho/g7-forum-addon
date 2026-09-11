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
| **Accepted-answer content box** *(new in 1.1.0)* | The widget shows the accepted answer's full formatted content (bold, lists, block quotes, tables) with the author's avatar, name and timestamp — not just a "there is an accepted answer" line. Renders through the same escape-then-client-sanitize pipeline as ordinary comments, so it inherits `g7-comment-editor`'s existing XSS defense rather than opening a new one. |
| **Board-list participants + last activity** *(new in 1.1.0)* | The board index page gets a "Participants" column (avatar stack, up to 5 recent commenters) and, on forum boards, relabels "Created" to "Last activity" with the most recent comment/post time. One batched, N+1-free query per page. Display only — see Known issues for sort order. |
| **Replies default-expanded on forum boards** *(new in 1.1.0)* | A comment's reply thread starts expanded on forum boards (still collapsed by default everywhere else); the "N replies" toggle keeps working normally in both directions. |
| **Reply targeting / soft-delete** | Provided by `sirsoft-board` itself; the forum board type inherits them, no code. |

Not yet implemented: edit history, tags, mentions + notifications, subscriptions,
sorting the board list by last activity (see Known issues).

## API

All endpoints re-check visibility with the same rules `sirsoft-board` applies
(secret / blinded / deleted / board active) and gate on the board being a
`forum` type.

| Method / path | Auth | Notes |
|---|---|---|
| `GET  /api/plugins/g7-forum-addon/posts/{id}/meta` | optional | `{ reactions, comment_reactions, tags, subscribed, accepted_reply_id, accepted_reply, is_notice, locked }`. `accepted_reply_id` is self-healed to `null` if the target comment is gone; `accepted_reply` (`{ id, content, author, created_at, created_at_formatted }`, added in 1.1.0) follows it 1:1 and is `null` under the same conditions. |
| `POST /api/plugins/g7-forum-addon/posts/{id}/lock` · `/unlock` | admin | Site admin only. |
| `POST /api/plugins/g7-forum-addon/{targetType}/{id}/reactions` | sanctum | `targetType` ∈ `posts` \| `comments`, body `{ reaction }`. One toggle endpoint for add / swap / remove. |
| `POST /api/plugins/g7-forum-addon/posts/{postId}/comments/{commentId}/accept` · `/unaccept` | sanctum | Post author or site admin. |
| `GET  /api/plugins/g7-forum-addon/boards/{slug}/list-meta?post_ids=...` *(new in 1.1.0)* | optional | Batched participants + last-activity for a page of board-list rows. 404 on non-forum boards; re-checks per-post visibility. |

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
  or admin only) spliced after each comment body, plus a full accepted-answer
  content box (avatar, name, timestamp, formatted body — see Features) in the
  widget.
- **Comment-delete refetch fix** *(1.1.0)*: the shared post/comment delete
  confirmation dialog (a `sirsoft-basic` core template) only refetched the `post`
  data source on comment delete, not this plugin's `forum_meta` sidecar — so the
  accepted-answer box lingered on screen until the next page load after its
  comment was deleted. Fixed by locating that dialog's existing refetch step by
  structural signature and splicing a sibling step for `forum_meta`, still
  through `after_apply` — no core template file touched.
- **Replies default-expanded** *(1.1.0)*: on forum boards, the reply-thread
  expand/collapse expressions `sirsoft-basic` already renders are patched so their
  "no value yet" default is expanded instead of collapsed; every other board type
  keeps the original default untouched.

`BoardIndexWidgetListener` does the same for `board/index` (the board list) —
splices a "Participants" column and relabels "Created" to "Last activity" on
forum boards only, backed by the batched `list-meta` endpoint above.

All widget nodes carry a `board.type === 'forum'` runtime `if`, so nothing renders
on non-forum boards. `sirsoft-basic` and `g7-comment-editor` are not touched — the
comment-side UI is spliced into the layout by this plugin.

## Requirements

- Gnuboard7 `>= 7.0.10`
- Module **`sirsoft-board >= 1.1.1`**
- Plugin **[`g7-comment-editor >= 1.1.0`](https://github.com/William1607cho/g7-comment-editor)**
  — the forum board's comment box uses it for CKEditor. **This must be installed and
  activated first** (see Installation). Raised from `>= 1.0.0` in this plugin's
  `1.1.0` release: the accepted-answer content box can display tables, and
  `g7-comment-editor`'s sanitizer only started preserving table markup at its own
  `1.1.0` — on an older `g7-comment-editor`, a table inside an accepted answer
  would render as nothing.

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

## Known issues

- **Accepted-answer box colors are fixed.** The green palette in
  `BoardShowWidgetListener::acceptedReplyBox()` is hard-coded; there is no
  settings UI to change it yet.
- **Board-list "last activity" does not change sort order.** The column shows
  the right timestamp, but the list is still paginated/sorted by whatever
  `sirsoft-board` already does — it exposes no hook this plugin can use to
  change list ordering, so a forum board is not actually sorted by recent
  activity.
- **Plugin → plugin removal order isn't enforced by the core** — see the
  callout above.

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
- **채택된 답변 전문 표시** *(1.1.0 신규)*: 위젯에 채택된 답변의 서식 포함 전문(굵게·목록·
  인용구·표)을 작성자 아바타·이름·시각과 함께 표시. 기존 댓글과 동일한 이스케이프 후
  클라이언트 재정화 방식이라 별도의 XSS 방어 경로를 새로 열지 않습니다. 색상은 현재
  고정값(설정 UI 없음).
- **게시판 목록 참여자 · 최근 활동** *(1.1.0 신규)*: 게시판 목록에 "참여자" 컬럼(최근
  댓글 작성자 아바타, 최대 5명), 포럼 게시판은 "작성일"이 "최근 활동"으로 표시(정렬
  기준은 변경되지 않음 — 표시만).
- **답글 기본 펼침** *(1.1.0 신규)*: 포럼 게시판에서만 답글 스레드가 기본 펼침 상태로
  시작(다른 게시판 유형은 기존처럼 기본 접힘 유지).
- **고정 · 답글 대상 지목 · 소프트삭제**: `sirsoft-board` 기본 기능을 그대로 사용.

**설치 순서 주의**: 의존 플러그인 `g7-comment-editor (>= 1.1.0)` 를 **먼저** 설치·활성화한
뒤 이 플러그인을 설치하세요(CLI 는 자동 동반 설치가 없음). `1.0.0` → `1.1.0`으로 올린
이유: 채택된 답변 박스가 표(table)를 표시할 수 있는데, `g7-comment-editor`의 새니타이저가
표 마크업을 보존하기 시작한 게 자신의 `1.1.0`부터라, 그보다 낮은 버전에서는 표가 담긴
채택 답변이 빈 내용으로 보입니다. 제거는 반드시 역순(`g7-forum-addon` → `g7-comment-editor`)
— 코어의 역방향 dependents 가드가 플러그인→플러그인 의존을 인식하지 못하는 버그가 있어,
순서를 운영자가 직접 지켜야 합니다.

## Acknowledgments

- Modelled on the "leave the target untouched, post-process from an add-on" approach
  used by `g7-ckeditor5-superpack`. No code is copied.

## License

MIT © 2026 William Cho. See [LICENSE](LICENSE).
