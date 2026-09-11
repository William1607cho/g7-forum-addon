<?php

namespace Plugins\G7\Forum\Addon\Http\Controllers;

use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Plugins\G7\Forum\Addon\Support\AcceptedReplyState;
use Plugins\G7\Forum\Addon\Support\PostVisibilityGuard;

/**
 * 베스트답글(채택) — 게시글 작성자(또는 관리자)가 답글 하나를 "채택된 답변"으로 지정.
 *
 *   POST /api/plugins/g7-forum-addon/posts/{postId}/comments/{commentId}/accept
 *   POST /api/plugins/g7-forum-addon/posts/{postId}/comments/{commentId}/unaccept
 *
 * ── 정책 (1차) ─────────────────────────────────────────────────
 * - **권한**: 게시글 작성자 본인 + 사이트 관리자(`User::isAdmin()`). 채택은 게시글 소유자의
 *   권리지만, 관리자 대리 채택 여지를 열어두는 게 자연스럽다(리액션의 "관리자 예외 없음"
 *   과는 성격이 다름).
 * - **대상**: 그 게시글에 속한, 삭제/블라인드 안 된 댓글. **답글(depth>0)도 가능**(Q&A 관례).
 * - **게시글당 1개**(단일 컬럼). `accept` 는 항상 그 댓글로 지정(다른 게 채택돼 있으면 교체).
 *   `unaccept` 는 그 댓글이 현재 채택 상태일 때만 해제.
 * - **잠금과 무관** — 잠긴 게시글에서도 채택 가능(마무리된 토론에 결론을 붙이는 흐름).
 *
 * 라우트 `auth:sanctum` → 로그인 필수. `/meta` 와 동일한 `PostVisibilityGuard` 가시성
 * 재검증 + 포럼유형 게이팅.
 */
class AcceptedReplyController extends PublicBaseController
{
    public function __construct(
        private readonly PostVisibilityGuard $visibilityGuard,
    ) {
        parent::__construct();
    }

    public function accept(Request $request, int $postId, int $commentId): JsonResponse
    {
        return $this->apply($request, $postId, $commentId, accept: true);
    }

    public function unaccept(Request $request, int $postId, int $commentId): JsonResponse
    {
        return $this->apply($request, $postId, $commentId, accept: false);
    }

    private function apply(Request $request, int $postId, int $commentId, bool $accept): JsonResponse
    {
        // 게시글 가시성 재검증 (불가 시 404/403).
        $resolved = $this->visibilityGuard->assertViewable($request, $postId);

        if (($resolved->board->type ?? null) !== 'forum') {
            return $this->error('g7-forum-addon::messages.accepted_reply.not_forum', 404);
        }

        // 권한: 게시글 작성자 본인 + 사이트 관리자.
        $userId = Auth::id();
        $isAuthor = $userId !== null && (int) $resolved->post->user_id === (int) $userId;
        $isAdmin = (bool) ($request->user()?->isAdmin());
        if (! $isAuthor && ! $isAdmin) {
            return $this->error('g7-forum-addon::messages.accepted_reply.forbidden', 403);
        }

        // 대상 댓글 검증: 이 게시글 소속 + 삭제/블라인드 아님.
        $comment = DB::table('board_comments')->where('id', $commentId)->first();
        if ($comment === null
            || (int) $comment->post_id !== $postId
            || $comment->deleted_at !== null
            || in_array($comment->status, ['blinded', 'deleted'], true)) {
            return $this->error('g7-forum-addon::messages.accepted_reply.bad_comment', 404);
        }

        $boardId = (int) $resolved->post->board_id;

        if ($accept) {
            AcceptedReplyState::set($postId, $boardId, $commentId);

            return $this->success('g7-forum-addon::messages.accepted_reply.accepted', [
                'accepted_reply_id' => $commentId,
            ]);
        }

        // 해제 — 현재 채택된 게 이 댓글일 때만 실제로 지운다(경쟁 상황 방어).
        AcceptedReplyState::clearIfMatches($postId, $commentId);

        return $this->success('g7-forum-addon::messages.accepted_reply.unaccepted', [
            'accepted_reply_id' => AcceptedReplyState::getRaw($postId),
        ]);
    }
}
