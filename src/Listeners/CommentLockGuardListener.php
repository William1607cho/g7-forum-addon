<?php

namespace Plugins\G7\Forum\Addon\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Modules\Sirsoft\Board\Exceptions\PostNotCommentableException;
use Plugins\G7\Forum\Addon\Support\PostLockState;

/**
 * 잠긴 포럼 게시글에 새 댓글/답글이 달리는 것을 **서버에서** 막는 리스너.
 *
 * 설계문서 §7: "잠금은 프론트 UI 차단뿐 아니라 API 레벨 검증 필수". 프론트(포럼 위젯)가
 * 입력 폼을 가리더라도, API 를 직접 때리면 통과되면 안 된다. 그래서 두 지점을 모두 건다:
 *
 *  1. `sirsoft-board.comment.store_validation_rules` (filter)
 *     — HTTP 폼 요청 검증 단계. 잠겨 있으면 항상 실패하는 합성 규칙을 규칙 배열 맨 앞에
 *       끼워 넣어 422 로 되돌린다. 프론트는 이 응답의 `message` 를 그대로 토스트로 띄운다
 *       (sirsoft-basic `_comment_input` 의 onError 는 `{{error.message}}` 를 표시).
 *       `before_create` 액션 등 부작용이 발화하기 전에 차단된다.
 *
 *  2. `sirsoft-board.comment.filter_create_data` (filter)
 *     — `CommentService::createComment()` 최종 관문. FormRequest 를 거치지 않는 경로
 *       (다른 훅, 서비스 직접 호출)까지 덮는다. 잠겨 있으면 `PostNotCommentableException`
 *       을 던진다 — sirsoft-board 의 User\CommentController::store 가 이 예외를 이미
 *       `catch` 해 422 + 메시지로 매핑한다(생성자가 public 이라 sirsoft-board 를 건드리지
 *       않고 인스턴스화 가능).
 *
 * ── 관리자 예외 없음 (1차 범위) ────────────────────────────────
 * 이번 이터레이션은 예외를 두지 않는다 — 관리자(작성자 본인 포함)도 잠긴 글엔 댓글을
 * 달 수 없다. 과설계 방지. 운영하며 "잠근 뒤에도 공지성 댓글은 달아야 한다"는 필요가
 * 확인되면 다음 이터레이션에서 `filter_create_data` 쪽에 관리자 우회를 추가한다
 * (그때도 위젯 폼 노출 조건과 함께 손봐야 함).
 */
class CommentLockGuardListener implements HookListenerInterface
{
    /** 합성 검증 키 — 실제 입력 필드와 겹치지 않는 이름 */
    private const LOCK_RULE_KEY = '_forum_lock';

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-board.comment.store_validation_rules' => [
                'method' => 'addLockRule',
                'type' => 'filter',
                'priority' => 20,
            ],
            'sirsoft-board.comment.filter_create_data' => [
                'method' => 'blockIfLocked',
                'type' => 'filter',
                'priority' => 20,
            ],
        ];
    }

    /**
     * 잠긴 게시글이면 규칙 배열 맨 앞에 "항상 실패" 규칙을 끼운다.
     *
     * @param  array<string, mixed>  $rules   StoreCommentRequest::rules() 가 만든 규칙
     * @param  \Illuminate\Foundation\Http\FormRequest  $request  댓글 생성 요청
     * @return array<string, mixed>
     */
    public function addLockRule(array $rules, $request): array
    {
        $postId = (int) $request->route('postId');

        if ($postId <= 0 || ! PostLockState::isLocked($postId)) {
            return $rules;
        }

        $message = (string) __('g7-forum-addon::messages.comment.locked');

        // 맨 앞에 둬서 다른 검증 오류보다 이 메시지가 응답 `message` 로 먼저 잡히게 한다.
        return array_merge(
            [self::LOCK_RULE_KEY => [
                function ($attribute, $value, $fail) use ($message) {
                    $fail($message);
                },
            ]],
            $rules
        );
    }

    /**
     * 최종 관문 — 잠긴 게시글이면 예외를 던져 생성 흐름을 중단한다.
     *
     * @param  array<string, mixed>  $data  댓글 생성 데이터 (post_id 포함)
     * @param  string  $slug  게시판 슬러그
     * @return array<string, mixed>
     *
     * @throws PostNotCommentableException
     */
    public function blockIfLocked(array $data, string $slug): array
    {
        $postId = (int) ($data['post_id'] ?? 0);

        if ($postId > 0 && PostLockState::isLocked($postId)) {
            throw new PostNotCommentableException('locked', 'g7-forum-addon::messages.comment.locked');
        }

        return $data;
    }

    /**
     * @inheritDoc
     */
    public function handle(...$args): void
    {
        // 이 리스너는 filter 훅만 구독한다.
    }
}
