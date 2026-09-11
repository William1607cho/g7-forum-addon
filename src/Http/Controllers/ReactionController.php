<?php

namespace Plugins\G7\Forum\Addon\Http\Controllers;

use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Plugins\G7\Forum\Addon\Support\ReactionStore;
use Plugins\G7\Forum\Addon\Support\PostVisibilityGuard;

/**
 * 포럼 게시글·댓글 리액션(추천/좋아요) 토글.
 *
 *   POST /api/plugins/g7-forum-addon/{targetType}/{id}/reactions   body: {reaction: "like"}
 *     targetType ∈ {posts, comments}
 *
 * ── 동작 ────────────────────────────────────────────────────────
 * **1인 1리액션 토글**: 리액션이 없으면 추가, 다른 종류면 교체, 같은 종류를 다시 누르면 취소.
 * 별도 DELETE 엔드포인트 없음(토글 하나로 처리 — 더 간단).
 *
 * ── 권한 ────────────────────────────────────────────────────────
 * 라우트에 `auth:sanctum` → **로그인 사용자만.** 비회원 리액션은 어뷰징 우려로 막는다
 * (댓글 작성보다 추천 남용 방지 우선순위가 높음). 비회원은 401.
 *
 * ── 가시성 / 포럼 게이팅 ───────────────────────────────────────
 * `/meta` 와 동일하게 `PostVisibilityGuard` 로 대상(게시글, 또는 댓글→소속 게시글)의
 * 가시성을 재검증하고, 소속 게시판이 포럼형(`forum`)인지 확인한다(아니면 404).
 *
 * ── 잠금과 무관 ────────────────────────────────────────────────
 * 이 엔드포인트는 `CommentService`/`StoreCommentRequest` 를 거치지 않으므로
 * `CommentLockGuardListener`(댓글 작성 차단)와 상호작용이 없다. 잠긴 게시글에서도 리액션은
 * 정상 동작한다(잠금 기능 보고서 권고).
 */
class ReactionController extends PublicBaseController
{
    public function __construct(
        private readonly PostVisibilityGuard $visibilityGuard,
    ) {
        parent::__construct();
    }

    /**
     * @param  string  $targetType  URL 세그먼트: posts | comments
     * @param  int     $id          대상 ID (board_posts.id 또는 board_comments.id)
     */
    public function toggle(Request $request, string $targetType, int $id): JsonResponse
    {
        $type = ReactionStore::normalizeTargetType($targetType);
        if ($type === null) {
            return $this->error('g7-forum-addon::messages.reaction.bad_target', 404);
        }

        $reaction = (string) $request->input('reaction', '');
        if (! ReactionStore::isValidReaction($reaction)) {
            return $this->error('g7-forum-addon::messages.reaction.bad_reaction', 422);
        }

        $userId = Auth::id();
        if ($userId === null) {
            return $this->error('g7-forum-addon::messages.reaction.login_required', 401);
        }

        // 대상 해석 + 가시성/포럼유형 재검증.
        if ($type === 'post') {
            $resolved = $this->visibilityGuard->assertViewable($request, $id);
            $board = $resolved->board;
            $boardId = (int) $resolved->post->board_id;
        } else {
            $resolved = $this->visibilityGuard->assertCommentTargetViewable($request, $id);
            $board = $resolved->board;
            $boardId = (int) $resolved->comment->board_id;
        }

        if (($board->type ?? null) !== 'forum') {
            return $this->error('g7-forum-addon::messages.reaction.not_forum', 404);
        }

        $result = ReactionStore::toggle($type, $id, $boardId, (int) $userId, $reaction);

        return $this->success('g7-forum-addon::messages.reaction.ok', [
            'action' => $result['action'],
            'reaction' => $result['reaction'],
            'summary' => ReactionStore::summary($type, $id, (int) $userId),
        ]);
    }
}
