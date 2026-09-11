<?php

namespace Plugins\G7\Forum\Addon\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;

/**
 * 포럼 위젯을 게시글 상세(`board/show`) 레이아웃에 주입하는 리스너.
 *
 * `core.layout_extension.after_apply` **필터 훅**에 붙는다 — extension_point 합성과
 * overlay 처리가 **모두 끝난 최종 레이아웃 트리**를 받아 수정해 반환한다. 따라서
 * `html_content` 확장점에 `mode: replace` 를 거는 다른 플러그인(sirsoft-ckeditor5)의
 * priority 전쟁과 **완전히 무관**하다. 어떤 확장도 이 시점 이후를 덮어쓰지 못한다.
 *
 * 뼈대 단계의 `html_content` extension_point 등록(priority 150 우회)을 대체한다.
 *
 * ── 주입 위치 ────────────────────────────────────────────────
 * `board/show` 레이아웃의 "게시글 본문 카드" 안에서, **게시글 내용 영역 다음 ·
 * 액션 버튼 줄(답변/신고/수정/삭제) 바로 앞**. 앵커는 액션 버튼 줄 컨테이너
 * (`<Div class="flex justify-end gap-2 px-6 py-4 border-t ...">`, `if` 에
 * `is_guest_post` 포함)를 형태로 찾아, 그 직전 형제로 위젯 노드를 splice 한다.
 * `board/show` 는 유형(basic/gallery/card/forum) 무관 공통 레이아웃이라 액션 줄이
 * 3벌(유형 분기 렌더러) 인라인되어 있고 — 3벌 모두에 주입하되, 위젯 노드의 런타임
 * `if` 가 `post.data.board.type === 'forum'` 일 때만 실제로 렌더한다.
 *
 * ── PHP 레벨 게이팅의 한계 ──────────────────────────────────
 * `after_apply` 가 받는 것은 정적 레이아웃 JSON 트리이지 런타임 데이터가 아니다.
 * "이 요청이 forum 게시판인가" 는 PHP 시점에 알 수 없다(레이아웃은 유형 무관). 그래서
 * board_type 게이팅은 주입 노드가 들고 다니는 `if` 표현식으로 프론트 렌더 시점에
 * 평가된다 — 뼈대 단계의 게이팅과 동등하다. PHP 레벨 게이트는 `layout_name` 뿐이다.
 */
class BoardShowWidgetListener implements HookListenerInterface
{
    /** 위젯 노드의 안정 식별자 (제거·중복 방지) */
    private const WIDGET_ID = 'g7_forum_addon_widget';

    /** 잠금 안내 노드의 안정 식별자 (댓글 입력 폼을 대체) */
    private const LOCK_NOTICE_ID = 'g7_forum_addon_lock_notice';

    /** 댓글 입력 폼 `if` 에 이미 잠금 조건이 붙었는지 표시하는 마커 */
    private const LOCK_IF_MARKER = 'forum_meta?.data?.locked';

    /** 댓글 리액션 바 노드의 안정 식별자 */
    private const COMMENT_REACTIONS_ID = 'g7_forum_addon_comment_reactions';

    /** 댓글 채택(베스트답글) UI 노드의 안정 식별자 */
    private const ACCEPTED_REPLY_ID = 'g7_forum_addon_accepted_reply';

    /** `/meta` 데이터소스 id */
    private const META_DS_ID = 'forum_meta';

    /** 리액션 종류 → 이모지 (순서 = 표시 순서, ReactionStore::REACTIONS 와 일치) */
    private const REACTION_EMOJI = [
        'like' => '👍',
        'love' => '❤️',
        'haha' => '😂',
        'wow' => '😮',
        'sad' => '😢',
    ];

    public static function getSubscribedHooks(): array
    {
        return [
            'core.layout_extension.after_apply' => [
                'method' => 'injectWidget',
                'type' => 'filter',
                'priority' => 20,
            ],
        ];
    }

    public function handle(...$args): void {}

    /**
     * @param  array  $layout      확장 적용이 끝난 최종 레이아웃 트리
     * @param  int    $templateId
     * @return array  수정된 레이아웃 (board/show 가 아니면 원본 그대로)
     */
    public function injectWidget(array $layout, int $templateId = 0): array
    {
        if (($layout['layout_name'] ?? null) !== 'board/show') {
            return $layout;
        }

        if (! isset($layout['components']) || ! is_array($layout['components'])) {
            return $layout;
        }

        // 위젯이 아직 없으면 주입한다(캐시 재적용 등에서 중복 방지 — 멱등).
        if (! $this->treeHasNodeId($layout['components'], self::WIDGET_ID)) {
            $injected = 0;
            $layout['components'] = $this->spliceBeforeActionRows($layout['components'], $injected);

            if ($injected === 0) {
                // 앵커(액션 버튼 줄)를 못 찾음 = sirsoft-basic 구조 변경 가능성.
                // LOG_LEVEL=error 라도 보이도록 error 로 남긴다 — 무경고 실패 방지.
                Log::error('[g7-forum-addon] board/show 액션 버튼 줄 앵커를 찾지 못해 포럼 위젯을 주입하지 못했습니다. sirsoft-basic 레이아웃 구조 변경 여부 확인 필요.', [
                    'template_id' => $templateId,
                ]);
            }
        }

        // 잠금 상태일 때 댓글 입력 폼을 가리고 안내 문구로 대체한다(멱등).
        // 위젯 앵커를 못 찾아도 이건 독립적으로 시도한다.
        if (! $this->treeHasNodeId($layout['components'], self::LOCK_NOTICE_ID)) {
            $lockApplied = 0;
            $layout['components'] = $this->applyLockCommentUi($layout['components'], $lockApplied);

            if ($lockApplied === 0) {
                Log::warning('[g7-forum-addon] board/show 댓글 입력 폼 앵커를 찾지 못해 잠금 안내 대체를 적용하지 못했습니다. API 레벨 차단은 그대로 유효합니다.', [
                    'template_id' => $templateId,
                ]);
            }
        }

        // 댓글 본문 뒤에 리액션 바 splice (멱등). 위젯/잠금과 독립.
        if (! $this->treeHasNodeId($layout['components'], self::COMMENT_REACTIONS_ID)) {
            $reactApplied = 0;
            $layout['components'] = $this->applyCommentReactions($layout['components'], $reactApplied);

            if ($reactApplied === 0) {
                Log::warning('[g7-forum-addon] board/show 댓글 본문 앵커를 찾지 못해 댓글 리액션 바를 주입하지 못했습니다. 게시글 리액션·API 는 그대로 동작합니다.', [
                    'template_id' => $templateId,
                ]);
            }
        }

        // 댓글 본문 뒤에 채택(베스트답글) 배지/버튼 splice (멱등). 리액션 바보다 위에 온다.
        if (! $this->treeHasNodeId($layout['components'], self::ACCEPTED_REPLY_ID)) {
            $acceptApplied = 0;
            $layout['components'] = $this->applyAcceptedReplyUi($layout['components'], $acceptApplied);

            if ($acceptApplied === 0) {
                Log::warning('[g7-forum-addon] board/show 댓글 본문 앵커를 찾지 못해 채택 UI 를 주입하지 못했습니다. 채택 API 는 그대로 동작합니다.', [
                    'template_id' => $templateId,
                ]);
            }
        }

        // `/meta` 2-call 데이터소스 주입 (레이아웃당 1회). 위젯/잠금/리액션 UI 가 쓴다.
        $layout['data_sources'] = $this->withMetaDataSource($layout['data_sources'] ?? []);

        return $layout;
    }

    /**
     * 컴포넌트 배열을 재귀 순회하며, 액션 버튼 줄 노드 직전에 위젯 노드를 splice.
     *
     * @param  array<int, mixed>  $nodes
     * @param  int  $injected  (참조) 주입 횟수
     * @return array<int, mixed>
     */
    private function spliceBeforeActionRows(array $nodes, int &$injected): array
    {
        $out = [];

        foreach ($nodes as $node) {
            if (is_array($node) && isset($node['children']) && is_array($node['children'])) {
                $node['children'] = $this->spliceBeforeActionRows($node['children'], $injected);
            }

            if ($this->isActionButtonRow($node)) {
                $out[] = $this->widgetNode();
                $injected++;
            }

            $out[] = $node;
        }

        return $out;
    }

    /**
     * 노드가 게시글 액션 버튼 줄 컨테이너인지 형태로 판정.
     *
     * 앵커 신호(모두 만족): basic Div + className 에 flex/justify-end/border-t/px-6/py-4
     * + `if` 에 `is_guest_post` 포함(sirsoft-basic 의 그 줄 고유 게이트).
     */
    private function isActionButtonRow(mixed $node): bool
    {
        if (! is_array($node)) {
            return false;
        }
        if (($node['type'] ?? null) !== 'basic' || ($node['name'] ?? null) !== 'Div') {
            return false;
        }

        $cls = $node['props']['className'] ?? '';
        if (! is_string($cls)) {
            return false;
        }
        foreach (['flex', 'justify-end', 'border-t', 'px-6', 'py-4'] as $token) {
            if (! str_contains($cls, $token)) {
                return false;
            }
        }

        $if = $node['if'] ?? '';

        return is_string($if) && str_contains($if, 'is_guest_post');
    }

    /**
     * 주입할 위젯 노드.
     *
     * 아직 대부분 자리표시자 텍스트지만, 고정(공지) 배지는 실제 데이터로 렌더한다 —
     * `forum_meta.data.is_notice`(원천: sirsoft-board `board_posts.is_notice`)가 참이면
     * "📌 고정됨" 배지를 앞에 붙인다. board_type 게이팅은 위젯 노드 전체 `if` 로 유지.
     *
     * @return array<string, mixed>
     */
    private function widgetNode(): array
    {
        return [
            'id' => self::WIDGET_ID,
            'type' => 'basic',
            'name' => 'Div',
            'if' => "{{post?.data?.board?.type === 'forum' && post?.data?.status !== 'blinded' && post?.data?.content !== null}}",
            'props' => [
                'className' => 'mx-6 mt-2 mb-2 flex flex-wrap items-center gap-2 rounded-lg border border-dashed border-blue-300 dark:border-blue-700 bg-blue-50/50 dark:bg-blue-900/10 px-4 py-3 text-sm text-blue-700 dark:text-blue-300',
            ],
            'children' => [
                [
                    // 고정(공지) 배지 — forum_meta.data.is_notice 가 참일 때만.
                    'type' => 'basic',
                    'name' => 'Span',
                    'if' => '{{forum_meta?.data?.is_notice}}',
                    'props' => [
                        'className' => 'inline-flex items-center rounded-full bg-blue-600 px-2 py-0.5 text-xs font-semibold text-white dark:bg-blue-500',
                    ],
                    'text' => '$t:g7-forum-addon.pinned_badge',
                ],
                [
                    // 잠금 배지 — forum_meta.data.locked 가 참일 때만.
                    'type' => 'basic',
                    'name' => 'Span',
                    'if' => '{{forum_meta?.data?.locked}}',
                    'props' => [
                        'className' => 'inline-flex items-center rounded-full bg-gray-600 px-2 py-0.5 text-xs font-semibold text-white dark:bg-gray-500',
                    ],
                    'text' => '$t:g7-forum-addon.locked_badge',
                ],
                [
                    // 채택 답변 요약 배지 — forum_meta.data.accepted_reply_id 가 있을 때만.
                    'type' => 'basic',
                    'name' => 'Span',
                    'if' => '{{forum_meta?.data?.accepted_reply_id}}',
                    'props' => [
                        'className' => 'inline-flex items-center rounded-full bg-green-600 px-2 py-0.5 text-xs font-semibold text-white dark:bg-green-500',
                    ],
                    'text' => '$t:g7-forum-addon.widget_has_accepted',
                ],
                [
                    'type' => 'basic',
                    'name' => 'Span',
                    'props' => ['className' => 'font-medium'],
                    'text' => '$t:g7-forum-addon.widget_placeholder',
                ],
                // 관리자 전용 잠금/해제 토글 — 사이트 관리자에게만 노출.
                // API 인가는 서버(AdminBaseController: auth:sanctum + admin)가 최종 판정.
                $this->lockToggleButton(false),
                $this->lockToggleButton(true),
                // 게시글 리액션 바 (5종 이모지, 로그인 사용자 클릭 시 토글).
                $this->reactionBar('post'),
            ],
        ];
    }

    /**
     * 리액션 바 컨테이너 — 5종 이모지 버튼.
     *
     * @param  string  $scope  'post' | 'comment'
     * @return array<string, mixed>
     */
    private function reactionBar(string $scope): array
    {
        $buttons = [];
        foreach (array_keys(self::REACTION_EMOJI) as $reaction) {
            $buttons[] = $this->reactionButton($scope, $reaction);
        }

        return [
            'type' => 'basic',
            'name' => 'Div',
            'props' => [
                // post: 위젯 안에서 한 줄 차지(w-full). comment: 댓글 본문 아래 인라인.
                'className' => $scope === 'post'
                    ? 'w-full mt-1 flex flex-wrap items-center gap-1.5'
                    : 'mt-2 flex flex-wrap items-center gap-1.5',
            ],
            'children' => $buttons,
        ];
    }

    /**
     * 리액션 버튼 1개.
     *
     * @param  string  $scope     'post' | 'comment'
     * @param  string  $reaction  like|love|haha|wow|sad
     * @return array<string, mixed>
     */
    private function reactionButton(string $scope, string $reaction): array
    {
        $emoji = self::REACTION_EMOJI[$reaction];

        if ($scope === 'post') {
            $countExpr = "forum_meta?.data?.reactions?.counts?.".$reaction;
            $mineExpr = 'forum_meta?.data?.reactions?.mine';
            $target = '/api/plugins/g7-forum-addon/posts/{{route?.id}}/reactions';
        } else {
            $base = "forum_meta?.data?.comment_reactions?.[comment?.id]";
            $countExpr = $base.'?.counts?.'.$reaction;
            $mineExpr = $base.'?.mine';
            $target = '/api/plugins/g7-forum-addon/comments/{{comment?.id}}/reactions';
        }

        $activeCls = "border-blue-500 bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-200 dark:border-blue-500";
        $idleCls = "border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700";
        $className = "inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs leading-none transition-colors cursor-pointer "
            ."{{ (".$mineExpr.") === '".$reaction."' ? '".$activeCls."' : '".$idleCls."' }}";

        return [
            'type' => 'basic',
            'name' => 'Button',
            'props' => [
                'type' => 'button',
                'className' => $className,
            ],
            'children' => [
                ['type' => 'basic', 'name' => 'Span', 'text' => $emoji],
                [
                    'type' => 'basic',
                    'name' => 'Span',
                    'if' => '{{('.$countExpr.' ?? 0) > 0}}',
                    'props' => ['className' => 'font-medium'],
                    'text' => '{{'.$countExpr.' ?? 0}}',
                ],
            ],
            'actions' => [
                [
                    'type' => 'click',
                    'handler' => 'apiCall',
                    'auth_required' => true,
                    'target' => $target,
                    'params' => ['method' => 'POST', 'body' => ['reaction' => $reaction]],
                    'onSuccess' => [
                        ['handler' => 'refetchDataSource', 'params' => ['dataSourceId' => self::META_DS_ID]],
                    ],
                    'onError' => [
                        ['handler' => 'toast', 'params' => ['type' => 'error', 'message' => '{{error.message}}']],
                    ],
                ],
            ],
        ];
    }

    /**
     * 위젯 안의 관리자용 잠금/해제 버튼 1개.
     *
     * @param  bool  $forUnlock  true=해제 버튼(잠겼을 때 표시), false=잠그기 버튼(안 잠겼을 때 표시)
     * @return array<string, mixed>
     */
    private function lockToggleButton(bool $forUnlock): array
    {
        $visibleWhen = $forUnlock ? 'forum_meta?.data?.locked' : '!forum_meta?.data?.locked';
        $endpoint = $forUnlock ? 'unlock' : 'lock';
        $label = $forUnlock ? '$t:g7-forum-addon.unlock_button' : '$t:g7-forum-addon.lock_button';
        $btnClass = 'ml-auto inline-flex items-center rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-2.5 py-1 text-xs font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 cursor-pointer';

        return [
            'type' => 'basic',
            'name' => 'Button',
            'if' => '{{_global.currentUser?.is_admin && '.$visibleWhen.'}}',
            'props' => [
                'type' => 'button',
                'className' => $btnClass,
            ],
            'text' => $label,
            'actions' => [
                [
                    'type' => 'click',
                    'handler' => 'apiCall',
                    'auth_required' => true,
                    'target' => '/api/plugins/g7-forum-addon/posts/{{route?.id}}/'.$endpoint,
                    'params' => ['method' => 'POST'],
                    'onSuccess' => [
                        [
                            'handler' => 'toast',
                            'params' => ['type' => 'success', 'message' => '{{response?.message}}'],
                        ],
                        [
                            'handler' => 'refetchDataSource',
                            'params' => ['dataSourceId' => self::META_DS_ID],
                        ],
                    ],
                    'onError' => [
                        [
                            'handler' => 'toast',
                            'params' => ['type' => 'error', 'message' => '{{error.message}}'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * `data_sources` 에 `forum_meta`(`/meta` 엔드포인트) 항목이 없으면 추가.
     *
     * @param  array<int, mixed>  $dataSources
     * @return array<int, mixed>
     */
    private function withMetaDataSource(array $dataSources): array
    {
        foreach ($dataSources as $ds) {
            if (is_array($ds) && ($ds['id'] ?? null) === self::META_DS_ID) {
                return $dataSources; // 이미 있음
            }
        }

        $dataSources[] = [
            'id' => self::META_DS_ID,
            'type' => 'api',
            'endpoint' => '/api/plugins/g7-forum-addon/posts/{{route.id}}/meta',
            'method' => 'GET',
            'auto_fetch' => true,
            'auth_mode' => 'optional',
            'if' => '{{!!route.slug && !!route.id}}',
            'fallback' => [
                'data' => [
                    'reactions' => [
                        'counts' => ['like' => 0, 'love' => 0, 'haha' => 0, 'wow' => 0, 'sad' => 0],
                        'total' => 0,
                        'mine' => null,
                    ],
                    'comment_reactions' => [],
                    'tags' => [],
                    'subscribed' => false,
                    'accepted_reply_id' => null,
                    'locked' => false,
                    'is_notice' => false,
                ],
            ],
        ];

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

    /**
     * 컴포넌트 트리를 재귀 순회하며, 댓글 입력 폼 노드를 찾아:
     *   1. 폼의 `if` 에 "잠기지 않았을 때만" 조건을 덧붙인다(잠기면 폼이 사라짐).
     *   2. 폼 바로 앞에 "🔒 잠긴 게시글" 안내 노드를 splice 한다(잠겼을 때만 렌더).
     *
     * sirsoft-basic·g7-comment-editor 를 건드리지 않고 forum-addon 레이아웃 리스너에서만
     * 처리한다. `board/show` 는 유형 무관 공통 레이아웃이라 댓글 입력 폼이 유형 분기(basic/
     * gallery/card)마다 인라인돼 3벌 나올 수 있다 — 매칭되는 모든 곳에 적용하되, 조건식이
     * `post.data.board.type === 'forum'` 을 포함하므로 포럼 게시판에서만 실제로 동작한다.
     *
     * @param  array<int, mixed>  $nodes
     * @param  int  $applied  (참조) 적용 횟수
     * @return array<int, mixed>
     */
    private function applyLockCommentUi(array $nodes, int &$applied): array
    {
        $out = [];

        foreach ($nodes as $node) {
            if (is_array($node) && isset($node['children']) && is_array($node['children'])) {
                $node['children'] = $this->applyLockCommentUi($node['children'], $applied);
            }

            if ($this->isCommentInputForm($node)) {
                $out[] = $this->lockNoticeNode();

                $baseIf = is_string($node['if'] ?? null) ? trim($node['if']) : '';
                $node['if'] = $this->appendLockGuardToIf($baseIf);

                $applied++;
            }

            $out[] = $node;
        }

        return $out;
    }

    /**
     * 노드가 sirsoft-basic 의 "새 댓글 입력 폼" 컨테이너인지 형태로 판정.
     *
     * 신호(모두 만족): basic Div + className 에 `p-4`·`border-t`
     * + `if` 에 `abilities?.can_write_comments` 와 `deleted_at` 포함
     * (partials/board/show/_comment_input.json 최상위 Div 의 고유 게이트).
     * 이미 잠금 조건이 덧붙은 노드는 재적용하지 않는다(멱등).
     */
    private function isCommentInputForm(mixed $node): bool
    {
        if (! is_array($node)) {
            return false;
        }
        if (($node['type'] ?? null) !== 'basic' || ($node['name'] ?? null) !== 'Div') {
            return false;
        }

        $cls = $node['props']['className'] ?? '';
        if (! is_string($cls) || ! str_contains($cls, 'p-4') || ! str_contains($cls, 'border-t')) {
            return false;
        }

        $if = $node['if'] ?? '';
        if (! is_string($if)) {
            return false;
        }
        if (str_contains($if, self::LOCK_IF_MARKER)) {
            return false; // 이미 잠금 조건이 붙음
        }

        return str_contains($if, 'can_write_comments') && str_contains($if, 'deleted_at');
    }

    /**
     * 댓글 입력 폼의 기존 `if` 에 "잠긴 포럼 게시글이 아닐 때만" 조건을 AND 로 덧붙인다.
     */
    private function appendLockGuardToIf(string $baseIf): string
    {
        $guard = "!(forum_meta?.data?.locked && post?.data?.board?.type === 'forum')";

        // 기존 표현식에서 바깥 `{{ }}` 를 벗겨 하나로 합친다.
        $inner = $baseIf;
        if (str_starts_with($inner, '{{') && str_ends_with($inner, '}}')) {
            $inner = trim(substr($inner, 2, -2));
        }

        if ($inner === '') {
            return '{{'.$guard.'}}';
        }

        return '{{('.$inner.') && '.$guard.'}}';
    }

    /**
     * 잠긴 게시글에서 댓글 입력 폼 자리에 대신 렌더되는 안내 노드.
     *
     * 폼과 같은 기본 조건(작성권한·비블라인드·비삭제)을 그대로 두고, 그 위에
     * "잠긴 포럼 게시글" 조건을 더해 폼이 사라진 바로 그 자리에만 나타나게 한다.
     *
     * @return array<string, mixed>
     */
    private function lockNoticeNode(): array
    {
        return [
            'id' => self::LOCK_NOTICE_ID,
            'type' => 'basic',
            'name' => 'Div',
            'if' => "{{post?.data?.abilities?.can_write_comments && post?.data?.status !== 'blinded' && post?.data?.status !== 'deleted' && !post?.data?.deleted_at && forum_meta?.data?.locked && post?.data?.board?.type === 'forum'}}",
            'props' => [
                'className' => 'p-4 border-t border-gray-200 dark:border-gray-700 flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400',
            ],
            'children' => [
                [
                    'type' => 'composite',
                    'name' => 'Icon',
                    'props' => ['name' => 'lock', 'size' => 'sm'],
                ],
                [
                    'type' => 'basic',
                    'name' => 'P',
                    'text' => '$t:g7-forum-addon.locked_comment_notice',
                ],
            ],
        ];
    }

    /**
     * 컴포넌트 트리를 재귀 순회하며, 렌더된 댓글 본문 `<P>` 노드 **직후**에 댓글 리액션
     * 바를 splice 한다.
     *
     * `_comment_item.json` 은 댓글 iteration 템플릿 안에 1벌만 인라인되므로(엔진이 댓글마다
     * 반복 렌더) 여기서도 1회만 splice 된다. 리액션 바 `if` 가 `board.type === 'forum'` 을
     * 포함해 포럼 게시판에서만 렌더된다.
     *
     * @param  array<int, mixed>  $nodes
     * @param  int  $applied  (참조) 적용 횟수
     * @return array<int, mixed>
     */
    private function applyCommentReactions(array $nodes, int &$applied): array
    {
        $out = [];

        foreach ($nodes as $node) {
            if (is_array($node) && isset($node['children']) && is_array($node['children'])) {
                $node['children'] = $this->applyCommentReactions($node['children'], $applied);
            }

            $out[] = $node;

            if ($this->isCommentContentP($node)) {
                $out[] = $this->commentReactionBarNode();
                $applied++;
            }
        }

        return $out;
    }

    /**
     * 노드가 `_comment_item.json` 의 "읽기 모드 댓글 본문" `<P>` 인지 형태로 판정.
     *
     * 신호(모두 만족): `name === 'P'`, `text === '{{comment?.content}}'`, `if` 문자열에
     * `is_cascade_deleted` 포함(그 P 고유 게이트 — 블라인드 원문 P 는 `if` 가 없어 배제).
     */
    private function isCommentContentP(mixed $node): bool
    {
        if (! is_array($node)) {
            return false;
        }
        if (($node['name'] ?? null) !== 'P') {
            return false;
        }
        if (trim((string) ($node['text'] ?? '')) !== '{{comment?.content}}') {
            return false;
        }
        $if = $node['if'] ?? '';

        return is_string($if) && str_contains($if, 'is_cascade_deleted');
    }

    /**
     * 댓글 본문 아래에 붙는 리액션 바 노드.
     *
     * 삭제·블라인드·수정중 댓글에는 숨긴다. 포럼 게시판에서만 렌더. 데이터는
     * `forum_meta.data.comment_reactions[comment.id]` 에서 읽는다.
     *
     * @return array<string, mixed>
     */
    private function commentReactionBarNode(): array
    {
        $bar = $this->reactionBar('comment');
        $bar['id'] = self::COMMENT_REACTIONS_ID;
        $bar['if'] = "{{(!comment?.deleted_at || comment?.is_cascade_deleted) && comment?.status !== 'blinded' && _global?.commentEdit?.editingCommentId !== comment?.id && post?.data?.board?.type === 'forum'}}";

        return $bar;
    }

    /**
     * 컴포넌트 트리를 재귀 순회하며, 렌더된 댓글 본문 `<P>` 노드 **직후**에 채택(베스트답글)
     * 배지/버튼 행을 splice 한다. (리액션 바보다 먼저 splice 호출되므로 `<P>` 바로 뒤 =
     * 리액션 바 위에 온다.)
     *
     * @param  array<int, mixed>  $nodes
     * @param  int  $applied  (참조) 적용 횟수
     * @return array<int, mixed>
     */
    private function applyAcceptedReplyUi(array $nodes, int &$applied): array
    {
        $out = [];

        foreach ($nodes as $node) {
            if (is_array($node) && isset($node['children']) && is_array($node['children'])) {
                $node['children'] = $this->applyAcceptedReplyUi($node['children'], $applied);
            }

            $out[] = $node;

            if ($this->isCommentContentP($node)) {
                $out[] = $this->acceptedReplyNode();
                $applied++;
            }
        }

        return $out;
    }

    /**
     * 댓글 본문 아래에 붙는 채택(베스트답글) 배지 + 버튼 행.
     *
     * - "✅ 채택된 답변" 배지: 이 댓글이 채택된 답변이면 모두에게 표시.
     * - "채택하기" 버튼: 뷰어가 글 작성자 본인 또는 사이트 관리자이고, 이 댓글이 아직
     *   채택 상태가 아닐 때.
     * - "채택 해제" 버튼: 위 권한 + 이 댓글이 현재 채택 상태일 때.
     *
     * 클릭 → `apiCall` → `refetchDataSource: forum_meta` (새로고침 없이 갱신).
     * 삭제·블라인드·수정중 댓글엔 숨김, 포럼 게시판에서만 렌더.
     *
     * @return array<string, mixed>
     */
    private function acceptedReplyNode(): array
    {
        $canManage = '(post?.data?.is_owner || _global.currentUser?.is_admin)';
        $isAccepted = 'forum_meta?.data?.accepted_reply_id === comment?.id';
        $visibleBase = "(!comment?.deleted_at || comment?.is_cascade_deleted) && comment?.status !== 'blinded'"
            ." && _global?.commentEdit?.editingCommentId !== comment?.id && post?.data?.board?.type === 'forum'";

        $btnBase = 'inline-flex items-center rounded-md border px-2 py-0.5 text-xs font-medium transition-colors cursor-pointer ';

        return [
            'id' => self::ACCEPTED_REPLY_ID,
            'type' => 'basic',
            'name' => 'Div',
            'if' => '{{'.$visibleBase.' && ('.$isAccepted.' || '.$canManage.')}}',
            'props' => ['className' => 'mt-2 flex flex-wrap items-center gap-2'],
            'children' => [
                [
                    // 채택 배지 — 모두에게.
                    'type' => 'basic',
                    'name' => 'Span',
                    'if' => '{{'.$isAccepted.'}}',
                    'props' => [
                        'className' => 'inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-semibold text-green-700 dark:bg-green-900/40 dark:text-green-300',
                    ],
                    'text' => '$t:g7-forum-addon.accepted_badge',
                ],
                [
                    // 채택하기 — 작성자/관리자 + 아직 미채택.
                    'type' => 'basic',
                    'name' => 'Button',
                    'if' => '{{'.$canManage.' && !('.$isAccepted.')}}',
                    'props' => [
                        'type' => 'button',
                        'className' => $btnBase.'border-green-300 text-green-700 hover:bg-green-50 dark:border-green-700 dark:text-green-300 dark:hover:bg-green-900/30',
                    ],
                    'text' => '$t:g7-forum-addon.accept_button',
                    'actions' => [$this->acceptAction('accept')],
                ],
                [
                    // 채택 해제 — 작성자/관리자 + 현재 채택된 댓글.
                    'type' => 'basic',
                    'name' => 'Button',
                    'if' => '{{'.$canManage.' && '.$isAccepted.'}}',
                    'props' => [
                        'type' => 'button',
                        'className' => $btnBase.'border-gray-300 text-gray-600 hover:bg-gray-100 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700',
                    ],
                    'text' => '$t:g7-forum-addon.unaccept_button',
                    'actions' => [$this->acceptAction('unaccept')],
                ],
            ],
        ];
    }

    /**
     * 채택/해제 `apiCall` 액션.
     *
     * @param  string  $verb  'accept' | 'unaccept'
     * @return array<string, mixed>
     */
    private function acceptAction(string $verb): array
    {
        return [
            'type' => 'click',
            'handler' => 'apiCall',
            'auth_required' => true,
            'target' => '/api/plugins/g7-forum-addon/posts/{{route?.id}}/comments/{{comment?.id}}/'.$verb,
            'params' => ['method' => 'POST'],
            'onSuccess' => [
                ['handler' => 'toast', 'params' => ['type' => 'success', 'message' => '{{response?.message}}']],
                ['handler' => 'refetchDataSource', 'params' => ['dataSourceId' => self::META_DS_ID]],
            ],
            'onError' => [
                ['handler' => 'toast', 'params' => ['type' => 'error', 'message' => '{{error.message}}']],
            ],
        ];
    }
}
