# g7-forum-addon

[![Release](https://img.shields.io/github/v/release/William1607cho/g7-forum-addon?sort=semver)](https://github.com/William1607cho/g7-forum-addon/releases)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](./LICENSE)

A [Gnuboard7](https://sir.kr/) plugin that adds a **forum-type board** to
`sirsoft-board` and layers forum features on top of it — pinned posts, best-answer
acceptance, thread locking, up/down votes — plus the plumbing (reply targeting,
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
| **Pin (notice)** *(interactive in 1.3.0)* | A pin **is** the core `board_posts.is_notice` flag — the add-on stores nothing of its own. A board manager pins / unpins from the widget without opening the edit form; the core's "notice on top of page 1" behaviour, SEO cache handling and activity log all follow for free. The widget shows a "📌 Pinned" badge from `/meta`. Replies cannot be pinned (422). |
| **Thread lock** | A **board manager** can lock / unlock a forum post (site-admin-only until 1.3.0). A locked thread rejects new comments and replies **on the server** (two `sirsoft-board.comment.*` filter hooks), and the visitor page replaces the comment form with a "🔒 locked" notice. |
| **Up / down votes** *(replaces emoji reactions in 1.3.0)* | Two votes — `up` and `down` — on **posts and comments**, shown as two separate counts. One vote per user per target: clicking the other side switches, clicking the same side cancels (enforced by a DB unique constraint). Logged-in users only, and **not on your own post or comment**. |
| **Best answer** | The post author (or a site admin — unchanged in 1.3.0) marks one comment — top-level or a reply — as the accepted answer. One per post; re-accepting swaps it. Auto-cleared if the accepted comment is deleted. |
| **Accepted-answer content box** *(new in 1.1.0)* | The widget shows the accepted answer's full formatted content (bold, lists, block quotes, tables) with the author's avatar, name and timestamp — not just a "there is an accepted answer" line. Renders through the same escape-then-client-sanitize pipeline as ordinary comments, so it inherits `g7-comment-editor`'s existing XSS defense rather than opening a new one. |
| **Board-list participants + last activity** *(new in 1.1.0)* | The board index page gets a "Participants" column (avatar stack, up to 5 recent commenters) and, on forum boards, relabels "Created" to "Last activity" with the most recent comment/post time. One batched, N+1-free query per page. |
| **Forum boards are always sorted by last activity** *(new in 1.2.0)* | On `forum` boards the post list is ordered by **last activity, newest first** — server-side, before pagination — so a thread that just got a comment returns to the top of page 1. **This overrides `sort_by` / `sort_order` and the board's own default ordering**; on a forum board, recent activity is the premise of the screen rather than one sort option among several. Other board types are untouched. See [Behaviour to be aware of](#behaviour-to-be-aware-of). |
| **Replies default-expanded on forum boards** *(new in 1.1.0)* | A comment's reply thread starts expanded on forum boards (still collapsed by default everywhere else); the "N replies" toggle keeps working normally in both directions. |
| **Reply targeting / soft-delete** | Provided by `sirsoft-board` itself; the forum board type inherits them, no code. |

Not yet implemented: edit history, tags, mentions + notifications, subscriptions.

## API

All endpoints re-check visibility with the same rules `sirsoft-board` applies
(board read permission / secret / blinded / deleted / board active) and gate on
the board being a `forum` type. Without the board's `posts.read` permission a
guest gets `401` and a signed-in user `403`, exactly like `sirsoft-board`'s own
post API (since 1.1.1).

| Method / path | Auth | Notes |
|---|---|---|
| `GET  /api/plugins/g7-forum-addon/posts/{id}/meta` | optional | `{ reactions, comment_reactions, tags, subscribed, accepted_reply_id, accepted_reply, is_notice, locked }`. `accepted_reply_id` is self-healed to `null` if the target comment is gone; `accepted_reply` (`{ id, content, author, created_at, created_at_formatted }`, added in 1.1.0) follows it 1:1 and is `null` under the same conditions. |
| `POST /api/plugins/g7-forum-addon/posts/{id}/lock` · `/unlock` | sanctum | Board manager (`sirsoft-board.{slug}.manager`). **Changed in 1.3.0** — was site-admin-only. |
| `POST /api/plugins/g7-forum-addon/posts/{id}/pin` · `/unpin` *(new in 1.3.0)* | sanctum | Board manager. Idempotent; answers `{ is_notice }` with the resulting state. Calls the core `PostService::updatePost()` with the single key `is_notice`. `422` on a reply. |
| `POST /api/plugins/g7-forum-addon/{targetType}/{id}/reactions` | sanctum | `targetType` ∈ `posts` \| `comments`, body `{ reaction }` where `reaction` ∈ `up` \| `down` (**changed in 1.3.0**; any other value → `422`). One toggle endpoint for add / switch / remove. `403` on your own post or comment. |
| `POST /api/plugins/g7-forum-addon/posts/{postId}/comments/{commentId}/accept` · `/unaccept` | sanctum | Post author or site admin. |
| `GET  /api/plugins/g7-forum-addon/boards/{slug}/list-meta?post_ids=...` *(new in 1.1.0)* | optional | Batched participants + last-activity for a page of board-list rows. 404 on non-forum boards; `401` / `403` without board read permission (1.1.1); re-checks per-post visibility. |

## Widget layout (1.3.0)

```
┌ post body ────────────────────────────────────────────────┐
│ …                                                         │
│                                                           │
│  [▲ 3] [▼ 0]   Pinned  Locked  Has an accepted answer   [📢] [🔒] │
│                                                           │
│  ┌ accepted answer ─────────────────────────────────────┐ │
│  │ avatar · name · time                                 │ │
│  │ body…                                                │ │
│  └──────────────────────────────────────────────────────┘ │
├───────────────────────────────────────────────────────────┤
│                        Reply  Report  Edit  Delete        │
└───────────────────────────────────────────────────────────┘
```

- **No box.** There is no border or background around the widget, only spacing.
- **One row.** Votes on the left, status badges next to them, pin and lock at the
  right edge. The row never wraps — only the badge group may wrap or shrink — so
  the four buttons stay on one line at phone width.
- **Equal squares.** Every button is `w-10 h-10` (40px). Votes stack an icon over
  their count; pin and lock are icon-only and name themselves through `title` and
  `aria-label`. An active toggle is filled and sets `aria-pressed`.

### Icons

The visitor template (`wc-community`) ships a **Solid-only Font Awesome subset**,
and a name that is not in it renders as a blank glyph with no error. Only names
present in the deployed subset are used:

| Use | Icon | Note |
|---|---|---|
| Upvote / downvote | `chevron-up` / `chevron-down` | the subset has no `caret-*` |
| Pin | `bullhorn` | no `thumbtack`; a pin here *is* the core notice flag |
| Lock (both states) | `lock` | no `lock-open` / `unlock` — state is shown by fill, not shape |
| Accepted mark | `circle-check` | |

If you run this plugin on a template with the full Font Awesome, swapping these
names is a one-line change per icon in `BoardShowWidgetListener`.

## How the front-end works

`BoardShowWidgetListener` subscribes to `core.layout_extension.after_apply` and,
on `board/show` only, transforms the fully-composed layout tree:

- Splices a forum widget before the post action-button row, and injects a
  `forum_meta` data source that calls `/meta` (a 2-call layout).
- **Lock:** ANDs a "not locked" condition onto the comment form's `if`, and splices
  a "🔒 locked" notice in its place; adds a `🔒 Locked` badge and a
  lock / unlock toggle to the widget.
- **Pin** *(1.3.0)*: a pin / unpin toggle next to the lock toggle.
- **Manager-only buttons** *(1.3.0)*: the pin and lock toggles are shown on
  `post.data.abilities.can_manage`, which the core fills from
  `sirsoft-board.{slug}.manager` — the same identifier the server judges on, so
  the button and the API cannot disagree. (Until 1.3.0 the lock button used
  `currentUser.is_admin`.)
- **Votes:** a two-button up/down bar in the widget (for the post) and one
  spliced right after each rendered comment body `<P>`. Both counts are always
  shown, including `0`. For the author the buttons are **disabled rather than
  hidden**, so the author can still read the counts.
- **Accepted-answer highlight** *(1.3.0)*: the accepted comment's row container
  gets a conditional `className` so the whole comment is outlined and tinted
  green. This rewrites a class string in the composed tree — the template file is
  not touched, and the container's own `style` (the depth indent) is preserved.
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

## <a name="behaviour-to-be-aware-of"></a>Behaviour to be aware of

**From 1.3.0, secret posts are judged by the core gate, not by this plugin.**
Until 1.2.0 the add-on applied its own "author only" rule to secret posts, so a
board manager or a holder of `posts.read-secret` read the post body normally
while every add-on endpoint answered `403` — the widget simply rendered as
nothing for them. Both judging sites (`PostVisibilityGuard`, and the board-list
batch in `ForumListMetaProvider`) now call `SecretContentGate::canView()`, the
core's single source of truth, so add-on visibility always matches the body.

Two consequences:

- A secret thread can now be **pinned and locked** by a manager, because the
  manager passes the visibility guard. There is no separate branch for it.
- These two classes now use the core `Post` Eloquent model, which the rest of
  this plugin deliberately avoids. The gate needs a `Post` and resolves the board
  slug from the route or a **loaded `board` relation**, failing closed with
  neither — and the add-on routes carry no `{slug}`, so the relation is always
  eager-loaded before the gate is called. Keep that in mind when extending these
  paths.

**From 1.2.0, `forum` boards ignore the requested sort order.** Every listing of a
forum board comes back ordered by last activity (newest first), with ties broken
by post id descending. This is deliberate — the board-list column already reads
"Last activity", and a list that shows one thing while ordering by another is
worse than no column at all.

What that means in practice:

- `?sort_by=` / `?sort_order=` on a forum board are ignored, as is the board's
  **Default sort** admin setting. Both keep working normally on every other
  board type.
- **Pinned (notice) posts are unaffected.** `sirsoft-board` fetches them in a
  separate query and puts them at the top of page 1; this plugin does not touch
  that path.
- **The admin post list for a forum board is sorted the same way**, because it
  goes through the same repository method. Sorting by title or author in the
  admin screen therefore has no effect on forum boards.
- **Anything else calling the core list API for a forum board sees this order
  too** — server-side rendering for crawlers, and any integration that reads
  `GET /api/modules/sirsoft-board/boards/{slug}/posts`.

"Activity" means the post's own creation time, or the creation time of its most
recent **non-deleted** comment, whichever is later. Editing a post or a comment
is not activity, and neither is a reaction or accepting an answer. Deleting a
comment removes it from the calculation immediately. Every post therefore has a
value without any new column or backfill — the definition lives in one place,
`src/Support/ActivityTime.php`, shared by the sort and the displayed column.

## Known issues

- **Accepted-answer box colors are fixed.** The green palette in
  `BoardShowWidgetListener::acceptedReplyBox()` is hard-coded; there is no
  settings UI to change it yet.
- **Last-activity sorting does not scale to very large forum boards.** The sort
  key is a correlated subquery over `board_comments`, evaluated for every root
  post of the board before the page is cut. It is backed by
  `idx_board_comments_post_deleted_created` and is inexpensive for boards of a
  few thousand posts, but a materialised column would be needed beyond that —
  and that cannot be done without modifying `sirsoft-board`.
- **Plugin → plugin removal order isn't enforced by the core** — see the
  callout above.

## Tables

| Table | Purpose |
|---|---|
| `g7_forum_addon_post_meta` | one lazily-created row per forum post — `is_locked`, `accepted_reply_id` |
| `g7_forum_addon_reactions` | post / comment votes (`up` / `down` since 1.3.0), unique on `(target_type, target_id, user_id)` |

(With `DB_PREFIX` set, the physical table names carry the prefix twice, as with
other Gnuboard7 plugins.)

## <a name="사용법-한국어"></a>사용법 (한국어)

`sirsoft-board` 에 **포럼형(`forum`) 게시판 유형**을 추가하고, 그 위에 고정(핀) · 잠금 ·
추천 · 베스트답글을 얹는 애드온입니다. **`sirsoft-board` 와 템플릿(`sirsoft-basic`) 은
전혀 수정하지 않으며**, 애드온 전용 테이블 · 자체 API · `core.layout_extension.after_apply`
필터 훅으로만 동작합니다.

- **고정(핀)** *(1.3.0 부터 화면에서 조작)*: 게시판 관리자가 글 수정 화면을 거치지 않고
  위젯의 버튼으로 글을 고정·해제합니다. **고정은 곧 코어의 공지 값**입니다 — 애드온은
  따로 저장하지 않고 `board_posts.is_notice` 를 바꾸기만 하므로, "공지는 1페이지 맨 위"
  라는 코어 동작과 SEO 캐시 처리·활동 로그가 그대로 따라옵니다. 답글은 고정할 수
  없습니다(422). 고정·해제는 `updated_at` 을 바꾸지만 목록의 최근활동순 정렬에는 영향이
  없습니다(정렬은 `created_at` 만 봅니다).
- **잠금**: **게시판 관리자**가 포럼 게시글을 잠그면 새 댓글·답글이 **서버에서** 거부되고,
  방문자 화면에서는 댓글 폼이 "🔒 잠긴 게시글" 안내로 바뀝니다. *1.3.0 변경* — 그 전에는
  사이트 관리자만 가능했습니다. 핀과 잠금이 한 위젯 안에서 기준이 갈리지 않도록 맞췄고,
  화면 버튼도 서버와 같은 식별자(`sirsoft-board.{slug}.manager`)로 노출됩니다. 관리자
  역할은 리프 권한을 모두 갖고 있으므로 기존 사이트 관리자는 그대로 잠글 수 있습니다.
- **추천(업·다운)** *(1.3.0 — 5종 이모지를 대체)*: 게시글·댓글에 `up` / `down` 두 가지.
  사용자당 대상 1표(같은 쪽 재클릭=취소, 반대쪽=전환, DB 유니크 제약). 업·다운 개수를
  각각 숫자로 표시하며 0 도 숨기지 않습니다(순점수는 만들지 않습니다). 로그인 사용자만이고,
  **자기 글·자기 댓글에는 투표할 수 없습니다**(서버 403, 화면은 버튼 비활성 — 감추면
  작성자만 자기 글의 점수를 못 보게 되므로). 스키마 변경도 마이그레이션도 없습니다.
- **위젯 모양** *(1.3.0)*: 위젯을 감싸던 파란 점선 상자를 없애고(여백은 유지), 조작을 한 줄에
  모았습니다 — 왼쪽에 추천 업·다운, 그 옆에 상태 배지, 오른쪽 끝에 고정·잠금. 버튼 4개는
  모두 같은 40px 정사각(`w-10 h-10`)이고, 좁은 화면에서도 한 줄을 지킵니다(배지 묶음만
  줄바꿈·축소를 허용). 고정·잠금은 아이콘만 두고 이름은 `title`·`aria-label` 로 제공하며,
  켜진 상태는 채운 색과 `aria-pressed` 로 나타냅니다.
  - **아이콘 대체**: 템플릿의 Font Awesome 은 Solid 전용 서브셋이라 목록에 없는 이름은
    오류 없이 빈칸으로 렌더됩니다. 그래서 서브셋에 있는 이름만 씁니다 —
    업·다운 `chevron-up`/`chevron-down`(`caret-*` 없음), 고정 `bullhorn`(`thumbtack` 없음;
    이 기능의 실체가 코어 공지라 뜻이 어긋나지 않습니다), 잠금 `lock`(`lock-open` 이 없어
    상태는 모양이 아니라 색으로 구분). **템플릿은 수정하지 않습니다.**
- **채택 답변 강조** *(1.3.0)*: 채택된 댓글은 행 전체에 초록 테두리·배경이 들어가고,
  "채택됨" 표시가 권한과 무관하게 모두에게 보입니다. 다크 모드에서도 같은 계열로 읽힙니다.
  강조는 `after_apply` 가 넘겨준 트리에서 댓글 행 컨테이너의 `className` 만 바꾸는
  방식이라 템플릿 파일을 건드리지 않고, 컨테이너의 `style`(댓글 깊이 들여쓰기)도 그대로
  둡니다. 채택이 아닌 행에는 같은 두께의 투명 테두리를 깔아 강조가 켜질 때 밀리지 않습니다.
- **베스트답글**: 글 작성자(또는 사이트 관리자 — 1.3.0 에서 바뀌지 않음)가 댓글 하나를 "채택된 답변"으로 지정.
  답글(대댓글)도 가능, 게시글당 1개, 채택 댓글 삭제 시 자동 해제.
- **채택된 답변 전문 표시** *(1.1.0 신규)*: 위젯에 채택된 답변의 서식 포함 전문(굵게·목록·
  인용구·표)을 작성자 아바타·이름·시각과 함께 표시. 기존 댓글과 동일한 이스케이프 후
  클라이언트 재정화 방식이라 별도의 XSS 방어 경로를 새로 열지 않습니다. 색상은 현재
  고정값(설정 UI 없음).
- **게시판 목록 참여자 · 최근 활동** *(1.1.0 신규)*: 게시판 목록에 "참여자" 컬럼(최근
  댓글 작성자 아바타, 최대 5명), 포럼 게시판은 "작성일"이 "최근 활동"으로 표시.
- **포럼 게시판 목록은 항상 최근활동순** *(1.2.0 신규)*: 포럼 유형 게시판의 목록은
  페이지를 나누기 전에 서버에서 최근 활동순(내림차순)으로 정렬됩니다. 방금 댓글이 달린
  글이 1페이지 맨 위로 올라옵니다. **요청의 `sort_by`/`sort_order` 와 게시판의 "기본 정렬"
  설정을 무시합니다** — 포럼에서 "최근 활동"은 고를 수 있는 정렬이 아니라 화면의 전제이고,
  목록에 보이는 값과 순서가 어긋나면 안 되기 때문입니다. 다른 게시판 유형은 그대로입니다.
  - **공지(고정) 글은 영향 없음** — 코어가 별도 쿼리로 1페이지 맨 앞에 붙이는 경로를
    건드리지 않습니다.
  - **관리자 게시글 목록도 같은 순서**가 됩니다(같은 코어 메서드를 쓰므로). 포럼 게시판에서는
    관리자 화면의 제목·작성자 정렬이 동작하지 않습니다.
  - 코어 목록 API 를 부르는 **봇 SSR·외부 연동도 같은 순서**를 받습니다.
  - "활동"은 글 작성 시각과 **삭제되지 않은** 댓글의 작성 시각 중 늦은 쪽입니다. 글·댓글
    수정, 리액션, 채택은 활동이 아닙니다. 댓글을 지우면 즉시 반영됩니다. 새 컬럼도 백필도
    없으며, 정의는 `src/Support/ActivityTime.php` 한 곳에 있고 표시값과 정렬이 이를 공유합니다.
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
