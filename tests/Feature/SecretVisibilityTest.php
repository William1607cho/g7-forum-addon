<?php

namespace Plugins\G7\Forum\Addon\Tests\Feature;

use Plugins\G7\Forum\Addon\Http\Controllers\ForumListMetaController;
use Plugins\G7\Forum\Addon\Http\Controllers\PostMetaController;
use Plugins\G7\Forum\Addon\Support\ForumListMetaProvider;
use Plugins\G7\Forum\Addon\Tests\PluginTestCase;

/**
 * 비밀글 가시성 판정 — 1.3.0
 *
 * 1.2.0 까지는 애드온이 "작성자 본인만" 이라는 자체 규칙을 썼다. 1.3.0 부터는 코어
 * `SecretContentGate::canView()`(SSoT)에 위임하므로, 본문을 읽을 수 있는 사람은
 * 애드온 응답도 받는다. 단일 경로(`/meta`)와 배치 경로(`list-meta`)가 같은 게이트를
 * 쓰는지도 함께 본다 — 두 경로가 갈리면 목록에서는 빠지는데 상세에서는 보이게 된다.
 */
class SecretVisibilityTest extends PluginTestCase
{
    private function meta(?\App\Models\User $user, int $postId): int
    {
        $request = $this->requestAs($user);

        return $this->statusOf(fn () => app(PostMetaController::class)->show($request, $postId));
    }

    /**
     * list-meta 응답에 그 게시글 id 가 들어 있는지.
     */
    private function listMetaHasPost(?\App\Models\User $user, string $slug, int $postId): bool
    {
        $request = $this->requestAs($user, ['post_ids' => (string) $postId]);
        $response = app(ForumListMetaController::class)->index($request, $slug);
        $payload = json_decode($response->getContent(), true);

        return isset($payload['data'][(string) $postId]);
    }

    // ── 작성자 ───────────────────────────────────────────────

    public function test_author_can_read_meta_of_own_secret_post(): void
    {
        $board = $this->createBoard('forum');
        $this->grant('user', $board);
        $author = $this->createUserWithRole('user');
        $postId = $this->createPost($board, [
            'is_secret' => true,
            'user_id' => $author->id,
            'author_name' => null,
        ]);

        $this->assertSame(200, $this->meta($author, $postId));
        $this->assertTrue($this->listMetaHasPost($author, $board->slug, $postId));
    }

    // ── read-secret 보유자 (1.3.0 에서 열린 경로) ────────────

    public function test_read_secret_holder_who_is_not_the_author_can_read_meta(): void
    {
        $board = $this->createBoard('forum');
        $this->grant('user', $board);
        $this->grant('fa-secret-reader', $board, 'posts.read');
        $this->grant('fa-secret-reader', $board, 'posts.read-secret');

        $author = $this->createUserWithRole('user');
        $reader = $this->createUserWithRole('fa-secret-reader');
        $postId = $this->createPost($board, [
            'is_secret' => true,
            'user_id' => $author->id,
            'author_name' => null,
        ]);

        $this->assertSame(200, $this->meta($reader, $postId));
    }

    public function test_board_manager_who_is_not_the_author_can_read_meta(): void
    {
        $board = $this->createBoard('forum');
        $this->grant('user', $board);
        $this->grant('fa-manager', $board, 'posts.read');
        $this->grant('fa-manager', $board, 'manager');

        $author = $this->createUserWithRole('user');
        $manager = $this->createUserWithRole('fa-manager');
        $postId = $this->createPost($board, [
            'is_secret' => true,
            'user_id' => $author->id,
            'author_name' => null,
        ]);

        $this->assertSame(200, $this->meta($manager, $postId));
    }

    // ── 권한 없는 회원 / 비회원 ──────────────────────────────

    public function test_member_without_read_secret_is_refused(): void
    {
        $board = $this->createBoard('forum');
        $this->grant('user', $board);

        $author = $this->createUserWithRole('user');
        $other = $this->createUserWithRole('user');
        $postId = $this->createPost($board, [
            'is_secret' => true,
            'user_id' => $author->id,
            'author_name' => null,
        ]);

        $this->assertSame(403, $this->meta($other, $postId));
        $this->assertFalse($this->listMetaHasPost($other, $board->slug, $postId));
    }

    public function test_guest_is_refused_on_a_secret_post(): void
    {
        $board = $this->createBoard('forum');
        $this->grant('user', $board);
        $this->grant('guest', $board);

        $postId = $this->createPost($board, ['is_secret' => true]);

        $this->assertSame(403, $this->meta(null, $postId));
        $this->assertFalse($this->listMetaHasPost(null, $board->slug, $postId));
    }

    // ── 두 경로의 판정 일치 ──────────────────────────────────

    public function test_single_and_batch_paths_agree_for_a_read_secret_holder(): void
    {
        $board = $this->createBoard('forum');
        $this->grant('user', $board);
        $this->grant('fa-secret-reader', $board, 'posts.read');
        $this->grant('fa-secret-reader', $board, 'posts.read-secret');

        $author = $this->createUserWithRole('user');
        $reader = $this->createUserWithRole('fa-secret-reader');
        $postId = $this->createPost($board, [
            'is_secret' => true,
            'user_id' => $author->id,
            'author_name' => null,
        ]);

        $this->assertSame(200, $this->meta($reader, $postId));
        $this->assertTrue($this->listMetaHasPost($reader, $board->slug, $postId));
    }

    public function test_batch_keeps_non_secret_posts_and_drops_only_the_refused_one(): void
    {
        $board = $this->createBoard('forum');
        $this->grant('user', $board);

        $author = $this->createUserWithRole('user');
        $other = $this->createUserWithRole('user');
        $openId = $this->createPost($board);
        $secretId = $this->createPost($board, [
            'is_secret' => true,
            'user_id' => $author->id,
            'author_name' => null,
        ]);

        $request = $this->requestAs($other, ['post_ids' => $openId.','.$secretId]);
        $visible = app(ForumListMetaProvider::class)
            ->filterVisiblePostIds($request, (int) $board->id, [$openId, $secretId]);

        $this->assertSame([$openId], $visible);
    }
}
