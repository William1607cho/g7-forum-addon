<?php

namespace Plugins\G7\Forum\Addon\Tests\Feature;

use Illuminate\Http\Exceptions\HttpResponseException;
use Plugins\G7\Forum\Addon\Http\Controllers\AcceptedReplyController;
use Plugins\G7\Forum\Addon\Http\Controllers\ForumListMetaController;
use Plugins\G7\Forum\Addon\Http\Controllers\PostMetaController;
use Plugins\G7\Forum\Addon\Http\Controllers\ReactionController;
use Plugins\G7\Forum\Addon\Support\PostVisibilityGuard;
use Plugins\G7\Forum\Addon\Tests\PluginTestCase;

/**
 * 게시판 읽기 권한(`sirsoft-board.{slug}.posts.read`) 재검증 (1.1.1)
 *
 * 기대 응답은 sirsoft-board 게시글 API 의 PermissionMiddleware 와 같다:
 * 비회원 401 / 권한 없는 회원 403. 회원에게 401 을 주면 프론트가 세션 만료로 보고
 * 강제 로그아웃하므로 403 이어야 한다.
 */
class PostVisibilityPermissionTest extends PluginTestCase
{
    private function meta(?\App\Models\User $user, int $postId): int
    {
        $request = $this->requestAs($user);

        return $this->statusOf(fn () => app(PostMetaController::class)->show($request, $postId));
    }

    private function listMeta(?\App\Models\User $user, string $slug, string $ids): int
    {
        $request = $this->requestAs($user, ['post_ids' => $ids]);

        return $this->statusOf(fn () => app(ForumListMetaController::class)->index($request, $slug));
    }

    // ── /meta ────────────────────────────────────────────────

    public function test_guest_gets_401_on_board_without_read_permission(): void
    {
        $board = $this->createBoard('basic');
        $this->declarePermission($board);
        $postId = $this->createPost($board);

        $this->assertSame(401, $this->meta(null, $postId));
    }

    public function test_member_without_read_permission_gets_403_not_401(): void
    {
        $board = $this->createBoard('basic');
        $this->declarePermission($board);
        $postId = $this->createPost($board);

        $this->assertSame(403, $this->meta($this->createUserWithRole('user'), $postId));
    }

    public function test_member_with_read_permission_gets_200(): void
    {
        $board = $this->createBoard('basic');
        $this->grant('operator', $board);
        $postId = $this->createPost($board);

        $this->assertSame(200, $this->meta($this->createUserWithRole('operator'), $postId));
    }

    public function test_guest_gets_200_on_public_forum_post(): void
    {
        $board = $this->createBoard('forum');
        $this->grant('guest', $board);
        $postId = $this->createPost($board);

        $this->assertSame(200, $this->meta(null, $postId));
    }

    public function test_guest_401_response_body_matches_core_shape(): void
    {
        $board = $this->createBoard('basic');
        $this->declarePermission($board);
        $postId = $this->createPost($board);

        try {
            app(PostVisibilityGuard::class)->assertViewable($this->requestAs(null), $postId);
            $this->fail('HttpResponseException expected');
        } catch (HttpResponseException $e) {
            $data = $e->getResponse()->getData(true);
            $this->assertSame(401, $e->getResponse()->getStatusCode());
            $this->assertFalse($data['success']);
            $this->assertArrayHasKey('message', $data);
        }
    }

    public function test_secret_post_stays_403_for_non_author_on_readable_board(): void
    {
        $board = $this->createBoard('forum');
        $this->grant('guest', $board);
        $this->grant('user', $board);
        $this->grant('operator', $board);
        $postId = $this->createPost($board, ['is_secret' => true]);

        $this->assertSame(403, $this->meta(null, $postId));
        // posts.read-secret 보유자도 기존처럼 작성자가 아니면 403 (1.1.1 범위 밖)
        $this->grant('operator', $board, 'posts.read-secret');
        $this->assertSame(403, $this->meta($this->createUserWithRole('operator'), $postId));
    }

    public function test_secret_post_author_with_read_permission_gets_200(): void
    {
        $board = $this->createBoard('basic');
        $this->grant('user', $board);
        $author = $this->createUserWithRole('user');
        $postId = $this->createPost($board, ['is_secret' => true, 'user_id' => $author->id]);

        $this->assertSame(200, $this->meta($author, $postId));
    }

    public function test_missing_post_is_404(): void
    {
        $this->assertSame(404, $this->meta(null, 2147480000));
    }

    public function test_inactive_board_is_404_even_without_permission(): void
    {
        $board = $this->createBoard('forum', active: false);
        $this->declarePermission($board);
        $postId = $this->createPost($board);

        $this->assertSame(404, $this->meta(null, $postId));
    }

    // ── 가드를 공유하는 쓰기 라우트 ─────────────────────────────
    //
    // 추천 값은 1.3.0 부터 up/down 뿐이다. 값 검사가 가시성 판정보다 먼저이므로,
    // 여기서 옛 이모지 값을 보내면 403 이 아니라 422 가 되어 의도가 흐려진다.

    public function test_post_reaction_on_private_forum_board_is_403_for_member(): void
    {
        $board = $this->createBoard('forum');
        $this->declarePermission($board);
        $postId = $this->createPost($board);
        $request = $this->requestAs($this->createUserWithRole('user'), [], ['reaction' => 'up']);

        $status = $this->statusOf(fn () => app(ReactionController::class)->toggle($request, 'posts', $postId));

        $this->assertSame(403, $status);
        $this->assertSame(0, \DB::table('g7_forum_addon_reactions')->where('target_id', $postId)->count());
    }

    public function test_comment_reaction_on_private_forum_board_is_403_for_member(): void
    {
        $board = $this->createBoard('forum');
        $this->declarePermission($board);
        $postId = $this->createPost($board);
        $commentId = $this->createComment($board, $postId);
        $request = $this->requestAs($this->createUserWithRole('user'), [], ['reaction' => 'up']);

        $status = $this->statusOf(fn () => app(ReactionController::class)->toggle($request, 'comments', $commentId));

        $this->assertSame(403, $status);
    }

    public function test_accept_on_private_forum_board_is_403_for_member_without_read(): void
    {
        $board = $this->createBoard('forum');
        $this->declarePermission($board);
        $author = $this->createUserWithRole('user');
        $postId = $this->createPost($board, ['user_id' => $author->id]);
        $commentId = $this->createComment($board, $postId);
        $request = $this->requestAs($author, [], ['_' => '1']);

        $status = $this->statusOf(fn () => app(AcceptedReplyController::class)->accept($request, $postId, $commentId));

        $this->assertSame(403, $status);
    }

    // ── list-meta ───────────────────────────────────────────

    public function test_list_meta_private_forum_board_guest_401(): void
    {
        $board = $this->createBoard('forum');
        $this->declarePermission($board);
        $postId = $this->createPost($board);

        $this->assertSame(401, $this->listMeta(null, $board->slug, (string) $postId));
    }

    public function test_list_meta_private_forum_board_member_403(): void
    {
        $board = $this->createBoard('forum');
        $this->declarePermission($board);
        $postId = $this->createPost($board);

        $this->assertSame(403, $this->listMeta($this->createUserWithRole('user'), $board->slug, (string) $postId));
    }

    public function test_list_meta_forum_board_member_with_read_200(): void
    {
        $board = $this->createBoard('forum');
        $this->grant('operator', $board);
        $postId = $this->createPost($board);

        $this->assertSame(200, $this->listMeta($this->createUserWithRole('operator'), $board->slug, (string) $postId));
    }

    public function test_list_meta_non_forum_board_stays_404(): void
    {
        $board = $this->createBoard('basic');
        $this->declarePermission($board);

        $this->assertSame(404, $this->listMeta(null, $board->slug, '1'));
    }
}
