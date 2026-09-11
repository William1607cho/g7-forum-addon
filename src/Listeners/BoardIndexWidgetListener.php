<?php

namespace Plugins\G7\Forum\Addon\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;

/**
 * forum 게시판 목록(`board/index`) 화면에 "참여자" 컬럼을 추가하고, "작성일" 컬럼을
 * "최근 활동"(게시글+댓글 전체 통틀어 최신 시각)으로 표시하는 리스너.
 *
 * `core.layout_extension.after_apply` **필터 훅**에 붙는다 — `BoardShowWidgetListener`
 * 와 같은 메커니즘, 게이트만 `board/index` 로 다르다. 확장 합성이 끝난 최종 레이아웃
 * 트리를 받아 수정해 반환한다.
 *
 * ── 배경: "교체"가 아니라 "추가" ─────────────────────────────────
 * 사전 실현가능성 조사(Yellow 판정) 결과를 반영해, 기존 "작성자" 컬럼은 전혀 건드리지
 * 않고 그 옆에 "참여자" 컬럼을 새로 추가한다. "작성일" 헤더/값만 forum 게시판에 한해
 * "최근 활동"으로 바뀐다.
 * **정렬 기준은 이번 범위에 포함하지 않는다** — sirsoft-board
 * 의 `PostRepository::buildSortedPostList()` 에 정렬 기준을 여는 필터 훅이 전혀 없어
 * (하드코딩 화이트리스트), 페이지네이션까지 정확한 서버 정렬을 이 플러그인만으로
 * 개입시킬 방법이 없기 때문 — 클라이언트 재정렬은 페이지 경계에서 부정확해지므로
 * 채택하지 않았다.
 *
 * ── board_type 분기 구조가 board/show 와 다르다 ──────────────────
 * `board/show` 는 유형별 렌더러가 **한 파일 안에 인라인**되어 있었지만, `board/index` 는
 * 유형별로 **다른 파셜 파일**(`types/basic|gallery|card/index.json`)을 조건부 include
 * 한다. forum 게시판은 별도 파셜이 없어 `types/basic/index.json` 을 basic 게시판과
 * 100% 동일하게 쓴다(`_type_renderer.json` 의 `!['gallery','card'].includes(type)`
 * fallback). 그래도 build 시점에 파셜이 전부 인라인되므로 이 리스너가 받는 최종 트리에는
 * 3벌이 다 들어있고 런타임 `if` 로 갈리는 것은 board/show 와 결과적으로 동일하다 —
 * gallery/card 파셜에는 `$t:board.author`/`$t:board.created_at` 텍스트가 아예 없어
 * (확인 완료) 아래 구조 매칭이 그쪽까지 잘못 번질 위험은 없다.
 *
 * ── 데이터 소스 체이닝 ───────────────────────────────────────────
 * 목록(`posts`) 데이터소스가 이미 로드된 뒤에야 "이 페이지에 보이는 게시글 ID" 를 알 수
 * 있다. 이 엔진은 한 데이터소스의 params/endpoint 가 "다른 데이터소스의 이미 로드된
 * 결과"를 직접 참조하는 기능이 없어(route/query/_global/_local 만 가능, 코드 확인
 * 완료), `posts` 의 `onSuccess` 에서 `refetchDataSource` + `localStateOverride` 로
 * 새 데이터소스(`forum_list_meta`)를 즉시 트리거하는 체이닝을 쓴다 — 이 조합은 이
 * 코드베이스에서 이번이 첫 사용이다(실브라우저로 반드시 검증).
 */
class BoardIndexWidgetListener implements HookListenerInterface
{
    /** 참여자 헤더 노드의 안정 식별자 (멱등 방어) */
    private const PARTICIPANTS_HEADER_ID = 'g7_forum_addon_list_participants_header';

    /** 참여자 셀(데스크톱 행) 노드의 안정 식별자 */
    private const PARTICIPANTS_CELL_ID = 'g7_forum_addon_list_participants_cell';

    /** 참여자 스택(모바일 행) 노드의 안정 식별자 */
    private const PARTICIPANTS_MOBILE_ID = 'g7_forum_addon_list_participants_mobile';

    /** `forum_list_meta` 데이터소스 id */
    private const META_DS_ID = 'forum_list_meta';

    /** forum 전용 그리드 컬럼 폭(조회수 있음/없음) — 원본 대비 참여자 열(110px) 삽입 */
    private const GRID_FORUM_WITH_VIEWS = "grid-cols-[60px_minmax(300px,1fr)_160px_110px_160px_70px]";

    private const GRID_FORUM_NO_VIEWS = "grid-cols-[60px_minmax(300px,1fr)_160px_110px_160px]";

    public static function getSubscribedHooks(): array
    {
        return [
            'core.layout_extension.after_apply' => [
                'method' => 'injectListWidgets',
                'type' => 'filter',
                'priority' => 20,
            ],
        ];
    }

    public function handle(...$args): void {}

    /**
     * @param  array  $layout      확장 적용이 끝난 최종 레이아웃 트리
     * @param  int    $templateId
     * @return array  수정된 레이아웃 (board/index 가 아니면 원본 그대로)
     */
    public function injectListWidgets(array $layout, int $templateId = 0): array
    {
        if (($layout['layout_name'] ?? null) !== 'board/index') {
            return $layout;
        }

        if (! isset($layout['components']) || ! is_array($layout['components'])) {
            return $layout;
        }

        if (! $this->treeHasNodeId($layout['components'], self::PARTICIPANTS_HEADER_ID)) {
            $applied = 0;
            $layout['components'] = $this->transform($layout['components'], $applied);

            if ($applied === 0) {
                Log::error('[g7-forum-addon] board/index 헤더/행 앵커를 찾지 못해 참여자 컬럼을 주입하지 못했습니다. sirsoft-basic 레이아웃 구조 변경 여부 확인 필요.', [
                    'template_id' => $templateId,
                ]);
            }
        }

        $layout['data_sources'] = $this->withListMetaDataSource($layout['data_sources'] ?? []);

        return $layout;
    }

    /**
     * 트리를 재귀 순회하며 데스크톱 헤더/행, 모바일 행을 찾아 변형한다.
     *
     * @param  array<int, mixed>  $nodes
     * @param  int  $applied  (참조) 적용 횟수 — 세 지점(헤더/데스크톱행/모바일행) 전부
     *                        찾았을 때만 3 증가. 부분 실패도 감지할 수 있게 지점별로 센다.
     * @return array<int, mixed>
     */
    private function transform(array $nodes, int &$applied): array
    {
        $out = [];

        foreach ($nodes as $node) {
            if (is_array($node) && isset($node['children']) && is_array($node['children'])) {
                $node['children'] = $this->transform($node['children'], $applied);
            }

            if ($this->isDesktopHeaderRow($node)) {
                $node = $this->rewriteHeaderRow($node);
                $applied++;
            } elseif ($this->isDesktopPostRow($node)) {
                $node = $this->rewritePostRow($node);
                $applied++;
            } elseif ($this->isMobileAuthorDateRow($node)) {
                $node = $this->rewriteMobileRow($node);
                $applied++;
            }

            $out[] = $node;
        }

        return $out;
    }

    // === 노드 판별 ===================================================

    private function isBasicDiv(mixed $node): bool
    {
        return is_array($node) && ($node['type'] ?? null) === 'basic' && ($node['name'] ?? null) === 'Div';
    }

    /**
     * 데스크톱 테이블 헤더 행 판정: `hidden lg:grid` + 헤더 전용 배경색(`bg-gray-100`) +
     * 직계 자식으로 "$t:board.author"/"$t:board.created_at" 텍스트 Span 을 둘 다 가짐.
     */
    private function isDesktopHeaderRow(mixed $node): bool
    {
        if (! $this->isBasicDiv($node)) {
            return false;
        }
        $cls = $node['props']['className'] ?? '';
        if (! is_string($cls) || ! str_contains($cls, 'hidden lg:grid') || ! str_contains($cls, 'bg-gray-100')) {
            return false;
        }

        return $this->hasDirectChildWithText($node, '$t:board.author')
            && $this->hasDirectChildWithText($node, '$t:board.created_at');
    }

    /**
     * 데스크톱 테이블 본문 행(게시글 1건) 판정: `hidden lg:grid` 이지만 헤더 배경색은 없고,
     * 직계 자식으로 작성일 값 Span("{{post?.created_at_formatted ?? ''}}")을 가짐.
     */
    private function isDesktopPostRow(mixed $node): bool
    {
        if (! $this->isBasicDiv($node)) {
            return false;
        }
        $cls = $node['props']['className'] ?? '';
        if (! is_string($cls) || ! str_contains($cls, 'hidden lg:grid') || str_contains($cls, 'bg-gray-100')) {
            return false;
        }

        return $this->hasDirectChildWithText($node, "{{post?.created_at_formatted ?? ''}}");
    }

    /**
     * 모바일 카드의 "작성자, 작성일, 조회수" 하단 행 판정: `justify-between` 이면서
     * 자식 중 하나(작성일을 담은 우측 grid Div)가 작성일 값 Span 을 직계 자식으로 가짐.
     */
    private function isMobileAuthorDateRow(mixed $node): bool
    {
        if (! $this->isBasicDiv($node)) {
            return false;
        }
        $cls = $node['props']['className'] ?? '';
        if (! is_string($cls) || ! str_contains($cls, 'justify-between')) {
            return false;
        }

        foreach (($node['children'] ?? []) as $child) {
            if ($this->hasDirectChildWithText($child, "{{post?.created_at_formatted ?? ''}}")) {
                return true;
            }
        }

        return false;
    }

    /**
     * 노드의 직계 자식(children) 중에 정확히 주어진 `text` 값을 가진 노드가 있는지.
     */
    private function hasDirectChildWithText(mixed $node, string $text): bool
    {
        if (! is_array($node) || ! isset($node['children']) || ! is_array($node['children'])) {
            return false;
        }
        foreach ($node['children'] as $child) {
            if (is_array($child) && ($child['text'] ?? null) === $text) {
                return true;
            }
        }

        return false;
    }

    /**
     * 노드의 직계 자식 중 정확히 주어진 `text` 값을 가진 노드의 인덱스. 없으면 null.
     */
    private function findChildIndexByText(array $node, string $text): ?int
    {
        foreach (($node['children'] ?? []) as $i => $child) {
            if (is_array($child) && ($child['text'] ?? null) === $text) {
                return $i;
            }
        }

        return null;
    }

    /**
     * 노드의 직계 자식 중, children 안에 `Avatar` composite(작성자 아바타)을 포함하는
     * Div 의 인덱스. 데스크톱 행의 "작성자" 셀 / 모바일 행의 "작성자" 블록을 찾는다.
     */
    private function findAuthorCellIndex(array $node): ?int
    {
        foreach (($node['children'] ?? []) as $i => $child) {
            if (! is_array($child) || ! isset($child['children']) || ! is_array($child['children'])) {
                continue;
            }
            foreach ($child['children'] as $grandchild) {
                if (is_array($grandchild) && ($grandchild['type'] ?? null) === 'composite' && ($grandchild['name'] ?? null) === 'Avatar') {
                    return $i;
                }
            }
        }

        return null;
    }

    // === 그리드 컬럼 폭 표현식 ========================================

    /**
     * forum 게시판일 때만 `style.gridTemplateColumns` 인라인 스타일로 그리드 트랙을
     * 덮어쓴다. **Tailwind 클래스 문자열(`grid-cols-[...]`)을 건드리지 않는 이유**:
     * Tailwind 는 빌드 시점에 소스에서 스캔한 클래스 문자열만 CSS 로 생성한다 — 이
     * 리스너가 런타임에 조립하는 새 브래킷 값(`grid-cols-[..._110px_...]`)은 어떤
     * 소스 파일에도 리터럴로 존재한 적이 없어 대응 CSS 가 아예 생성되지 않는다(실측:
     * 첫 구현에서 className 토큰을 치환했다가 그리드가 완전히 무너짐 —
     * `getComputedStyle().gridTemplateColumns` 가 단일 트랙으로 붕괴 확인). 인라인
     * `style` 은 빌드 시점 퍼지 대상이 아니라 런타임 값을 그대로 반영하고, 클래스보다
     * 우선순위도 높아 원본 Tailwind 클래스를 그대로 둔 채 forum 일 때만 덮어쓸 수 있다.
     * non-forum 이면 빈 문자열을 반환해(React 인라인 스타일 관례상 미설정과 동일)
     * 원본 클래스의 grid-cols 가 그대로 적용되게 한다.
     */
    private function gridTemplateColumnsExpression(): string
    {
        // grid-cols-[60px_minmax(300px,1fr)_160px_110px_160px_70px] → 60px minmax(300px,1fr) 160px 110px 160px 70px
        $with = str_replace('_', ' ', substr(self::GRID_FORUM_WITH_VIEWS, strlen('grid-cols-['), -1));
        $noViews = str_replace('_', ' ', substr(self::GRID_FORUM_NO_VIEWS, strlen('grid-cols-['), -1));

        return "{{posts?.data?.board?.type === 'forum'"
            ." ? (posts?.data?.board?.settings?.show_view_count !== false ? '{$with}' : '{$noViews}')"
            .' : \'\'}}';
    }

    /**
     * 헤더/행 컨테이너 노드에 forum 전용 `style.gridTemplateColumns` 를 추가한다.
     * 기존 `props.style` 이 있으면 병합(지금 시점엔 헤더/행 컨테이너에 없음을 확인했지만,
     * 향후 sirsoft-basic 이 추가하더라도 통째로 지우지 않도록 방어).
     */
    private function withForumGridTemplateColumns(array $node): array
    {
        $node['props']['style'] = array_merge(
            $node['props']['style'] ?? [],
            ['gridTemplateColumns' => $this->gridTemplateColumnsExpression()]
        );

        return $node;
    }

    // === 헤더 행 변형 ================================================

    private function rewriteHeaderRow(array $node): array
    {
        $node = $this->withForumGridTemplateColumns($node);

        // "작성일" 헤더 텍스트 → forum 이면 "최근 활동", 아니면 원본 그대로.
        $dateIdx = $this->findChildIndexByText($node, '$t:board.created_at');
        if ($dateIdx !== null) {
            $node['children'][$dateIdx]['text'] =
                "{{posts?.data?.board?.type === 'forum' ? \$t('g7-forum-addon.list_last_activity_header') : \$t('board.created_at')}}";
        }

        // "작성자" 헤더 바로 뒤에 "참여자" 헤더 삽입 (forum 전용).
        $authorIdx = $this->findChildIndexByText($node, '$t:board.author');
        if ($authorIdx !== null) {
            $node['children'] = $this->insertAfter($node['children'], $authorIdx, [
                'id' => self::PARTICIPANTS_HEADER_ID,
                'type' => 'basic',
                'name' => 'Span',
                'if' => "{{posts?.data?.board?.type === 'forum'}}",
                'text' => "{{\$t('g7-forum-addon.list_participants_header')}}",
            ]);
        }

        return $node;
    }

    // === 데스크톱 본문 행 변형 ========================================

    private function rewritePostRow(array $node): array
    {
        $node = $this->withForumGridTemplateColumns($node);

        // "작성일" 값 → forum_list_meta 조회 결과가 있으면 그 값, 없으면(로딩 전/비-forum)
        // 원래 게시글 작성 시각 그대로 — 로딩 지연 중에도 빈 칸이 아니라 자연스러운 값을 보인다.
        $dateIdx = $this->findChildIndexByText($node, "{{post?.created_at_formatted ?? ''}}");
        if ($dateIdx !== null) {
            $node['children'][$dateIdx]['text'] =
                "{{forum_list_meta?.data?.[post?.id]?.last_activity_at_formatted ?? (post?.created_at_formatted ?? '')}}";
            $node['children'][$dateIdx]['props']['title'] =
                "{{forum_list_meta?.data?.[post?.id]?.last_activity_at ?? (post?.created_at ?? '')}}";
        }

        // "작성자" 셀 바로 뒤에 "참여자" 셀 삽입 (forum 전용, 최대 5개 겹침 아바타).
        $authorIdx = $this->findAuthorCellIndex($node);
        if ($authorIdx !== null) {
            $node['children'] = $this->insertAfter($node['children'], $authorIdx, $this->participantsCellNode(self::PARTICIPANTS_CELL_ID));
        }

        return $node;
    }

    // === 모바일 행 변형 ===============================================

    private function rewriteMobileRow(array $node): array
    {
        // 참여자 스택은 작성자 블록(첫 자식) 바로 뒤에 삽입.
        $authorIdx = $this->findAuthorCellIndex($node);
        if ($authorIdx !== null) {
            $node['children'] = $this->insertAfter($node['children'], $authorIdx, $this->participantsCellNode(self::PARTICIPANTS_MOBILE_ID, mobile: true));
        }

        // 작성일 값은 "우측 grid" 서브 Div 안에 있다 — 그 서브 Div 를 찾아 텍스트만 교체.
        foreach ($node['children'] ?? [] as $i => $child) {
            $dateIdx = is_array($child) ? $this->findChildIndexByText($child, "{{post?.created_at_formatted ?? ''}}") : null;
            if ($dateIdx === null) {
                continue;
            }
            $node['children'][$i]['children'][$dateIdx]['text'] =
                "{{forum_list_meta?.data?.[post?.id]?.last_activity_at_formatted ?? (post?.created_at_formatted ?? '')}}";
            break;
        }

        return $node;
    }

    /**
     * 참여자 아바타 스택 노드(최대 5개, 겹침 표시). 데스크톱/모바일 공용 — 모바일은 아이콘을
     * 조금 더 작게(각 아바타 자체 크기는 Avatar composite 의 size prop 로 통일, xs).
     *
     * @param  string  $id  안정 식별자
     * @param  bool  $mobile  모바일 배치용 마진 보정 여부(현재는 동일 스타일 — 자리만 마련)
     */
    private function participantsCellNode(string $id, bool $mobile = false): array
    {
        return [
            'id' => $id,
            'type' => 'basic',
            'name' => 'Div',
            'if' => "{{posts?.data?.board?.type === 'forum'}}",
            'props' => [
                'className' => 'flex -space-x-2',
            ],
            'children' => [
                [
                    'type' => 'basic',
                    'name' => 'Div',
                    'iteration' => [
                        'source' => "{{forum_list_meta?.data?.[post?.id]?.participants ?? []}}",
                        'item_var' => 'participant',
                    ],
                    'props' => [
                        'className' => 'ring-2 ring-white dark:ring-gray-800 rounded-full',
                    ],
                    'children' => [
                        [
                            'type' => 'composite',
                            'name' => 'Avatar',
                            'props' => [
                                'author' => '{{participant}}',
                                'size' => 'xs',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * 주어진 인덱스 바로 뒤에 노드를 삽입한 새 배열을 반환한다.
     *
     * @param  array<int, mixed>  $children
     */
    private function insertAfter(array $children, int $index, array $newNode): array
    {
        $before = array_slice($children, 0, $index + 1);
        $after = array_slice($children, $index + 1);

        return [...$before, $newNode, ...$after];
    }

    /**
     * `posts` 데이터소스에 `onSuccess`(체이닝 트리거)를 달고, `forum_list_meta` 데이터소스가
     * 없으면 추가한다. 멱등 — 이미 있으면 그대로 둔다.
     *
     * @param  array<int, mixed>  $dataSources
     * @return array<int, mixed>
     */
    private function withListMetaDataSource(array $dataSources): array
    {
        $hasMeta = false;
        foreach ($dataSources as $ds) {
            if (is_array($ds) && ($ds['id'] ?? null) === self::META_DS_ID) {
                $hasMeta = true;
                break;
            }
        }

        $dataSources = array_map(function ($ds) {
            if (! is_array($ds) || ($ds['id'] ?? null) !== 'posts') {
                return $ds;
            }
            if (isset($ds['onSuccess'])) {
                return $ds; // 이미 있음(멱등) — 다른 확장이 먼저 달았을 가능성 등, 덮어쓰지 않음.
            }

            // `refetchDataSource` 의 `localStateOverride` 파라미터는 문서와 달리 이
            // 엔진 빌드의 실제 구현(ActionDispatcher.ts registerDefaultHandlers)에서는
            // 액션이 선언한 값을 쓰지 않고 **호출 시점의 기존 `_local` 상태를 그대로
            // 재전송**한다(실측 확인 — 네트워크 요청에 파라미터가 전혀 안 붙어 발견).
            // 그래서 "setState(local) 로 먼저 실제로 써넣은 뒤 refetchDataSource" 하는
            // sequence 로 우회한다 — 코드 주석이 명시한 지원 경로다("sequence 내에서
            // setState local 후 refetchDataSource 호출 시 params에서 {{_local.xxx}} 참조 지원").
            $ds['onSuccess'] = [
                'handler' => 'conditions',
                'conditions' => [
                    [
                        'if' => "{{response.data.data.board?.type === 'forum'}}",
                        'then' => [
                            'handler' => 'sequence',
                            'actions' => [
                                [
                                    'handler' => 'setState',
                                    'params' => [
                                        'target' => 'local',
                                        'g7_forum_addon_list_post_ids' => "{{(response.data.data.data ?? []).map(p => p.id).join(',')}}",
                                    ],
                                ],
                                [
                                    'handler' => 'refetchDataSource',
                                    'params' => [
                                        'dataSourceId' => self::META_DS_ID,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ];

            return $ds;
        }, $dataSources);

        if (! $hasMeta) {
            $dataSources[] = [
                'id' => self::META_DS_ID,
                'type' => 'api',
                'endpoint' => '/api/plugins/g7-forum-addon/boards/{{route.slug}}/list-meta',
                'method' => 'GET',
                'params' => [
                    'post_ids' => '{{_local.g7_forum_addon_list_post_ids ?? \'\'}}',
                ],
                'auto_fetch' => false,
                'auth_mode' => 'optional',
                'fallback' => ['data' => (object) []],
            ];
        }

        return $dataSources;
    }

    /**
     * 트리 어딘가에 주어진 id 를 가진 노드가 이미 있는지 (멱등 방어).
     *
     * @param  array<int, mixed>  $nodes
     */
    private function treeHasNodeId(array $nodes, string $id): bool
    {
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            if (($node['id'] ?? null) === $id) {
                return true;
            }
            if (isset($node['children']) && is_array($node['children']) && $this->treeHasNodeId($node['children'], $id)) {
                return true;
            }
        }

        return false;
    }
}
