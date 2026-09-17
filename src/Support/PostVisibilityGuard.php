<?php

namespace Plugins\G7\Forum\Addon\Support;

use App\Helpers\PermissionHelper;
use App\Helpers\ResponseHelper;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 애드온 엔드포인트가 게시글 메타를 내려주기 전, sirsoft-board 와 **동일한 가시성 규칙**을
 * 자체적으로 재검증하는 게이트.
 *
 * 왜 필요한가: 애드온 API 는 sirsoft-board 의 컨트롤러/미들웨어를 거치지 않는다. 인증
 * 여부만 보고 응답하면 추천수·태그·구독여부가 비밀글/블라인드/삭제글의 존재나 내용을
 * 추론하는 우회 채널이 된다(설계문서 §4). 그래서 게시글을 실제로 조회하고, sirsoft-board
 * 가 적용하는 것과 같은 조건으로 열람 가능 여부를 먼저 판정한다.
 *
 * 판정 범위:
 *  - 존재/소프트삭제/게시판 활성 : 완전 반영 (404)
 *  - 게시판별 읽기 권한(ACL)      : `sirsoft-board.{slug}.posts.read` — sirsoft-board 라우트의
 *                                  permission 미들웨어와 같은 판정·같은 순서·같은 응답
 *                                  (비회원 401 / 권한 없는 회원 403). 1.1.1 에서 추가.
 *  - status = blinded / deleted  : 작성자 본인만 통과 (매니저 권한 경로는 TODO)
 *  - is_secret                   : 작성자 본인만 통과 (로그인+게시판권한/비번검증 토큰 경로는 TODO)
 *
 * TODO 표시 지점은 각 포럼 기능이 실제 데이터를 붙이기 전에 sirsoft-board 의 PostResource/
 * CommentResource 가 쓰는 `canViewSecretForPost` 등가 로직으로 채운다.
 */
class PostVisibilityGuard
{
    /**
     * 게시글이 요청자에게 열람 가능한지 확인하고, 불가하면 HTTP 예외로 중단한다.
     *
     * @return object{post: object, board: object} 통과 시 게시글/게시판 행
     */
    public function assertViewable(Request $request, int $postId): object
    {
        $post = DB::table('board_posts')->where('id', $postId)->first();

        // 존재하지 않음 / 소프트 삭제됨 → 404 (삭제글 열람은 뼈대 범위 밖)
        if ($post === null || $post->deleted_at !== null) {
            abort(404, 'Post not found.');
        }

        $board = DB::table('boards')->where('id', $post->board_id)->first();

        if ($board === null || (int) $board->is_active !== 1) {
            abort(404, 'Post not found.');
        }

        // 게시판 읽기 권한 — 블라인드/비밀글보다 먼저 (sirsoft-board 는 미들웨어에서 먼저 막는다).
        $this->assertBoardReadable($request, $board);

        $userId = $request->user()?->id;
        $isAuthor = $userId !== null && (int) $post->user_id === (int) $userId;

        // 블라인드/삭제 상태 → 작성자 본인만 (매니저 권한 경로 TODO)
        if (in_array($post->status, ['blinded', 'deleted'], true) && ! $isAuthor) {
            abort(403, 'This post is not viewable.');
        }

        // 비밀글 → 작성자 본인만 (로그인+게시판권한 / 비번검증 토큰 경로 TODO)
        if ((int) $post->is_secret === 1 && ! $isAuthor) {
            abort(403, 'This post is secret.');
        }

        return (object) ['post' => $post, 'board' => $board];
    }

    /**
     * 요청자가 게시판 글 읽기 권한(`sirsoft-board.{slug}.posts.read`)을 가졌는지 확인하고,
     * 없으면 sirsoft-board 의 PermissionMiddleware 와 같은 응답으로 중단한다.
     *
     * - 비회원: 401 `auth.guest_permission_denied`
     * - 회원  : 403 `auth.permission_denied` — 회원에게 401 을 주면 프론트 ApiClient 가
     *           토큰 만료로 보고 강제 로그아웃하므로 반드시 403 이어야 한다.
     *
     * @param  object  $board  boards 행 (slug 필요)
     */
    public function assertBoardReadable(Request $request, object $board): void
    {
        $ability = "sirsoft-board.{$board->slug}.posts.read";
        $user = $request->user();

        if (PermissionHelper::check($ability, $user)) {
            return;
        }

        $params = ['required_permissions' => $ability];

        throw new HttpResponseException(
            $user === null
                ? ResponseHelper::unauthorized('auth.guest_permission_denied', $params)
                : ResponseHelper::forbidden('auth.permission_denied', $params)
        );
    }

    /**
     * 댓글을 리액션 대상으로 삼기 전, 댓글 존재/상태 + 소속 게시글 가시성을 재검증한다.
     *
     * 삭제(soft delete)·삭제 상태·블라인드 댓글에는 리액션할 수 없다. 통과 조건이면
     * 댓글이 속한 게시글에 대해 `assertViewable()` 을 그대로 태워 게시글 가시성까지 확인한다.
     *
     * @return object{comment: object, post: object, board: object} 통과 시 행들
     */
    public function assertCommentTargetViewable(Request $request, int $commentId): object
    {
        $comment = DB::table('board_comments')->where('id', $commentId)->first();

        if ($comment === null || $comment->deleted_at !== null) {
            abort(404, 'Comment not found.');
        }

        if (in_array($comment->status, ['blinded', 'deleted'], true)) {
            abort(403, 'This comment is not viewable.');
        }

        // 소속 게시글 가시성 재검증 (존재/삭제/블라인드/비밀글/게시판 활성).
        $resolved = $this->assertViewable($request, (int) $comment->post_id);

        return (object) [
            'comment' => $comment,
            'post' => $resolved->post,
            'board' => $resolved->board,
        ];
    }
}
