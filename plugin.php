<?php

namespace Plugins\G7\Forum\Addon;

use App\Extension\AbstractPlugin;
use Illuminate\Support\Facades\DB;
use Plugins\G7\Forum\Addon\Listeners\AcceptedReplyCleanupListener;
use Plugins\G7\Forum\Addon\Listeners\BoardIndexWidgetListener;
use Plugins\G7\Forum\Addon\Listeners\BoardShowWidgetListener;
use Plugins\G7\Forum\Addon\Listeners\BoardTypeSeedListener;
use Plugins\G7\Forum\Addon\Listeners\CommentLockGuardListener;

/**
 * 포럼게시판 애드온 (g7-forum-addon) — 뼈대(scaffold) 빌드 0.1.0-dev.
 *
 * sirsoft-board 게시판에 포럼형(`forum`) board_type 을 더하고, 그 위에 추천·태그·멘션·
 * 구독·베스트답글·잠금 같은 포럼 기능을 얹기 위한 애드온이다. 대상 모듈(sirsoft-board)과
 * 방문자 템플릿(sirsoft-basic)은 **파일 한 줄도 수정하지 않는다** — 애드온 전용 테이블,
 * 자체 API(`/api/plugins/g7-forum-addon/*`), 그리고 `core.layout_extension.after_apply`
 * 필터 훅으로 최종 레이아웃 트리에 위젯을 splice 하는 방식으로만 동작한다.
 *
 * 이 빌드에 담긴 것 (뼈대만):
 *  - `board_types` 에 `forum` 행 등록 (install 시 삽입 + `seed.sirsoft-board.board_types
 *    .translations` 필터 리스너로 재시드 생존) / uninstall 시 정리(사용 중이면 차단)
 *  - `GET /api/plugins/g7-forum-addon/posts/{id}/meta` — 빈 뼈대 응답 + sirsoft-board 와
 *    동일한 가시성(비밀글/블라인드/삭제/게시판) 재검증 게이트
 *  - `BoardShowWidgetListener` 가 `core.layout_extension.after_apply` 로 `board/show`
 *    레이아웃의 액션 버튼 줄 직전에 포럼 위젯 자리표시자를 주입 (board.type === 'forum' 게이팅).
 *    `html_content` extension_point 의 priority/replace 경쟁을 피한다.
 *  - 알림 정의 등록 메커니즘 확인용 자리표시자 정의 1개 (트리거 훅 없음)
 *
 * 실제 기능(추천 버튼·태그 UI·멘션 파싱·구독 토글 등)은 이후 단계에서 각 기능별로 추가한다.
 */
class Plugin extends AbstractPlugin
{
    /** board_types 에 등록하는 포럼 유형 슬러그 */
    public const FORUM_BOARD_TYPE = 'forum';

    /**
     * 플러그인 메타데이터.
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return [
            'author' => 'William Cho',
            'license' => 'MIT',
            'keywords' => ['forum', 'board', 'reactions', 'tags', 'mentions', 'subscriptions', 'sirsoft-board'],
        ];
    }

    /**
     * 훅 리스너 목록.
     *
     * - BoardTypeSeedListener: `seed.sirsoft-board.board_types.translations` 필터에 붙어,
     *   sirsoft-board 의 BoardTypeSeeder 가 (재)실행될 때 `forum` 유형이 upsert 되고 동시에
     *   stale-cleanup 화이트리스트에도 포함되도록 한다.
     * - BoardShowWidgetListener: `core.layout_extension.after_apply` 필터에 붙어, 확장 합성이
     *   끝난 `board/show` 레이아웃 트리에 포럼 위젯을 액션 버튼 줄 직전으로 splice 하고,
     *   잠금 상태일 때 댓글 입력 폼을 가리고 안내 문구로 대체한다.
     *   `html_content` extension_point 의 priority/replace 경쟁을 피하는 정식 앵커 방식.
     * - CommentLockGuardListener: `sirsoft-board.comment.store_validation_rules` +
     *   `sirsoft-board.comment.filter_create_data` 필터에 붙어, 잠긴 포럼 게시글에 새 댓글이
     *   달리는 것을 서버에서 거부한다(프론트 차단과 별개의 API 레벨 관문).
     * - AcceptedReplyCleanupListener: `sirsoft-board.comment.after_delete` 액션에 붙어, 삭제된
     *   댓글이 그 게시글의 채택 답글이면 `accepted_reply_id` 를 null 로 되돌린다(고아 참조 방지).
     * - BoardIndexWidgetListener: `core.layout_extension.after_apply` 필터에 붙어, `board/index`
     *   (게시판 목록) 레이아웃에 "참여자" 컬럼을 추가하고 "작성일" 컬럼을 forum 게시판에
     *   한해 "최근 활동"으로 표시한다(정렬 기준 변경은 범위 밖 — sirsoft-board 에 정렬
     *   개입 훅이 없어 페이지네이션까지 정확히 구현할 방법이 없다고 확인됨).
     *
     * @return array<int, class-string>
     */
    public function getHookListeners(): array
    {
        return [
            BoardTypeSeedListener::class,
            BoardShowWidgetListener::class,
            BoardIndexWidgetListener::class,
            CommentLockGuardListener::class,
            AcceptedReplyCleanupListener::class,
        ];
    }

    /**
     * 플러그인이 관리하는 동적 테이블 — uninstall(--delete-data) 시 코어가 DROP 한다.
     *
     * @return array<int, string>
     */
    public function getDynamicTables(): array
    {
        return [
            'g7_forum_addon_post_meta',
            'g7_forum_addon_reactions',
        ];
    }

    /**
     * 알림 정의 — 이 빌드에서는 "등록 메커니즘이 동작하는지" 확인용 자리표시자 1개만 둔다.
     *
     * `hooks` 가 빈 배열이라 어떤 이벤트로도 발화하지 않는다 (NotificationHookListener 는
     * hooks 항목마다 동적 구독하므로 빈 배열 = 구독 0 = 발송 0). 실제 알림 종류(멘션 등)는
     * 이후 단계에서 이 배열에 추가하며, PluginManager 가 install/update/재시드마다
     * 플러그인 소유분(extension_identifier=g7-forum-addon)만 upsert/cleanup 한다.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getNotificationDefinitions(): array
    {
        return [
            [
                'type' => 'forum_scaffold_check',
                'hook_prefix' => 'g7-forum-addon',
                'name' => [
                    'ko' => '[뼈대 확인용] 포럼 애드온',
                    'en' => '[Scaffold] Forum Add-on',
                ],
                'description' => [
                    'ko' => '알림 정의 등록 메커니즘 검증용 자리표시자입니다. 트리거 훅이 없어 실제로 발송되지 않으며, 실제 알림(멘션 등)은 이후 단계에서 추가됩니다.',
                    'en' => 'Placeholder to verify the notification-definition registration path. It has no trigger hook and never sends; real notifications (mentions, etc.) are added in later steps.',
                ],
                'channels' => ['database'],
                'hooks' => [],
                'variables' => [
                    ['key' => 'app_name', 'description' => '사이트명'],
                ],
                'templates' => [
                    [
                        'channel' => 'database',
                        'recipients' => [['type' => 'trigger_user']],
                        'subject' => [
                            'ko' => '[포럼 애드온] 자리표시자 알림',
                            'en' => '[Forum Add-on] Placeholder notification',
                        ],
                        'body' => [
                            'ko' => '이 알림 정의는 뼈대 검증용입니다. 실제 발송되지 않습니다.',
                            'en' => 'This notification definition is a scaffold check. It is never actually sent.',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * 플러그인 설치 — `board_types` 에 `forum` 행을 보장한다.
     *
     * 주의: PluginManager 는 install() 을 **마이그레이션 실행 전** 에 호출하므로, 여기서
     * 이 플러그인 자체 테이블(g7_forum_addon_*)은 아직 없다. `board_types` 는 sirsoft-board
     * (의존성으로 이미 활성) 소유 테이블이라 존재가 보장된다.
     *
     * @return bool
     */
    public function install(): bool
    {
        $this->ensureForumBoardType();

        return true;
    }

    /**
     * 플러그인 제거.
     *
     * `forum` 유형을 쓰는 게시판이 하나라도 있으면 제거를 **차단**한다 (게시판이 유형을
     * 잃고 basic 으로 조용히 폴백되는 것을 막는다). 운영자는 해당 게시판을 다른 유형으로
     * 바꾸거나 삭제한 뒤 다시 시도해야 한다. 사용 중이 아니면 `board_types` 의 `forum`
     * 행을 지운다.
     *
     * 레이아웃 확장·알림 정의는 PluginManager 가 소유자 기준으로 자동 정리하므로 여기서
     * 다루지 않는다. 애드온 전용 테이블은 `plugin:uninstall --delete-data` 시 코어가
     * `getDynamicTables()` 기준으로 DROP 한다.
     *
     * @return bool
     */
    public function uninstall(): bool
    {
        if ($this->forumBoardsInUse() > 0) {
            $count = $this->forumBoardsInUse();

            return $this->failWith(
                "포럼형('forum') 유형을 사용하는 게시판이 {$count}개 있어 제거할 수 없습니다. "
                .'해당 게시판을 다른 유형으로 변경하거나 삭제한 뒤 다시 시도하세요.'
            );
        }

        DB::table('board_types')->where('slug', self::FORUM_BOARD_TYPE)->delete();

        return true;
    }

    /**
     * `board_types` 에 `forum` 행이 없으면 삽입한다 (idempotent).
     */
    private function ensureForumBoardType(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('board_types')) {
            return;
        }

        $exists = DB::table('board_types')
            ->where('slug', self::FORUM_BOARD_TYPE)
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('board_types')->insert([
            'slug' => self::FORUM_BOARD_TYPE,
            'name' => json_encode(['ko' => '포럼형', 'en' => 'Forum'], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * `forum` 유형을 쓰는 활성/비활성 게시판 수.
     */
    private function forumBoardsInUse(): int
    {
        if (! DB::getSchemaBuilder()->hasTable('boards')) {
            return 0;
        }

        return (int) DB::table('boards')
            ->where('type', self::FORUM_BOARD_TYPE)
            ->count();
    }
}
