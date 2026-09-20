<?php

namespace Plugins\G7\Forum\Addon\Support;

use Illuminate\Support\Facades\DB;

/**
 * 추천(업·다운) 저장·집계의 단일 지점.
 *
 * - 대상: `post`(board_posts) / `comment`(board_comments)
 * - 종류: **2종 — up / down** (1.3.0 에서 5종 이모지 like/love/haha/wow/sad 를 대체했다)
 * - **1인 1표**: 한 사용자는 한 대상에 표 1개. 같은 쪽 재클릭 = 취소,
 *   다른 쪽 클릭 = 전환. (`(target_type,target_id,user_id)` 유니크 제약과 짝)
 *
 * 저장 구조는 그대로다 — `reaction` 은 자유 문자열 컬럼이고 허용 값은 아래 상수뿐이므로
 * 종류 교체에 스키마 변경도 마이그레이션도 필요하지 않다. 전환 시점에 양 사이트의
 * 기존 행이 0 건이라 변환 대상도 없었다(1.3.0 조사).
 *
 * 모델 없이 쿼리 빌더로 처리(post_meta·lock 과 동일 방침). 테이블명은 코드 기준
 * `g7_forum_addon_reactions`, 커넥션 prefix 가 물리명 `g7_g7_forum_addon_reactions` 로 해석.
 */
class ReactionStore
{
    public const TABLE = 'g7_forum_addon_reactions';

    /** 허용 추천 종류 (순서 = 프론트 표시 순서) */
    public const REACTIONS = ['up', 'down'];

    /** 허용 대상 유형 */
    public const TARGETS = ['post', 'comment'];

    public static function isValidReaction(string $r): bool
    {
        return in_array($r, self::REACTIONS, true);
    }

    public static function isValidTarget(string $t): bool
    {
        return in_array($t, self::TARGETS, true);
    }

    /**
     * URL 세그먼트(`posts`/`comments`)를 내부 대상 유형(`post`/`comment`)으로.
     */
    public static function normalizeTargetType(string $segment): ?string
    {
        return match ($segment) {
            'posts', 'post' => 'post',
            'comments', 'comment' => 'comment',
            default => null,
        };
    }

    /**
     * 추천 토글/전환/취소.
     *
     * @return array{action: 'added'|'changed'|'removed', reaction: string|null}
     */
    public static function toggle(string $targetType, int $targetId, int $boardId, int $userId, string $reaction): array
    {
        $existing = DB::table(self::TABLE)
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->where('user_id', $userId)
            ->first();

        if ($existing === null) {
            DB::table(self::TABLE)->insert([
                'target_type' => $targetType,
                'target_id' => $targetId,
                'board_id' => $boardId,
                'user_id' => $userId,
                'reaction' => $reaction,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['action' => 'added', 'reaction' => $reaction];
        }

        if ($existing->reaction === $reaction) {
            DB::table(self::TABLE)->where('id', $existing->id)->delete();

            return ['action' => 'removed', 'reaction' => null];
        }

        DB::table(self::TABLE)->where('id', $existing->id)->update([
            'reaction' => $reaction,
            'board_id' => $boardId,
            'updated_at' => now(),
        ]);

        return ['action' => 'changed', 'reaction' => $reaction];
    }

    /**
     * 한 대상의 추천 요약.
     *
     * `counts` 는 `{up: n, down: n}`, `total` 은 둘의 합(투표 참여 수)이다.
     * 순점수(up - down)는 화면이 두 숫자를 각각 표시하므로 내려주지 않는다.
     *
     * @return array{counts: array<string,int>, total: int, mine: string|null}
     */
    public static function summary(string $targetType, int $targetId, ?int $userId): array
    {
        $rows = DB::table(self::TABLE)
            ->select('reaction', DB::raw('COUNT(*) as c'))
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->groupBy('reaction')
            ->pluck('c', 'reaction');

        $counts = [];
        $total = 0;
        foreach (self::REACTIONS as $r) {
            $n = (int) ($rows[$r] ?? 0);
            $counts[$r] = $n;
            $total += $n;
        }

        $mine = null;
        if ($userId !== null) {
            $mineRow = DB::table(self::TABLE)
                ->where('target_type', $targetType)
                ->where('target_id', $targetId)
                ->where('user_id', $userId)
                ->value('reaction');
            $mine = $mineRow !== null && self::isValidReaction($mineRow) ? $mineRow : null;
        }

        return ['counts' => $counts, 'total' => $total, 'mine' => $mine];
    }

    /**
     * 여러 댓글의 추천 요약을 한 번에.
     *
     * @param  array<int>  $commentIds
     * @return array<int, array{counts: array<string,int>, total: int, mine: string|null}>
     */
    public static function summaryForComments(array $commentIds, ?int $userId): array
    {
        $commentIds = array_values(array_unique(array_filter(array_map('intval', $commentIds), fn ($i) => $i > 0)));
        if ($commentIds === []) {
            return [];
        }

        $countRows = DB::table(self::TABLE)
            ->select('target_id', 'reaction', DB::raw('COUNT(*) as c'))
            ->where('target_type', 'comment')
            ->whereIn('target_id', $commentIds)
            ->groupBy('target_id', 'reaction')
            ->get();

        $mineRows = [];
        if ($userId !== null) {
            $mineRows = DB::table(self::TABLE)
                ->where('target_type', 'comment')
                ->whereIn('target_id', $commentIds)
                ->where('user_id', $userId)
                ->pluck('reaction', 'target_id')
                ->toArray();
        }

        $out = [];
        foreach ($commentIds as $cid) {
            $counts = array_fill_keys(self::REACTIONS, 0);
            $out[$cid] = ['counts' => $counts, 'total' => 0, 'mine' => $mineRows[$cid] ?? null];
        }
        foreach ($countRows as $row) {
            $cid = (int) $row->target_id;
            if (! isset($out[$cid]) || ! self::isValidReaction($row->reaction)) {
                continue;
            }
            $out[$cid]['counts'][$row->reaction] = (int) $row->c;
            $out[$cid]['total'] += (int) $row->c;
        }

        return $out;
    }
}
