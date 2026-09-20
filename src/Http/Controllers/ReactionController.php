<?php

namespace Plugins\G7\Forum\Addon\Http\Controllers;

use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Plugins\G7\Forum\Addon\Support\PostVisibilityGuard;
use Plugins\G7\Forum\Addon\Support\ReactionStore;

/**
 * 포럼 게시글·댓글 추천(업·다운) 토글.
 *
 *   POST /api/plugins/g7-forum-addon/{targetType}/{id}/reactions   body: {reaction: "up"|"down"}
 *     targetType ∈ {posts, comments}
 *
 * ── 동작 ────────────────────────────────────────────────────────
 * **1인 1표 토글**: 표가 없으면 추가, 반대쪽이면 전환, 같은 쪽을 다시 누르면 취소.
 * 별도 DELETE 엔드포인트 없음(토글 하나로 처리 — 더 간단).
 *
 * 1.3.0 에서 5종 이모지(like/love/haha/wow/sad)를 업·다운 2종으로 바꿨다. 허용 값은
 * {@see ReactionStore::REACTIONS} 뿐이고, 옛 이모지 값을 보내면 422 로 거부된다.
 *
 * ── 권한 ────────────────────────────────────────────────────────
 * 라우트에 `auth:sanctum` → **로그인 사용자만.** 비회원 추천은 어뷰징 우려로 막는다
 * (댓글 작성보다 추천 남용 방지 우선순위가 높음). 비회원은 401.
 *
 * 투표 가능한 회원 범위는 따로 두지 않는다 — 게시판 읽기 권한(`posts.read`)을 통과해
 * 대상이 보이는 사용자면 누구나 투표할 수 있다(가시성 판정이 곧 권한 판정).
 *
 * ── 본인 글·본인 댓글 투표 금지 (1.3.0) ────────────────────────
 * 자기 글/댓글에는 업도 다운도 넣을 수 없다. 서버가 403 으로 거부하고, 화면도
 * 같은 조건으로 버튼을 비활성화한다(`post.data.is_owner` / `comment.is_author`).
 * 서버 판정이 최종이며 화면 비활성화는 안내용이다.
 *
 * ── 가시성 / 포럼 게이팅 ───────────────────────────────────────
 * `/meta` 와 동일하게 `PostVisibilityGuard` 로 대상(게시글, 또는 댓글→소속 게시글)의
 * 가시성을 재검증하고, 소속 게시판이 포럼형(`forum`)인지 확인한다(아니면 404).
 *
 * ── 잠금과 무관 ────────────────────────────────────────────────
 * 이 엔드포인트는 `CommentService`/`StoreCommentRequest` 를 거치지 않으므로
 * `CommentLockGuardListener`(댓글 작성 차단)와 상호작용이 없다. 잠긴 게시글에서도 추천은
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
            $ownerId = $resolved->post->user_id;
        } else {
            $resolved = $this->visibilityGuard->assertCommentTargetViewable($request, $id);
            $board = $resolved->board;
            $boardId = (int) $resolved->comment->board_id;
            $ownerId = $resolved->comment->user_id;
        }

        if (($board->type ?? null) !== 'forum') {
            return $this->error('g7-forum-addon::messages.reaction.not_forum', 404);
        }

        // 본인 글·본인 댓글 투표 금지. 비회원이 쓴 글(user_id = null)은 소유자가 없으므로
        // 이 조건에 걸리지 않는다.
        if ($ownerId !== null && (int) $ownerId === (int) $userId) {
            return $this->error('g7-forum-addon::messages.reaction.self_vote', 403);
        }

        $result = ReactionStore::toggle($type, $id, $boardId, (int) $userId, $reaction);

        return $this->success('g7-forum-addon::messages.reaction.ok', [
            'action' => $result['action'],
            'reaction' => $result['reaction'],
            'summary' => ReactionStore::summary($type, $id, (int) $userId),
        ]);
    }
}
