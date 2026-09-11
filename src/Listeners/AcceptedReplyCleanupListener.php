<?php

namespace Plugins\G7\Forum\Addon\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Plugins\G7\Forum\Addon\Support\AcceptedReplyState;

/**
 * 댓글이 삭제되면, 그 댓글이 어느 게시글의 "채택된 답변"이었을 경우 채택을 자동 해제한다
 * (고아 참조 방지).
 *
 * `sirsoft-board.comment.after_delete` 액션 훅에 `sync: true` 로 붙는다 — sirsoft-board 의
 * 카운트 동기화 리스너들과 같은 방식. **직접 삭제 경로**(User/Admin CommentController →
 * `CommentService::deleteComment()`)만 이 훅을 발화한다. 게시글 삭제로 인한 cascade
 * 소프트삭제는 bulk update 라 훅이 안 뜨지만, 그때는 게시글 자체가 삭제되어 `/meta` 가
 * 404 이므로 무해하고, 블라인드/그 외 엣지는 `AcceptedReplyState::resolveForMeta()` 의
 * 지연 정리가 덮는다.
 */
class AcceptedReplyCleanupListener implements HookListenerInterface
{
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-board.comment.after_delete' => [
                'method' => 'onCommentDeleted',
                'type' => 'action',
                'priority' => 20,
                'sync' => true,
            ],
        ];
    }

    /**
     * @param  object  $comment  삭제된 댓글 모델 (post_id, id 보유)
     * @param  string  $slug     게시판 슬러그
     */
    public function onCommentDeleted($comment = null, $slug = null): void
    {
        if (! is_object($comment)) {
            return;
        }

        $postId = (int) ($comment->post_id ?? 0);
        $commentId = (int) ($comment->id ?? 0);

        AcceptedReplyState::clearIfMatches($postId, $commentId);
    }

    public function handle(...$args): void
    {
        $this->onCommentDeleted($args[0] ?? null, $args[1] ?? null);
    }
}
