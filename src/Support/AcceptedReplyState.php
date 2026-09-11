<?php

namespace Plugins\G7\Forum\Addon\Support;

use Illuminate\Support\Facades\DB;

/**
 * 베스트답글(채택) 상태의 단일 지점.
 *
 * 게시글당 채택 답글 1개 — `g7_forum_addon_post_meta.accepted_reply_id`(단일 컬럼이라
 * 자연히 1개). 채택 대상은 그 게시글에 속한, 삭제/블라인드 안 된 댓글(최상위·답글 무관).
 *
 * ── 삭제 시 자동 해제 (2단계) ──────────────────────────────────
 *  1. `AcceptedReplyCleanupListener` 가 `sirsoft-board.comment.after_delete`(직접 삭제 경로)
 *     에서 `clearIfMatches()` 호출 — 즉시 정리.
 *  2. `resolveForMeta()` 가 `/meta` 조회 시점에 채택 댓글의 상태를 재확인해, 삭제/블라인드/
 *     타 게시글 소속이면 그 자리에서 null 로 되돌린다(자기치유). cascade 삭제(게시글 삭제로
 *     댓글이 bulk 소프트삭제 — 훅 미발화)나 블라인드까지 덮는다.
 *
 * 잠금(`is_locked`)과 같은 메타 행을 쓰지만 서로 다른 컬럼만 건드린다. insert 경로는
 * 행이 없을 때만 타므로 `is_locked` 기본값(0)을 덮어써도 무해하다.
 */
class AcceptedReplyState
{
    public const META_TABLE = 'g7_forum_addon_post_meta';

    /**
     * 게시글의 채택 답글 id (원시값, 정리 안 함).
     */
    public static function getRaw(int $postId): ?int
    {
        if ($postId <= 0) {
            return null;
        }

        $v = DB::table(self::META_TABLE)->where('post_id', $postId)->value('accepted_reply_id');

        return $v !== null ? (int) $v : null;
    }

    /**
     * 채택 답글을 설정한다(메타 행 upsert). null 이면 채택 해제.
     *
     * @param  int|null  $commentId  board_comments.id 또는 null
     */
    public static function set(int $postId, int $boardId, ?int $commentId): void
    {
        $exists = DB::table(self::META_TABLE)->where('post_id', $postId)->exists();

        if ($exists) {
            DB::table(self::META_TABLE)->where('post_id', $postId)->update([
                'accepted_reply_id' => $commentId,
                'board_id' => $boardId,
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table(self::META_TABLE)->insert([
            'post_id' => $postId,
            'board_id' => $boardId,
            'is_locked' => 0,
            'accepted_reply_id' => $commentId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * 삭제되는 댓글이 그 게시글의 채택 답글이면 채택을 해제한다.
     *
     * `sirsoft-board.comment.after_delete` 리스너에서 호출. `$comment->post_id` /
     * `$comment->id` 만 있으면 되므로 board_id 재조회 없이 부분 업데이트한다.
     */
    public static function clearIfMatches(int $postId, int $commentId): void
    {
        if ($postId <= 0 || $commentId <= 0) {
            return;
        }

        DB::table(self::META_TABLE)
            ->where('post_id', $postId)
            ->where('accepted_reply_id', $commentId)
            ->update([
                'accepted_reply_id' => null,
                'updated_at' => now(),
            ]);
    }

    /**
     * `/meta` 응답용 채택 답글 id — 유효하지 않으면 그 자리에서 정리하고 null 반환.
     *
     * 유효 = 그 댓글이 존재 · 이 게시글 소속 · soft-delete 안 됨 · status ∉ {blinded, deleted}.
     */
    public static function resolveForMeta(int $postId): ?int
    {
        $id = self::getRaw($postId);
        if ($id === null) {
            return null;
        }

        $ok = DB::table('board_comments')
            ->where('id', $id)
            ->where('post_id', $postId)
            ->whereNull('deleted_at')
            ->whereNotIn('status', ['blinded', 'deleted'])
            ->exists();

        if ($ok) {
            return $id;
        }

        // 고아/무효 참조 — 자기치유.
        DB::table(self::META_TABLE)
            ->where('post_id', $postId)
            ->where('accepted_reply_id', $id)
            ->update(['accepted_reply_id' => null, 'updated_at' => now()]);

        return null;
    }
}
