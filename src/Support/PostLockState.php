<?php

namespace Plugins\G7\Forum\Addon\Support;

use Illuminate\Support\Facades\DB;

/**
 * 게시글 잠금(Lock) 상태의 단일 조회 지점.
 *
 * 잠금은 **포럼형(`forum`) 게시판의 게시글에만** 의미가 있다. basic/gallery/card 게시판의
 * 게시글은 애드온 메타 행이 있더라도 잠금으로 취급하지 않는다(게이팅 재확인). 판정은
 * `g7_forum_addon_post_meta.is_locked = 1` AND 소속 게시판 `boards.type = 'forum'` 두 조건을
 * 모두 만족할 때만 참이다.
 *
 * 모델을 두지 않고 쿼리 빌더 한 방으로 처리한다 — 애드온이 대상 모듈(sirsoft-board) 스키마에
 * Eloquent 관계로 묶이지 않도록 하는 뼈대 단계 방침을 잇는다. 테이블명은 코드 기준
 * `g7_forum_addon_post_meta` 로 적고, 커넥션 prefix(`g7_`)가 물리 테이블
 * `g7_g7_forum_addon_post_meta` 로 해석한다(스캐폴드 마이그레이션과 동일 규약).
 */
class PostLockState
{
    /** 애드온 메타 테이블 (코드상 이름 — 커넥션 prefix 가 물리명으로 해석) */
    public const META_TABLE = 'g7_forum_addon_post_meta';

    /**
     * 해당 게시글이 "잠긴 포럼 게시글"인지.
     *
     * @param  int  $postId  board_posts.id
     */
    public static function isLocked(int $postId): bool
    {
        if ($postId <= 0) {
            return false;
        }

        return DB::table(self::META_TABLE.' as m')
            ->join('boards as b', 'b.id', '=', 'm.board_id')
            ->where('m.post_id', $postId)
            ->where('m.is_locked', 1)
            ->where('b.type', 'forum')
            ->exists();
    }

    /**
     * 게시글의 현재 잠금 값을 그대로 반환(메타 행이 없으면 false).
     *
     * `isLocked()` 와 달리 board_type 게이팅을 하지 않는다 — 호출부가 이미 포럼 게시글임을
     * 확인한 맥락(메타 응답 조립 등)에서 쓴다.
     *
     * @param  int  $postId  board_posts.id
     */
    public static function rawFlag(int $postId): bool
    {
        if ($postId <= 0) {
            return false;
        }

        return (bool) DB::table(self::META_TABLE)
            ->where('post_id', $postId)
            ->value('is_locked');
    }

    /**
     * 게시글의 잠금 상태를 설정한다(메타 행 upsert).
     *
     * 행이 없으면 만들고, 있으면 `is_locked` 만 갱신한다. `created_at` 은 최초 삽입 때만
     * 쓴다(토글마다 덮어쓰지 않음).
     *
     * @param  int  $postId   board_posts.id
     * @param  int  $boardId  boards.id (행 스코프·정리용)
     * @param  bool  $locked  목표 잠금 상태
     */
    public static function setLocked(int $postId, int $boardId, bool $locked): void
    {
        $exists = DB::table(self::META_TABLE)->where('post_id', $postId)->exists();

        if ($exists) {
            DB::table(self::META_TABLE)
                ->where('post_id', $postId)
                ->update([
                    'board_id' => $boardId,
                    'is_locked' => $locked ? 1 : 0,
                    'updated_at' => now(),
                ]);

            return;
        }

        DB::table(self::META_TABLE)->insert([
            'post_id' => $postId,
            'board_id' => $boardId,
            'is_locked' => $locked ? 1 : 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
