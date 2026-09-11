<?php

namespace Plugins\G7\Forum\Addon\Http\Controllers;

use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Plugins\G7\Forum\Addon\Support\ForumListMetaProvider;

/**
 * 게시판 목록 화면의 "참여자"/"최근 활동" 배치 조회 엔드포인트.
 *
 * `GET /api/plugins/g7-forum-addon/boards/{slug}/list-meta?post_ids=1,2,3,...`
 *
 * 목록 화면(`board/index`)이 게시글 목록을 받은 직후, 그 페이지에 실제로 보이는 게시글
 * ID 들을 모아 한 번에 호출한다(프론트 배선은 `BoardIndexWidgetListener` 참고). 응답은
 * 게시글 ID(문자열 키) → `{participants, last_activity_at, last_activity_at_formatted}` 맵이다.
 *
 * forum 유형 게시판에만 의미가 있으므로 그 외 유형은 404 로 게이팅한다. 게시글별
 * 가시성(비밀글/블라인드/삭제)은 `ForumListMetaProvider::filterVisiblePostIds()` 로
 * 배치용으로 재검증한다 — 단일 게시글 엔드포인트(`/meta`)처럼 통째로 중단하지 않고
 * 통과 못한 ID 만 결과에서 조용히 빠진다.
 */
class ForumListMetaController extends PublicBaseController
{
    public function __construct(
        private readonly ForumListMetaProvider $provider,
    ) {
        parent::__construct();
    }

    /**
     * @param  string  $slug  boards.slug
     */
    public function index(Request $request, string $slug): JsonResponse
    {
        $board = DB::table('boards')->where('slug', $slug)->first();

        if ($board === null || (int) $board->is_active !== 1 || $board->type !== 'forum') {
            return $this->notFound();
        }

        $requestedIds = $this->provider->parsePostIds($request->query('post_ids'));

        if ($requestedIds === []) {
            return $this->success('common.success', (object) []);
        }

        $viewerId = Auth::id();
        $visibleIds = $this->provider->filterVisiblePostIds((int) $board->id, $requestedIds, $viewerId);

        if ($visibleIds === []) {
            return $this->success('common.success', (object) []);
        }

        $dateFormat = g7_module_settings('sirsoft-board', 'display.date_display_format', 'standard');
        $result = $this->provider->batch((int) $board->id, $visibleIds, $dateFormat);

        // JSON 응답에서 정수 키 맵이 배열로 오인되지 않도록(빈 배치가 아니면 항상 객체) 문자열 키로 통일.
        $keyed = [];
        foreach ($result as $postId => $meta) {
            $keyed[(string) $postId] = $meta;
        }

        return $this->success('common.success', $keyed);
    }
}
