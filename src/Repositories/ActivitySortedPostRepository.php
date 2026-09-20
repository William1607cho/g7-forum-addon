<?php

namespace Plugins\G7\Forum\Addon\Repositories;

use Closure;
use Illuminate\Contracts\Pagination\Paginator as PaginatorContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Repositories\PostRepository;
use Plugins\G7\Forum\Addon\Plugin;
use Plugins\G7\Forum\Addon\Support\ActivityTime;

/**
 * 포럼 유형 게시판의 목록을 **최근 활동순으로 강제 정렬**하는 게시글 Repository.
 *
 * `ForumAddonServiceProvider` 가 `PostRepositoryInterface` 를 이 클래스로 재바인딩한다
 * (플러그인 프로바이더는 모듈 프로바이더 뒤에 등록되므로 이 바인딩이 이긴다).
 *
 * ── 왜 상속인가 ─────────────────────────────────────────────────
 * sirsoft-board 의 목록 조회 경로에는 정렬에 개입할 훅이 하나도 없고, 허용 정렬 컬럼은
 * `PostRepository::buildSortedPostList()` 안에 하드코딩돼 있다. 그 메서드는 `private` 이라
 * 상속으로 재사용할 수 없다.
 *
 * 그렇다고 `paginate()` 를 통째로 베끼면 공지 별도 쿼리·답글 트리·권한 스코프·지연조인까지
 * 200행 가까운 코어 로직의 사본이 생겨 코어가 바뀔 때마다 조용히 어긋난다. 그래서
 * **부모가 쿼리를 전부 만든 뒤의 한 지점만** 가로챈다.
 *
 *   1. `paginate()` — forum 게시판이면 "이번 호출은 활동순" 이라는 1회용 정렬 스펙을 올려두고
 *      부모 구현을 그대로 부른다. forum 이 아니면 곧장 `parent::paginate()` 로 빠진다.
 *   2. `paginateWithDeferredJoin()` — 부모의 `buildSortedPostList()` 가 원글 페이지네이션을
 *      맡기는 지점(코어 트레이트 `PaginatesWithDeferredJoin` 의 `protected` 메서드)이다.
 *      올려둔 스펙이 있으면 `$sort` 만 갈아끼우고 부모에게 넘긴다.
 *
 * 이 시점의 `$query` 에는 게시판·공지 제외·원글 한정·권한 스코프·삭제 처리·검색/분류
 * 필터가 이미 다 들어가 있다. **코어 본문을 한 줄도 복사하지 않는다.**
 *
 * ── 코어가 대신 해 주는 것 ──────────────────────────────────────
 * - **동률 처리**: 정렬 스펙에 키 컬럼이 없으면 코어가 마지막 스펙과 같은 방향으로 `id` 를
 *   덧붙인다. 우리가 `desc` 하나만 주므로 결과는 `ORDER BY (활동시각) DESC, id DESC` 다.
 * - **페이지 경계**: 코어가 inner(ID 추출)와 outer(행 조회)에 같은 스펙을 적용해
 *   두 쿼리의 순서가 어긋나지 않는다.
 * - **공지**: 공지는 이 지점을 타지 않는 별도 쿼리(`created_at desc`, 1페이지 한정)로
 *   조회돼 목록 맨 앞에 붙는다. 활동순 정렬은 공지 블록을 건드리지 않는다.
 *
 * ── 적용 범위 ───────────────────────────────────────────────────
 * - forum 유형 **게시판만**. 그 외 유형은 부모 구현을 그대로 탄다(동작 변화 없음).
 * - forum 게시판에서는 요청의 `sort_by`/`sort_order` 와 **게시판 설정 정렬값을 모두 무시**한다.
 *   포럼에서 "최근 활동"은 정렬 선택지가 아니라 화면의 전제이기 때문이다.
 * - 같은 목록 API 를 쓰는 봇 SSR 도 같은 순서를 받는다.
 * - 관리자 게시글 목록도 같은 `paginate()` 를 쓰므로 forum 게시판에서는 함께 활동순이 된다.
 *
 * ── 알려진 한계 ─────────────────────────────────────────────────
 * 정렬 키가 상관 서브쿼리라 페이지 ID 를 뽑는 쿼리가 그 게시판 원글 전체에 대해
 * 서브쿼리를 평가한 뒤 정렬한다. 글이 수천 건 규모가 되면 실체화한 컬럼이 필요하다
 * (그것은 코어 수정 없이는 할 수 없다).
 */
class ActivitySortedPostRepository extends PostRepository
{
    /**
     * 다음 `paginateWithDeferredJoin()` 호출 한 번에만 적용할 정렬 스펙.
     *
     * `paginateWithDeferredJoin()` 은 이 클래스가 가로채는 목록 경로 말고도
     * 부모의 다른 메서드에서 쓰인다. 1회용으로 두고 소비 즉시 비워
     * 그 경로들에는 닿지 않게 한다.
     *
     * @var array<int, array{column: mixed, direction: string}>|null
     */
    private ?array $pendingActivitySort = null;

    /**
     * 게시판의 게시글 목록을 페이지네이션하여 조회합니다.
     *
     * forum 유형이면 활동순 정렬 스펙을 걸고, 아니면 부모 구현을 그대로 돌려준다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  array  $filters  필터 조건
     * @param  int  $perPage  페이지당 항목 수
     * @param  bool  $withTrashed  삭제된 게시글 포함 여부
     * @param  Board|null  $board  게시판 모델 (이미 조회된 경우)
     */
    public function paginate(string $slug, array $filters = [], int $perPage = 15, bool $withTrashed = false, ?Board $board = null): PaginatorContract
    {
        if (! $this->isForumBoard($slug, $board)) {
            return parent::paginate($slug, $filters, $perPage, $withTrashed, $board);
        }

        $this->pendingActivitySort = [[
            'column' => ActivityTime::orderColumn(),
            'direction' => 'desc',
        ]];

        try {
            return parent::paginate($slug, $filters, $perPage, $withTrashed, $board);
        } finally {
            // 부모가 예외로 빠져나가도 스펙이 남아 다음 호출을 오염시키지 않게 한다.
            $this->pendingActivitySort = null;
        }
    }

    /**
     * 지연 조인 페이지네이션 — 활동순 스펙이 걸려 있으면 정렬만 갈아끼운다.
     *
     * 부모 `buildSortedPostList()` 가 **이름 있는 인자**로 호출하므로 매개변수 이름·순서·
     * 기본값을 코어 트레이트와 동일하게 유지한다.
     */
    protected function paginateWithDeferredJoin(
        Builder|Relation $query,
        array $columns,
        array $sort,
        int $perPage,
        ?int $page = null,
        array $relations = [],
        array $withCount = [],
        string $keyName = 'id',
        ?int $total = null,
        bool $simple = false,
        bool $preserveIdOrder = false,
        string $pageName = 'page',
        ?Closure $outerUsing = null,
        ?int $resultCap = null,
    ): PaginatorContract {
        if ($this->pendingActivitySort !== null) {
            $sort = $this->pendingActivitySort;
            $this->pendingActivitySort = null;
        }

        return parent::paginateWithDeferredJoin(
            query: $query,
            columns: $columns,
            sort: $sort,
            perPage: $perPage,
            page: $page,
            relations: $relations,
            withCount: $withCount,
            keyName: $keyName,
            total: $total,
            simple: $simple,
            preserveIdOrder: $preserveIdOrder,
            pageName: $pageName,
            outerUsing: $outerUsing,
            resultCap: $resultCap,
        );
    }

    /**
     * 이번 조회 대상이 forum 유형 게시판인지 판정합니다.
     *
     * 실제 호출자(사용자·관리자 목록 컨트롤러)는 이미 조회한 게시판을 넘겨주므로
     * 추가 쿼리가 없다. 넘어오지 않은 하위 호환 경로에서만 `type` 한 컬럼을 읽는다
     * (모듈의 Eloquent 모델을 쓰지 않는 이 플러그인의 방침대로 쿼리 빌더로 읽는다).
     */
    private function isForumBoard(string $slug, ?Board $board): bool
    {
        $type = $board?->type ?? DB::table('boards')->where('slug', $slug)->value('type');

        return $type === Plugin::FORUM_BOARD_TYPE;
    }
}
