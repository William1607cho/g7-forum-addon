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

    /** 댓글 행 컨테이너 className(sirsoft-basic 원본 그대로) — 채택 강조 앵커 */
    private const COMMENT_ROW_CLASS = 'flex gap-3 p-4 rounded-lg';

    /** 채택 강조가 이미 적용됐는지 표시하는 마커(멱등) */
    private const ACCEPTED_ROW_MARKER = 'forum_meta?.data?.accepted_reply_id === comment?.id';

    /** 위젯 영역의 채택된 답변 전문 박스 노드의 안정 식별자 */
    private const ACCEPTED_REPLY_BOX_ID = 'g7_forum_addon_accepted_reply_box';

    /** `/meta` 데이터소스 id */
    private const META_DS_ID = 'forum_meta';

    /**
     * "답글이 있는 댓글" 행 가시성 `if`(sirsoft-basic 코어, 원본 그대로) — 답글은
     * `_local.collapsedReplies[루트id] === false` 일 때만 보인다(기본값 undefined = 접힘).
     * 구조적 시그니처로 앵커 식별에 쓴다(리터럴 일치, 3개 게시판 유형 분기 모두 동일).
     */
    private const REPLIES_ROW_IF_ORIGINAL = '{{(comment?.depth ?? 0) === 0 || (_local.collapsedReplies?.[$computed.commentRootMap?.[comment?.id]] === false)}}';

    /** 위 `if` 를 forum 게시판일 때만 "기본값이 펼침"이 되도록 바꾼 버전(멱등 마커 겸용) */
    private const REPLIES_ROW_IF_PATCHED = '{{(comment?.depth ?? 0) === 0 || !(_local.collapsedReplies?.[$computed.commentRootMap?.[comment?.id]] ?? (post?.data?.board?.type === \'forum\' ? false : true))}}';

    /** "답글 보기 (N)" 토글 버튼의 `if`(sirsoft-basic 코어, 원본 그대로) — 앵커 식별용 */
    private const REPLIES_TOGGLE_BUTTON_IF = '{{(comment?.replies_count ?? 0) > 0 && (comment?.depth ?? 0) === 0}}';

    /** 토글 클릭 시 `_local.collapsedReplies` 갱신식(원본) */
    private const REPLIES_TOGGLE_SETSTATE_ORIGINAL = '{{Object.assign({}, _local.collapsedReplies || {}, {[comment?.id]: !(_local.collapsedReplies?.[comment?.id] ?? true)})}}';

    /** 위 갱신식을 forum 게시판일 때만 "기본값이 펼침"이 되도록 바꾼 버전 */
    private const REPLIES_TOGGLE_SETSTATE_PATCHED = '{{Object.assign({}, _local.collapsedReplies || {}, {[comment?.id]: !(_local.collapsedReplies?.[comment?.id] ?? (post?.data?.board?.type === \'forum\' ? false : true))})}}';

    /** 토글 아이콘 이름 표현식(원본) */
    private const REPLIES_TOGGLE_ICON_ORIGINAL = '{{_local.collapsedReplies?.[comment?.id] === false ? \'chevron-up\' : \'chevron-down\'}}';

    /** 위 아이콘 표현식의 forum 대응 버전 */
    private const REPLIES_TOGGLE_ICON_PATCHED = '{{!(_local.collapsedReplies?.[comment?.id] ?? (post?.data?.board?.type === \'forum\' ? false : true)) ? \'chevron-up\' : \'chevron-down\'}}';

    /** 토글 라벨 텍스트 표현식(원본) */
    private const REPLIES_TOGGLE_LABEL_ORIGINAL = '{{_local.collapsedReplies?.[comment?.id] === false ? \'$t:board.hide_replies\' : \'$t:board.show_replies\'}} ({{comment?.replies_count}})';

    /** 위 라벨 표현식의 forum 대응 버전 */
    private const REPLIES_TOGGLE_LABEL_PATCHED = '{{!(_local.collapsedReplies?.[comment?.id] ?? (post?.data?.board?.type === \'forum\' ? false : true)) ? \'$t:board.hide_replies\' : \'$t:board.show_replies\'}} ({{comment?.replies_count}})';

    /**
     * 추천 종류 → 아이콘 이름 (순서 = 표시 순서, ReactionStore::REACTIONS 와 일치).
     *
     * 템플릿(wc-community)의 Font Awesome 은 **Solid 전용 서브셋**이라 목록에 있는
     * 이름만 글리프를 갖는다. 없는 이름을 넣으면 오류 없이 빈칸으로 렌더되므로, 쓰는
     * 이름은 모두 배포된 서브셋 CSS 에 규칙이 있는지 확인하고 골랐다.
     * `caret-up`/`caret-down` 은 서브셋에 없어 같은 방향의 `chevron-*` 으로 대체했다.
     */
    private const REACTION_ICON = [
        'up' => 'chevron-up',
        'down' => 'chevron-down',
    ];

    /** 추천 종류 → 접근성 라벨 키 (title·aria-label) */
    private const REACTION_LABEL_KEY = [
        'up' => '$t:g7-forum-addon.reaction_up',
        'down' => '$t:g7-forum-addon.reaction_down',
    ];

    /**
     * 고정(핀) 버튼 아이콘.
     *
     * `thumbtack` 이 서브셋에 없다. 이 기능의 실체가 코어 공지(`board_posts.is_notice`)
     * 라서 "확성기" 가 의미상 어긋나지 않고, 서브셋에 있다.
     */
    private const PIN_ICON = 'bullhorn';

    /** 잠금 버튼 아이콘 (서브셋에 있다. `lock-open`/`unlock` 은 없어 상태는 색으로 구분한다) */
    private const LOCK_ICON = 'lock';

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

        // 채택된 댓글의 행 전체를 강조한다(멱등). 배지·버튼과 독립적으로 시도한다.
        $rowApplied = 0;
        $layout['components'] = $this->applyAcceptedRowHighlight($layout['components'], $rowApplied);

        if ($rowApplied === 0) {
            Log::warning('[g7-forum-addon] board/show 댓글 행 컨테이너 앵커를 찾지 못해 채택 강조를 적용하지 못했습니다. 채택 배지와 API 는 그대로 동작합니다.', [
                'template_id' => $templateId,
            ]);
        }

        // `/meta` 2-call 데이터소스 주입 (레이아웃당 1회). 위젯/잠금/리액션 UI 가 쓴다.
        $layout['data_sources'] = $this->withMetaDataSource($layout['data_sources'] ?? []);

        // 댓글 삭제 성공 시 forum_meta 도 함께 갱신 — 채택된 답변이 삭제되면
        // AcceptedReplyCleanupListener 가 DB 는 즉시 정리하지만, 프론트가 forum_meta 를
        // 재조회하지 않으면 위젯의 채택 답변 박스가 새로고침 전까지 남아있는다.
        // 공용 삭제 모달(sirsoft-basic 코어, board_delete_modal)의 onSuccess 체인에
        // 이미 있는 `dataSourceId: post` 재조회 스텝을 구조적 시그니처로 찾아 그 옆에
        // 스텝 하나를 추가한다 — 모달 자체를 새로 만들거나 코어 파일을 고치지 않는다(C-2).
        // 주의: 삭제 모달은 `components` 가 아니라 레이아웃의 **별도 최상위 키인
        // `modals`** 아래에 있다(컴포넌트 트리 밖) — 그래서 두 트리를 모두 순회한다.
        $deleteRefetchApplied = 0;
        $layout['components'] = $this->applyCommentDeleteMetaRefetch($layout['components'], $deleteRefetchApplied);
        if (isset($layout['modals']) && is_array($layout['modals'])) {
            $layout['modals'] = $this->applyCommentDeleteMetaRefetch($layout['modals'], $deleteRefetchApplied);
        }

        if ($deleteRefetchApplied === 0) {
            Log::warning('[g7-forum-addon] 댓글 삭제 모달의 forum_meta 재조회 앵커(dataSourceId=post 재조회 스텝)를 찾지 못했습니다. 채택된 답변이 삭제되면 위젯 박스가 새로고침 전까지 남아있을 수 있습니다(백엔드 자동 해제 자체는 정상 동작).', [
                'template_id' => $templateId,
            ]);
        }

        // "답글 보기 (N)" 토글 기본 펼침(forum 전용) — sirsoft-basic 코어의 `_local.collapsedReplies`
        // 삼항식 4곳(행 가시성·클릭 토글·아이콘·라벨)은 전부 "값 없으면 접힘"이 기본값이다.
        // 서버 데이터(post.data.comments)에 의존하는 initActions 방식은 `post` 데이터소스가
        // progressive(non-blocking)라 타이밍상 불가 — 대신 각 삼항식이 댓글 반복 렌더 시점에
        // 평가된다는 점(이미 post.data 로딩 완료)을 이용해, "기본값 분기"만
        // `post?.data?.board?.type === 'forum'` 조건으로 바꿔치기한다. 컴포넌트 트리 안에만
        // 있음을 실측 확인(모달 같은 별도 최상위 키 없음).
        $repliesApplied = 0;
        $layout['components'] = $this->applyForumRepliesDefaultExpanded($layout['components'], $repliesApplied);

        if ($repliesApplied === 0) {
            Log::warning('[g7-forum-addon] board/show 답글 토글 앵커(collapsedReplies 삼항식)를 찾지 못해 forum 답글 기본 펼침을 적용하지 못했습니다. sirsoft-basic 레이아웃 구조 변경 여부 확인 필요.', [
                'template_id' => $templateId,
            ]);
        }

        return $layout;
    }

    /**
     * 댓글 트리를 재귀 순회하며 "답글 보기 (N)" 토글 관련 4개 표현식을 구조적
     * 시그니처(리터럴 일치)로 찾아 forum 전용 기본-펼침 버전으로 치환한다. 이미
     * 치환됐으면(멱등 — 원본 리터럴과 더 이상 일치하지 않음) 건드리지 않는다.
     *
     * @param  array<int, mixed>  $nodes
     * @param  int  $applied  (참조) 적용 횟수
     * @return array<int, mixed>
     */
    private function applyForumRepliesDefaultExpanded(array $nodes, int &$applied): array
    {
        $out = [];

        foreach ($nodes as $node) {
            if (is_array($node)) {
                if (($node['if'] ?? null) === self::REPLIES_ROW_IF_ORIGINAL) {
                    $node['if'] = self::REPLIES_ROW_IF_PATCHED;
                    $applied++;
                } elseif ($this->isRepliesToggleButton($node)) {
                    $node['actions'][0]['params']['collapsedReplies'] = self::REPLIES_TOGGLE_SETSTATE_PATCHED;
                    $node['children'][0]['props']['name'] = self::REPLIES_TOGGLE_ICON_PATCHED;
                    $node['children'][1]['text'] = self::REPLIES_TOGGLE_LABEL_PATCHED;
                    $applied++;
                }

                if (isset($node['children']) && is_array($node['children'])) {
                    $node['children'] = $this->applyForumRepliesDefaultExpanded($node['children'], $applied);
                }
            }

            $out[] = $node;
        }

        return $out;
    }

    /**
     * 노드가 "답글 보기 (N)" 토글 버튼인지 형태로 판정.
     *
     * 신호: `if` 가 원본 리터럴과 일치 + `actions[0].params.collapsedReplies` 원본
     * 리터럴 존재(이미 치환됐으면 더 이상 원본과 일치하지 않아 자연히 멱등).
     */
    private function isRepliesToggleButton(mixed $node): bool
    {
        if (! is_array($node)) {
            return false;
        }

        if (($node['if'] ?? null) !== self::REPLIES_TOGGLE_BUTTON_IF) {
            return false;
        }

        $current = $node['actions'][0]['params']['collapsedReplies'] ?? null;

        return $current === self::REPLIES_TOGGLE_SETSTATE_ORIGINAL;
    }

    /**
     * 컴포넌트 트리를 전체 재귀 순회(`children` 뿐 아니라 `actions`/`onSuccess` 등
     * 모든 배열 값)하며 댓글 삭제 성공 액션 체인(`onSuccess`)을 찾아 패치한다.
     *
     * @param  array<int|string, mixed>  $nodes
     * @param  int  $applied  (참조) 적용 횟수
     * @return array<int|string, mixed>
     */
    private function applyCommentDeleteMetaRefetch(array $nodes, int &$applied): array
    {
        foreach ($nodes as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            if ($key === 'onSuccess' && array_is_list($value)) {
                $nodes[$key] = $this->spliceForumMetaRefetchStep($value, $applied);

                continue;
            }

            $nodes[$key] = $this->applyCommentDeleteMetaRefetch($value, $applied);
        }

        return $nodes;
    }

    /**
     * `onSuccess` 스텝 배열에서 "댓글 삭제 시 게시글(post) 재조회" 스텝을 구조적
     * 시그니처(handler=refetchDataSource + dataSourceId=post + if 에
     * `deleteModal.type === 'comment'` 포함)로 찾아, 바로 뒤에 forum_meta 재조회
     * 스텝을 추가한다. 이미 forum_meta 스텝이 있으면(멱등) 건드리지 않는다.
     *
     * @param  array<int, mixed>  $steps
     * @return array<int, mixed>
     */
    private function spliceForumMetaRefetchStep(array $steps, int &$applied): array
    {
        foreach ($steps as $step) {
            if (is_array($step) && ($step['params']['dataSourceId'] ?? null) === self::META_DS_ID) {
                return $steps;
            }
        }

        $insertAt = null;
        foreach ($steps as $i => $step) {
            if (
                is_array($step)
                && ($step['handler'] ?? null) === 'refetchDataSource'
                && ($step['params']['dataSourceId'] ?? null) === 'post'
                && str_contains((string) ($step['if'] ?? ''), "deleteModal.type === 'comment'")
            ) {
                $insertAt = $i;
                break;
            }
        }

        if ($insertAt === null) {
            return $steps;
        }

        $newStep = [
            'comment' => 'g7-forum-addon: 댓글 삭제 시 채택된 답변 등 포럼 메타도 함께 갱신(위젯 박스 즉시 반영)',
            'if' => "{{_global.deleteModal.type === 'comment'}}",
            'handler' => 'refetchDataSource',
            'params' => ['dataSourceId' => self::META_DS_ID],
        ];

        array_splice($steps, $insertAt + 1, 0, [$newStep]);
        $applied++;

        return $steps;
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
     * ── 1.3.0 레이아웃 ──────────────────────────────────────────
     * 바깥은 상자가 아니다(점선 테두리·배경 없음, 여백만 유지). 안은 두 층이다.
     *
     *   [버튼 줄]  ▲n ▼n   (배지…)                      📢  🔒
     *   [채택 답변 전문 박스]
     *
     * 버튼 줄은 `flex`(줄바꿈 없음)라 좁은 화면에서도 버튼 4개가 한 줄을 지킨다.
     * 가운데 배지 영역만 `flex-wrap min-w-0`이라, 공간이 모자라면 배지가 줄바꿈되거나
     * 줄어들고 버튼은 밀려나지 않는다. 오른쪽 버튼 묶음은 `ml-auto` 로 끝에 붙는다.
     *
     * 채택 답변 박스는 버튼 줄의 형제가 아니라 아래 층이다 — 버튼 줄이 `nowrap` 이라
     * 그 안에 두면 `w-full` 이 먹지 않고 눌린다.
     *
     * board_type 게이팅은 위젯 노드 전체 `if` 로 유지한다.
     *
     * @return array<string, mixed>
     */
    private function widgetNode(): array
    {
        $badge = 'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold text-white';

        return [
            'id' => self::WIDGET_ID,
            'type' => 'basic',
            'name' => 'Div',
            'if' => "{{post?.data?.board?.type === 'forum' && post?.data?.status !== 'blinded' && post?.data?.content !== null}}",
            'props' => [
                // 점선 상자를 걷어냈다. 좌우 여백(mx-6)은 본문 카드와 맞추기 위해 유지한다.
                'className' => 'mx-6 mt-2 mb-2',
            ],
            'children' => [
                [
                    // 버튼 줄 — 줄바꿈 없음.
                    'type' => 'basic',
                    'name' => 'Div',
                    'props' => ['className' => 'flex items-center gap-2'],
                    'children' => [
                        // 왼쪽: 추천 업·다운.
                        $this->reactionBar('post'),
                        [
                            // 가운데: 상태 배지. 여기만 줄바꿈·축소를 허용한다.
                            'type' => 'basic',
                            'name' => 'Div',
                            'props' => ['className' => 'flex flex-wrap items-center gap-1.5 min-w-0'],
                            'children' => [
                                [
                                    // 고정(공지) 배지 — forum_meta.data.is_notice 가 참일 때만.
                                    'type' => 'basic',
                                    'name' => 'Span',
                                    'if' => '{{forum_meta?.data?.is_notice}}',
                                    'props' => ['className' => $badge.' bg-blue-600 dark:bg-blue-500'],
                                    'text' => '$t:g7-forum-addon.pinned_badge',
                                ],
                                [
                                    // 잠금 배지 — forum_meta.data.locked 가 참일 때만.
                                    'type' => 'basic',
                                    'name' => 'Span',
                                    'if' => '{{forum_meta?.data?.locked}}',
                                    'props' => ['className' => $badge.' bg-gray-600 dark:bg-gray-500'],
                                    'text' => '$t:g7-forum-addon.locked_badge',
                                ],
                                [
                                    // 채택 답변 요약 배지 — forum_meta.data.accepted_reply_id 가 있을 때만.
                                    'type' => 'basic',
                                    'name' => 'Span',
                                    'if' => '{{forum_meta?.data?.accepted_reply_id}}',
                                    'props' => ['className' => $badge.' bg-green-600 dark:bg-green-500'],
                                    'text' => '$t:g7-forum-addon.widget_has_accepted',
                                ],
                            ],
                        ],
                        [
                            // 오른쪽 끝: 관리자급 전용 핀/잠금 토글.
                            // `post.data.abilities.can_manage`(= `sirsoft-board.{slug}.manager`)
                            // 로 노출하고, 서버가 같은 식별자로 최종 판정한다.
                            // 켜짐/꺼짐은 같은 자리에 하나씩만 렌더되는 두 버튼으로 나타낸다 —
                            // 대상 경로를 표현식으로 조립하지 않아도 되고, 채운 색과
                            // `aria-pressed` 가 버튼별로 고정값이라 읽기 쉽다.
                            'type' => 'basic',
                            'name' => 'Div',
                            'props' => ['className' => 'ml-auto flex items-center gap-2 shrink-0'],
                            'children' => [
                                $this->pinToggleButton(false),
                                $this->pinToggleButton(true),
                                $this->lockToggleButton(false),
                                $this->lockToggleButton(true),
                            ],
                        ],
                    ],
                ],
                // 채택된 답변 전문 박스 — forum_meta.data.accepted_reply 가 있을 때만.
                $this->acceptedReplyBox(),
            ],
        ];
    }

    /**
     * 위젯·댓글에서 같은 크기로 쓰는 정사각 버튼의 공통 클래스.
     *
     * 한 변 40px(`w-10 h-10`). 1.2.0 의 "고정하기" 버튼 높이 26px
     * (text-xs 줄높이 16 + `py-1` 8 + 테두리 2)의 1.5배는 39px 인데, Tailwind 임의값
     * (`w-[39px]`)은 애드온이 PHP 로 만드는 클래스라 빌드 시점 스캔 대상이 아니어서
     * CSS 가 생성되지 않는다. 빌드 CSS 에 실제로 존재하는 이웃 값 중 39px 에 가장 가까운
     * `w-10 h-10`(40px)을 쓴다(`w-9 h-9` = 36px, `h-11` 은 CSS 에 없다).
     */
    private function squareButtonClass(): string
    {
        return 'inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-md border transition-colors ';
    }

    /**
     * 채택된 답변 전문 박스.
     *
     * `forum_meta.data.accepted_reply` 가 있을 때만 렌더된다(없으면 = 채택 안 됨 또는
     * 채택된 댓글이 삭제·블라인드 등으로 무효화됨 — `AcceptedReplyState::resolveForMeta()`
     * 가 자기치유하므로 이 박스도 자동으로 사라진다, 별도 처리 불필요).
     *
     * 본문은 **`text` 바인딩으로 이스케이프해 렌더**한 뒤, g7-comment-editor 가 페이지
     * 전역에서 이미 스캔하는 `p.text-gray-700.dark:text-gray-300`(빈 자식 + HTML-ish
     * 여부 판정) 선택자에 **일부러 같은 클래스를 그대로 얹어** 그 기존 승격
     * 파이프라인(`sanitizeCommentHtml` 재정화 → `innerHTML` 승격 → `enrichComment`
     * 외부링크 렌더링)에 편승한다. 댓글 본문을 여기서 다시 안전하게 표시하려고
     * 새 렌더링 경로(`HtmlContent` composite 의 `dangerouslySetInnerHTML`)를 만들면
     * DB 원본을 검증 없이 그대로 주입하는 셈이 되어, 사이트 전체가 의존하는
     * "표시할 때마다 재정화" 방어선을 이 박스만 우회하게 된다 — 그래서 일부러
     * 기존 댓글과 완전히 같은 표시 경로를 태운다(새 코드 0, g7-comment-editor 무변경).
     * 클래스 조합(`prose dark:prose-invert prose-sm max-w-none text-gray-700
     * dark:text-gray-300`)은 이 템플릿(`_modal_privacy.json` 등)에 이미 리터럴로 존재해
     * Tailwind 빌드 시점에 스캔된 조합만 골랐다(즉석 조합 시 CSS 미생성 함정 재발 방지).
     *
     * @return array<string, mixed>
     */
    private function acceptedReplyBox(): array
    {
        return [
            'id' => self::ACCEPTED_REPLY_BOX_ID,
            'type' => 'basic',
            'name' => 'Div',
            'if' => '{{!!forum_meta?.data?.accepted_reply}}',
            'props' => [
                // 초록 계열 고정값 — 다음 단계(색상 설정 UI)에서 여기 4개 클래스
                // (bg-green-50/dark:bg-green-900/10/border-green-300/dark:border-green-700)
                // 를 설정값 기반 동적 스타일로 교체 예정.
                'className' => 'w-full mt-1 rounded-lg border border-green-300 dark:border-green-700 bg-green-50 dark:bg-green-900/10 p-3',
            ],
            'children' => [
                [
                    // 작성자 아이콘 + 이름 + 작성 시각.
                    'type' => 'basic',
                    'name' => 'Div',
                    'props' => ['className' => 'flex items-center gap-2 mb-2'],
                    'children' => [
                        [
                            'type' => 'composite',
                            'name' => 'Avatar',
                            'props' => [
                                'author' => '{{forum_meta?.data?.accepted_reply?.author}}',
                                'size' => 'xs',
                            ],
                        ],
                        [
                            'type' => 'basic',
                            'name' => 'Span',
                            'props' => ['className' => 'text-sm font-medium text-green-800 dark:text-green-300'],
                            'text' => '{{forum_meta?.data?.accepted_reply?.author?.name ?? \'\'}}',
                        ],
                        [
                            'type' => 'basic',
                            'name' => 'Span',
                            'props' => [
                                'className' => 'text-xs text-green-600 dark:text-green-400',
                                'title' => '{{forum_meta?.data?.accepted_reply?.created_at ?? \'\'}}',
                            ],
                            'text' => '{{forum_meta?.data?.accepted_reply?.created_at_formatted ?? \'\'}}',
                        ],
                    ],
                ],
                [
                    // 본문 전문 — text 바인딩(이스케이프) + g7-comment-editor 기존 전역
                    // 스캐너(class 조합이 앵커)가 재정화·승격을 전담. 새 렌더링 경로 없음.
                    'type' => 'basic',
                    'name' => 'P',
                    'props' => [
                        'className' => 'prose dark:prose-invert prose-sm max-w-none text-gray-700 dark:text-gray-300',
                    ],
                    'text' => '{{forum_meta?.data?.accepted_reply?.content ?? \'\'}}',
                ],
            ],
        ];
    }

    /**
     * 추천 바 컨테이너 — 업·다운 정사각 버튼 2개.
     *
     * @param  string  $scope  'post' | 'comment'
     * @return array<string, mixed>
     */
    private function reactionBar(string $scope): array
    {
        $buttons = [];
        foreach (array_keys(self::REACTION_ICON) as $reaction) {
            $buttons[] = $this->reactionButton($scope, $reaction);
        }

        return [
            'type' => 'basic',
            'name' => 'Div',
            'props' => ['className' => 'flex items-center gap-2 shrink-0'],
            'children' => $buttons,
        ];
    }

    /**
     * 추천 버튼 1개 (업 또는 다운).
     *
     * 40px 정사각({@see squareButtonClass})에 **위는 방향 아이콘, 아래는 숫자**를 세로로
     * 쌓는다. 고정·잠금 버튼과 한 변이 같아 한 줄에서 높이가 어긋나지 않는다.
     *
     * ── 본인 글·본인 댓글 ───────────────────────────────────────
     * 자기 글/댓글에는 투표할 수 없다. 버튼을 감추지 않고 `disabled` 로 두는 이유는,
     * 감추면 업/다운 숫자까지 사라져 작성자만 자기 글의 점수를 못 보게 되기 때문이다.
     * 판정은 코어가 이미 내려주는 값을 쓴다 — 게시글은 `post.data.is_owner`
     * (`BaseApiResource::resourceMeta`), 댓글은 `comment.is_author`
     * (`CommentResource`). 서버도 같은 조건을 403 으로 막으므로 이 비활성화는 안내용이다.
     *
     * ── 개수 표시 ────────────────────────────────────────────────
     * 업·다운 각각의 개수를 항상 숫자로 보여준다(0 이어도 숨기지 않는다) — 두 버튼이
     * 나란히 있는데 한쪽만 숫자가 붙으면 어느 쪽이 0 인지 읽히지 않는다. 순점수
     * (up − down)는 만들지 않는다.
     *
     * @param  string  $scope     'post' | 'comment'
     * @param  string  $reaction  up|down
     * @return array<string, mixed>
     */
    private function reactionButton(string $scope, string $reaction): array
    {
        $icon = self::REACTION_ICON[$reaction];
        $label = self::REACTION_LABEL_KEY[$reaction];

        if ($scope === 'post') {
            $countExpr = "forum_meta?.data?.reactions?.counts?.".$reaction;
            $mineExpr = 'forum_meta?.data?.reactions?.mine';
            $target = '/api/plugins/g7-forum-addon/posts/{{route?.id}}/reactions';
            $isOwnExpr = 'post?.data?.is_owner';
        } else {
            $base = "forum_meta?.data?.comment_reactions?.[comment?.id]";
            $countExpr = $base.'?.counts?.'.$reaction;
            $mineExpr = $base.'?.mine';
            $target = '/api/plugins/g7-forum-addon/comments/{{comment?.id}}/reactions';
            $isOwnExpr = 'comment?.is_author';
        }

        // cursor 는 세 분기 각각에 넣는다 — 기본 클래스에 `cursor-pointer` 를 두고
        // 분기에서 `cursor-not-allowed` 를 얹으면 두 유틸리티가 같은 특정도로 겹쳐
        // 어느 쪽이 이길지 Tailwind 출력 순서에 달리게 된다.
        $activeCls = "cursor-pointer border-blue-500 bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-200 dark:border-blue-500";
        $idleCls = "cursor-pointer border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700";
        $ownCls = "cursor-not-allowed border-gray-200 dark:border-gray-700 text-gray-400 dark:text-gray-500 opacity-60";
        $className = $this->squareButtonClass().'flex-col gap-0.5 '
            ."{{ (".$isOwnExpr.") ? '".$ownCls."' : ((".$mineExpr.") === '".$reaction."' ? '".$activeCls."' : '".$idleCls."') }}";

        return [
            'type' => 'basic',
            'name' => 'Button',
            'props' => [
                'type' => 'button',
                'className' => $className,
                'disabled' => '{{!!('.$isOwnExpr.')}}',
                'title' => $label,
                'aria-label' => $label,
                'aria-pressed' => '{{('.$mineExpr.") === '".$reaction."'}}",
            ],
            'children' => [
                [
                    'type' => 'composite',
                    'name' => 'Icon',
                    'props' => ['name' => $icon, 'ariaLabel' => $label],
                ],
                [
                    'type' => 'basic',
                    'name' => 'Span',
                    'props' => ['className' => 'text-xs font-medium leading-none'],
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
     * 위젯 안의 관리자급 토글 버튼 1개 (핀·잠금 공통 골격).
     *
     * 40px 정사각({@see squareButtonClass})에 **아이콘만** 둔다. 이름은 `title` 과
     * `aria-label` 로 제공하므로 읽어 주는 도구에서도 무엇인지 알 수 있다.
     *
     * ── 켜짐/꺼짐 ────────────────────────────────────────────────
     * 같은 자리에 두 버튼 중 하나만 렌더된다(꺼짐일 때 "켜는" 버튼, 켜짐일 때 "끄는"
     * 버튼). 그래서 대상 경로를 표현식으로 조립할 필요가 없고, 채운 색과 `aria-pressed`
     * 가 버튼마다 고정값이라 읽기도 쉽다. **켜진 상태는 채운 색**(꺼짐은 테두리만)으로
     * 구분한다 — `lock-open`/`unlock` 아이콘이 템플릿 서브셋에 없어 모양으로는 구분할
     * 수 없기 때문이다.
     *
     * 노출 조건은 `post.data.abilities.can_manage`(= `sirsoft-board.{slug}.manager`)이고,
     * 서버가 같은 식별자로 최종 판정한다.
     *
     * @param  bool    $on        현재 켜진 상태에 놓이는 버튼인가(= 끄는 버튼)
     * @param  string  $stateExpr 켜짐 여부 표현식
     * @param  string  $icon      아이콘 이름 (서브셋에 있는 것만)
     * @param  string  $endpoint  호출할 엔드포인트 마지막 마디
     * @param  string  $labelKey  title·aria-label 로 쓸 번역 키
     * @param  string  $onCls     켜짐(채운 색) 클래스
     * @return array<string, mixed>
     */
    private function managerToggleButton(
        bool $on,
        string $stateExpr,
        string $icon,
        string $endpoint,
        string $labelKey,
        string $onCls
    ): array {
        $offCls = 'cursor-pointer border-gray-300 bg-white text-gray-700 hover:bg-gray-50 '
            .'dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700';

        return [
            'type' => 'basic',
            'name' => 'Button',
            'if' => '{{post?.data?.abilities?.can_manage && '.($on ? $stateExpr : '!'.$stateExpr).'}}',
            'props' => [
                'type' => 'button',
                'className' => $this->squareButtonClass().($on ? $onCls : $offCls),
                'title' => $labelKey,
                'aria-label' => $labelKey,
                'aria-pressed' => $on ? 'true' : 'false',
            ],
            'children' => [
                [
                    'type' => 'composite',
                    'name' => 'Icon',
                    'props' => ['name' => $icon, 'ariaLabel' => $labelKey],
                ],
            ],
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
     * 관리자급 잠금/해제 버튼 1개.
     *
     * 1.3.0 에서 노출 조건을 `_global.currentUser.is_admin`(사이트 관리자)에서
     * `post.data.abilities.can_manage`(= `sirsoft-board.{slug}.manager`)로 바꿨다.
     * 서버(`PostLockController`)도 같은 식별자로 판정하므로 화면과 판정이 갈리지 않는다.
     *
     * @param  bool  $forUnlock  true=해제 버튼(잠겼을 때 표시), false=잠그기 버튼
     * @return array<string, mixed>
     */
    private function lockToggleButton(bool $forUnlock): array
    {
        return $this->managerToggleButton(
            on: $forUnlock,
            stateExpr: 'forum_meta?.data?.locked',
            icon: self::LOCK_ICON,
            endpoint: $forUnlock ? 'unlock' : 'lock',
            labelKey: $forUnlock ? '$t:g7-forum-addon.unlock_button' : '$t:g7-forum-addon.lock_button',
            onCls: 'cursor-pointer border-gray-600 bg-gray-600 text-white hover:bg-gray-700 '
                .'dark:border-gray-500 dark:bg-gray-500 dark:hover:bg-gray-400',
        );
    }

    /**
     * 관리자급 핀(고정)/고정해제 버튼 1개.
     *
     * 현재 상태는 `forum_meta.data.is_notice`(= 코어 `board_posts.is_notice`)로 읽는다 —
     * 애드온이 따로 저장하는 값이 없다. 켜진 상태의 채운 색은 고정 배지(`bg-blue-600`)와
     * 같은 계열이라 배지와 버튼이 같은 상태를 가리킨다는 것이 한눈에 보인다.
     *
     * @param  bool  $forUnpin  true=고정해제 버튼(고정돼 있을 때 표시), false=고정 버튼
     * @return array<string, mixed>
     */
    private function pinToggleButton(bool $forUnpin): array
    {
        return $this->managerToggleButton(
            on: $forUnpin,
            stateExpr: 'forum_meta?.data?.is_notice',
            icon: self::PIN_ICON,
            endpoint: $forUnpin ? 'unpin' : 'pin',
            labelKey: $forUnpin ? '$t:g7-forum-addon.unpin_button' : '$t:g7-forum-addon.pin_button',
            onCls: 'cursor-pointer border-blue-600 bg-blue-600 text-white hover:bg-blue-700 '
                .'dark:border-blue-500 dark:bg-blue-500 dark:hover:bg-blue-400',
        );
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
                        'counts' => ['up' => 0, 'down' => 0],
                        'total' => 0,
                        'mine' => null,
                    ],
                    'comment_reactions' => [],
                    'tags' => [],
                    'subscribed' => false,
                    'accepted_reply_id' => null,
                    'accepted_reply' => null,
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
     * 컴포넌트 트리를 순회하며 **채택된 댓글의 행 전체**에 테두리·배경 강조를 건다.
     *
     * ── 왜 className 을 고치는가 ─────────────────────────────────
     * 댓글 한 건의 바깥 상자는 템플릿(`_comment_item.json`)의 최상위 `Div` 다. 배지처럼
     * 노드를 끼워 넣는 방식으로는 그 상자에 테두리를 줄 수 없어서, 이 노드의
     * `className` 에 **조건부 표현식을 덧붙이는** 방식을 쓴다. 템플릿 파일은 건드리지
     * 않는다 — 다른 splice 들과 마찬가지로 `after_apply` 가 받은 트리에서만 바꾼다.
     *
     * `props.style`(댓글 깊이에 따른 `marginLeft`)은 그대로 둔다. `className` 만 바꾼다.
     *
     * ── 앵커 식별 ────────────────────────────────────────────────
     * 구조적 시그니처 두 가지를 모두 만족해야 한다:
     *   1. `name === 'Div'` 이고 `className` 이 {@see COMMENT_ROW_CLASS} 와 정확히 같다
     *   2. 그 하위 어딘가에 "읽기 모드 댓글 본문 `<P>`"({@see isCommentContentP})가 있다
     * 2번이 없으면 우연히 같은 클래스를 쓰는 다른 상자까지 물들 수 있다.
     *
     * 채택이 아닐 때도 `border border-transparent` 를 깔아 둔다 — 강조가 켜질 때
     * 테두리 두께만큼 밀려나는 것을 막는다.
     *
     * @param  array<int, mixed>  $nodes
     * @param  int  $applied  (참조) 적용 횟수
     * @return array<int, mixed>
     */
    private function applyAcceptedRowHighlight(array $nodes, int &$applied): array
    {
        $out = [];

        foreach ($nodes as $node) {
            if (is_array($node) && $this->isCommentRowContainer($node)) {
                $node['props']['className'] = self::COMMENT_ROW_CLASS
                    .' {{'.self::ACCEPTED_ROW_MARKER." ? 'border border-green-300 bg-green-50"
                    ." dark:border-green-700 dark:bg-green-900/20' : 'border border-transparent'}}";
                $applied++;
            }

            if (is_array($node) && isset($node['children']) && is_array($node['children'])) {
                $node['children'] = $this->applyAcceptedRowHighlight($node['children'], $applied);
            }

            $out[] = $node;
        }

        return $out;
    }

    /**
     * 노드가 댓글 행 컨테이너인지 형태로 판정한다 (멱등 — 이미 손댄 노드는 제외).
     *
     * @param  array<string, mixed>  $node
     */
    private function isCommentRowContainer(array $node): bool
    {
        if (($node['name'] ?? null) !== 'Div') {
            return false;
        }

        $class = $node['props']['className'] ?? null;

        // 이미 적용된 노드는 className 이 달라져 여기서 걸러진다(멱등).
        if (! is_string($class) || $class !== self::COMMENT_ROW_CLASS) {
            return false;
        }

        return $this->subtreeHasCommentContentP($node['children'] ?? []);
    }

    /**
     * 하위 트리 어딘가에 "읽기 모드 댓글 본문 `<P>`" 가 있는지.
     *
     * @param  mixed  $nodes
     */
    private function subtreeHasCommentContentP(mixed $nodes): bool
    {
        if (! is_array($nodes)) {
            return false;
        }

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            if ($this->isCommentContentP($node)) {
                return true;
            }
            if ($this->subtreeHasCommentContentP($node['children'] ?? [])) {
                return true;
            }
        }

        return false;
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
                    // "채택됨" 표시 — 채택된 댓글이면 권한과 무관하게 모두에게 보인다.
                    // 행 전체 강조(applyAcceptedRowHighlight)와 짝이다 — 강조만으로는
                    // 색을 구분하기 어려운 환경에서 무엇을 뜻하는지 알 수 없다.
                    'type' => 'basic',
                    'name' => 'Span',
                    'if' => '{{'.$isAccepted.'}}',
                    'props' => [
                        'className' => 'inline-flex items-center gap-1 rounded-full bg-green-100 px-2 py-0.5 text-xs font-semibold text-green-700 dark:bg-green-900/40 dark:text-green-300',
                    ],
                    'children' => [
                        [
                            'type' => 'composite',
                            'name' => 'Icon',
                            'props' => ['name' => 'circle-check', 'size' => 'sm', 'ariaLabel' => '$t:g7-forum-addon.accepted_badge'],
                        ],
                        [
                            'type' => 'basic',
                            'name' => 'Span',
                            'text' => '$t:g7-forum-addon.accepted_badge',
                        ],
                    ],
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
