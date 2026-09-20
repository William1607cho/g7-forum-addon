<?php

namespace Plugins\G7\Forum\Addon\Support;

use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * 포럼 게시글의 "최근 활동 시각" 정의 — **이 플러그인에서 이 정의의 단일 원본**.
 *
 * ── 정의 ────────────────────────────────────────────────────────
 *   활동 시각(글) = 그 글의 작성 시각과, 그 글에 달린 **삭제되지 않은** 댓글의
 *                   작성 시각을 통틀어 가장 늦은 값
 *
 * - 글·댓글의 **수정**은 활동이 아니다 (`updated_at` 을 보지 않는다).
 *   리액션·베스트답글 채택도 활동이 아니다.
 * - 봇 계정이 단 댓글도 사람과 똑같이 활동으로 센다 (작성자 조건 없음).
 * - 댓글이 하나도 없으면 글 작성 시각이 그대로 활동 시각이다.
 *   → 모든 글이 값을 가지므로 별도 저장 컬럼도, 과거 데이터 백필도 필요 없다.
 * - 댓글을 삭제하면(`deleted_at IS NOT NULL`) 그 즉시 집계에서 빠져 활동 시각이
 *   이전 값으로 되돌아간다.
 *
 * ── 왜 한 클래스에 두 가지 형태를 두는가 ────────────────────────
 * 같은 정의를 쓰는 자리가 둘인데 필요한 SQL 모양이 다르다.
 *
 *   1. **표시** — 목록 화면이 이미 뽑아 놓은 게시글 ID 묶음(한 페이지치)에 대해
 *      한 번에 값을 구한다 → {@see self::batch()} (파생 테이블 + `GROUP BY`)
 *   2. **정렬** — 페이지를 나누기 **전에** 게시판 전체를 줄 세워야 한다 →
 *      {@see self::orderColumn()} (행마다 값 하나를 주는 상관 스칼라 서브쿼리)
 *
 * 두 SQL 은 결과가 **정확히 같아야 한다.** 표시값과 정렬 순서가 어긋나면 사용자는
 * "최근 활동" 열을 보고도 왜 이 순서인지 알 수 없다. 그래서 테이블·컬럼·제외 조건을
 * 아래 상수 한 벌로 묶고, 두 형태 모두 여기서만 만든다. 정의를 바꿀 일이 생기면
 * 이 파일만 고친다.
 *
 * `orderColumn()` 이 `GREATEST(...)` 로 글 작성 시각을 한 번 더 비교하는 것은
 * `batch()` 와의 동치를 보장하기 위해서다 — 댓글 시각이 글 시각보다 이른 오염된
 * 행이 있어도 두 경로가 같은 값을 낸다.
 *
 * sirsoft-board 의 Eloquent 모델(`Post`/`Comment`)은 쓰지 않는다 — 이 플러그인의
 * 기존 방침대로 쿼리 빌더와 원본 SQL 로만 코어 테이블을 읽는다.
 * 대상 DB 는 MariaDB 10.11 (`GREATEST` / 파생 테이블 / 윈도우 함수 사용).
 */
final class ActivityTime
{
    /** 코어 sirsoft-board 게시글 테이블 (DB 접두어 미포함) */
    public const POSTS_TABLE = 'board_posts';

    /** 코어 sirsoft-board 댓글 테이블 (DB 접두어 미포함) */
    public const COMMENTS_TABLE = 'board_comments';

    /** 활동 시각으로 삼는 컬럼 — 작성 시각이다. 수정 시각(`updated_at`)이 아니다. */
    public const TIME_COLUMN = 'created_at';

    /** 한 번에 집계할 수 있는 게시글 ID 상한 (배치 남용 방지) */
    public const MAX_POST_IDS = 100;

    /**
     * 게시글 ID 묶음의 활동 시각을 한 번에 구합니다 (표시용).
     *
     * @param  int  $boardId  boards.id
     * @param  array<int, int>  $postIds  이미 가시성 필터를 통과한 게시글 ID
     * @return array<int, string> post_id => 활동 시각 문자열 (DB 원본 포맷)
     */
    public static function batch(int $boardId, array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }

        $posts = self::qualified(self::POSTS_TABLE);
        $comments = self::qualified(self::COMMENTS_TABLE);
        $time = self::TIME_COLUMN;
        $placeholders = implode(',', array_fill(0, count($postIds), '?'));

        // 글 자신 + 삭제되지 않은 댓글을 한 줄기로 모아(UNION ALL) 글별 최댓값을 취한다.
        // 익명/탈퇴 작성자의 댓글도 시각은 유효하므로 user_id 조건을 두지 않는다.
        $rows = DB::select(
            "SELECT post_id, MAX({$time}) AS last_activity_at FROM (
                SELECT post_id, {$time} FROM {$comments}
                WHERE board_id = ? AND post_id IN ({$placeholders}) AND deleted_at IS NULL
                UNION ALL
                SELECT id AS post_id, {$time} FROM {$posts}
                WHERE board_id = ? AND id IN ({$placeholders})
            ) g7_forum_addon_events
            GROUP BY post_id",
            [$boardId, ...$postIds, $boardId, ...$postIds]
        );

        $byPost = [];
        foreach ($rows as $row) {
            $byPost[(int) $row->post_id] = $row->last_activity_at;
        }

        return $byPost;
    }

    /**
     * 활동 시각을 행마다 하나씩 돌려주는 상관 서브쿼리를 만듭니다 (정렬용).
     *
     * 코어 `PaginatesWithDeferredJoin` 의 정렬 스펙은 `column` 으로 문자열뿐 아니라
     * 쿼리 빌더도 받아 `ORDER BY (서브쿼리)` 로 전개한다. 바깥 게시글 행(`board_posts`)에
     * 상관되므로 inner(ID 추출)와 outer(행 조회) 어느 쪽에 걸어도 같은 순서가 나온다.
     *
     * 집계 함수는 `GROUP BY` 없이 항상 한 행을 돌려주므로, 댓글이 없으면
     * `MAX(...)` 가 `NULL` → `GREATEST(...)` 도 `NULL` → `COALESCE` 가 글 작성 시각을 준다.
     *
     * 인덱스: `idx_board_comments_post_deleted_created (board_id, post_id, deleted_at, created_at)`
     * 가 board_id·post_id 등치 + `deleted_at IS NULL` + `MAX(created_at)` 을 그대로 받친다.
     *
     * @return QueryBuilder 정렬 스펙의 `column` 으로 넘길 상관 서브쿼리
     */
    public static function orderColumn(): QueryBuilder
    {
        $posts = self::qualified(self::POSTS_TABLE);
        $comments = self::qualified(self::COMMENTS_TABLE);
        $time = self::TIME_COLUMN;

        return DB::table(self::COMMENTS_TABLE)
            ->selectRaw("COALESCE(GREATEST(MAX({$comments}.{$time}), {$posts}.{$time}), {$posts}.{$time})")
            ->whereColumn(self::COMMENTS_TABLE.'.post_id', self::POSTS_TABLE.'.id')
            ->whereColumn(self::COMMENTS_TABLE.'.board_id', self::POSTS_TABLE.'.board_id')
            ->whereNull(self::COMMENTS_TABLE.'.deleted_at');
    }

    /**
     * 원본 SQL 안에 쓸 수 있도록 DB 접두어를 붙인 테이블명을 돌려줍니다.
     *
     * 쿼리 빌더 경로(`DB::table()` / `whereColumn()`)는 접두어를 알아서 붙이므로
     * 접두어 없는 이름을 넘겨야 하고, `selectRaw()` / `DB::select()` 의 원본 SQL 에는
     * 접두어가 붙은 이름이 필요하다. 두 경로가 섞이는 자리라 헷갈리기 쉬워 분리해 둔다.
     */
    private static function qualified(string $table): string
    {
        return DB::getTablePrefix().$table;
    }
}
