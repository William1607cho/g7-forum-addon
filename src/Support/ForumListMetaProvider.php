<?php

namespace Plugins\G7\Forum\Addon\Support;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Traits\FormatsBoardDate;

/**
 * 게시판 목록 화면의 "참여자"/"최근 활동" 배치 조회.
 *
 * 목록에 보이는 게시글 ID 묶음(한 페이지치, 보통 20건 이하)에 대해:
 *  - `last_activity_at`(ISO) / `last_activity_at_formatted`(표시용) : 활동 시각의
 *    정의와 SQL 은 {@see ActivityTime} 이 단독으로 갖는다 — 목록 정렬
 *    (`ActivitySortedPostRepository`)이 같은 정의를 쓰므로 표시값과 정렬 순서가
 *    어긋나지 않는다. 표시 포맷은 sirsoft-board 의 `FormatsBoardDate` 트레이트를 그대로
 *    재사용(모듈 파일을 고치는 게 아니라 그 모듈이 공개한 트레이트를 그대로 쓰는
 *    것 — Post/Comment 모델을 직접 안 쓰는 이 플러그인의 기존 방침과 상충하지 않는다.
 *    "작성일" 기존 컬럼과 표시 포맷이 완전히 같아야 자연스럽기 때문에 새로 만들지
 *    않고 재사용한다)로 "작성일" 컬럼과 동일한 포맷 규칙(관리자 설정
 *    `display.date_display_format`)을 따른다 — 프론트에 별도 포맷 함수를 새로 만들지
 *    않기 위함.
 *  - `participants` : 게시글 작성자 + 댓글 작성자 전원을 활동 시각 내림차순으로
 *    사용자 기준 중복 제거한 뒤 상위 5명.
 *
 * 페이지당 쿼리 2개로 끝난다(N+1 없음) — `post_id IN (...)` 상관 집계는 마이페이지
 * 활동목록이 이미 쓰는 페이지-스코프 서브쿼리 패턴과 동형이며,
 * `idx_board_comments_post_created (board_id, post_id, created_at)` 인덱스가 뒷받침한다.
 * sirsoft-board 의 `Post`/`Comment` Eloquent 모델은 쓰지 않고(이 플러그인의 기존 방침 —
 * `post_meta`/`lock`/리액션과 동일하게 쿼리 빌더로 처리) 쿼리 빌더 + 원본 SQL(윈도우
 * 함수는 플루언트 빌더로 표현 불가)로만 접근한다. 사용자 아바타/이름은 앱 core 의
 * `App\Models\User` 를 그대로 쓴다(모듈 소유 도메인 모델과 달리 앱 전역 개념이라
 * 플러그인이 직접 의존해도 결합도 문제가 없음 — 다른 플러그인들도 이미 그렇게 한다).
 */
class ForumListMetaProvider
{
    use FormatsBoardDate;

    /** 참여자 상한 (아이콘 최대 5개) */
    private const MAX_PARTICIPANTS = 5;

    /**
     * 한 요청에서 받아들이는 게시글 ID 최대 개수 (배치 남용 방지).
     *
     * 값의 원본은 {@see ActivityTime::MAX_POST_IDS} 다 — 활동 시각 집계의 상한과
     * 같은 값이어야 해서 여기서 따로 정하지 않는다. 이미 공개된 이름이라 별칭으로 남긴다.
     */
    public const MAX_POST_IDS = ActivityTime::MAX_POST_IDS;

    /**
     * 요청 쿼리스트링의 `post_ids`(콤마 구분 문자열)를 정수 배열로 정규화합니다.
     *
     * @return array<int, int> 중복 제거·0 이하 제외·상한 적용된 게시글 ID 목록
     */
    public function parsePostIds(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        $ids = array_map('intval', explode(',', $raw));
        $ids = array_values(array_unique(array_filter($ids, fn ($id) => $id > 0)));

        return array_slice($ids, 0, self::MAX_POST_IDS);
    }

    /**
     * 요청된 게시글 ID 중 이 요청자에게 노출 가능한 것만 남깁니다.
     *
     * `PostVisibilityGuard::assertViewable()` 와 같은 판정 기준(비밀글/블라인드/삭제 →
     * 작성자 본인만)을 배치용으로 적용한다 — 단일 게시글처럼 404 로 중단하지 않고,
     * 통과하지 못한 ID 만 조용히 제외한다(나머지는 정상 응답해야 하는 배치 특성).
     *
     * @param  array<int, int>  $postIds
     * @return array<int, int>
     */
    public function filterVisiblePostIds(int $boardId, array $postIds, ?int $viewerId): array
    {
        if ($postIds === []) {
            return [];
        }

        $rows = DB::table('board_posts')
            ->select('id', 'user_id', 'is_secret', 'status', 'deleted_at')
            ->where('board_id', $boardId)
            ->whereIn('id', $postIds)
            ->get();

        $allowed = [];
        foreach ($rows as $row) {
            $isAuthor = $viewerId !== null && (int) $row->user_id === (int) $viewerId;
            $isRemoved = $row->deleted_at !== null || $row->status === 'deleted';
            $isBlinded = $row->status === 'blinded';

            if (($isRemoved || $isBlinded) && ! $isAuthor) {
                continue;
            }
            if ((int) $row->is_secret === 1 && ! $isAuthor) {
                continue;
            }

            $allowed[] = (int) $row->id;
        }

        return $allowed;
    }

    /**
     * 참여자/최근활동 배치 조회 본체.
     *
     * @param  array<int, int>  $postIds  이미 가시성 필터를 통과한 게시글 ID
     * @param  string  $dateFormat  'standard' | 'relative' — `display.date_display_format`
     * @return array<int, array{
     *     participants: list<array{uuid: string|null, name: string, avatar: string|null}>,
     *     last_activity_at: string|null,
     *     last_activity_at_formatted: string
     * }>
     */
    public function batch(int $boardId, array $postIds, string $dateFormat = 'standard'): array
    {
        $postIds = array_values(array_unique(array_filter(array_map('intval', $postIds), fn ($id) => $id > 0)));

        if ($postIds === []) {
            return [];
        }

        $prefix = DB::getTablePrefix();
        $postsTable = $prefix.ActivityTime::POSTS_TABLE;
        $commentsTable = $prefix.ActivityTime::COMMENTS_TABLE;
        $placeholders = implode(',', array_fill(0, count($postIds), '?'));

        // 1) 게시글별 최종 활동 시각 — 정의와 SQL 은 ActivityTime 이 단독으로 갖는다.
        //    목록 정렬(ActivitySortedPostRepository)이 같은 클래스의 다른 형태를 쓰므로
        //    표시값과 정렬 순서가 어긋날 수 없다. 여기서 다시 계산하지 않는다.
        $lastActivityByPost = ActivityTime::batch($boardId, $postIds);

        // 2) 게시글별 참여자 상위 5명 — 사용자별 최댓값(MAX(created_at))으로 묶은 뒤
        //    ROW_NUMBER() 윈도우 함수로 게시글 내 순위를 매겨 상위 5명만 남긴다.
        //    (MariaDB 10.11 — 윈도우 함수/파생 테이블 지원. 익명 작성자(user_id NULL)는
        //    "참여자"로 셀 정체성이 없으므로 제외한다 — 최근활동 계산과 달리 여기선
        //    user_id 필수.)
        $participantRows = DB::select(
            "SELECT post_id, user_id, last_at FROM (
                SELECT post_id, user_id, MAX(created_at) AS last_at,
                       ROW_NUMBER() OVER (PARTITION BY post_id ORDER BY MAX(created_at) DESC) AS rn
                FROM (
                    SELECT post_id, user_id, created_at FROM {$commentsTable}
                    WHERE board_id = ? AND post_id IN ({$placeholders}) AND deleted_at IS NULL AND user_id IS NOT NULL
                    UNION ALL
                    SELECT id AS post_id, user_id, created_at FROM {$postsTable}
                    WHERE board_id = ? AND id IN ({$placeholders}) AND user_id IS NOT NULL
                ) g7_forum_addon_events
                GROUP BY post_id, user_id
            ) g7_forum_addon_ranked
            WHERE rn <= ".self::MAX_PARTICIPANTS.'
            ORDER BY post_id, last_at DESC',
            [$boardId, ...$postIds, $boardId, ...$postIds]
        );

        $userIdsByPost = [];
        $allUserIds = [];
        foreach ($participantRows as $row) {
            $pid = (int) $row->post_id;
            $uid = (int) $row->user_id;
            $userIdsByPost[$pid][] = $uid;
            $allUserIds[] = $uid;
        }
        $allUserIds = array_values(array_unique($allUserIds));

        $users = $allUserIds === []
            ? collect()
            : User::whereIn('id', $allUserIds)->with('avatarAttachment')->get()->keyBy('id');

        $result = [];
        foreach ($postIds as $postId) {
            $participants = [];
            foreach ($userIdsByPost[$postId] ?? [] as $uid) {
                $participants[] = $this->userToParticipant($users->get($uid));
            }

            $lastActivityAt = $lastActivityByPost[$postId] ?? null;

            $result[$postId] = [
                'participants' => $participants,
                'last_activity_at' => $lastActivityAt,
                'last_activity_at_formatted' => $this->formatCreatedAtFormat($lastActivityAt, $dateFormat),
            ];
        }

        return $result;
    }

    /**
     * User 모델을 참여자 아바타 표시용 배열로 변환합니다.
     *
     * `PostResource::getAuthorInfo()` 와 같은 규칙(탈퇴 회원은 이름/아바타 마스킹)을
     * 따른다 — 목록 화면에 이미 노출되는 "작성자" 정보와 표시 규칙이 어긋나지 않도록.
     *
     * @return array{uuid: string|null, name: string, avatar: string|null}
     */
    private function userToParticipant(?User $user): array
    {
        if ($user === null) {
            // 배치 조회 사이 탈퇴/삭제 등으로 사라진 극히 드문 경합 — 조용히 빈 자리로.
            return ['uuid' => null, 'name' => '', 'avatar' => null];
        }

        $isWithdrawn = UserStatus::tryFrom($user->status) === UserStatus::Withdrawn;

        return [
            'uuid' => $user->uuid,
            'name' => $isWithdrawn ? __('user.withdrawn_user') : $user->name,
            'avatar' => $isWithdrawn ? null : $user->getAvatarUrl(),
        ];
    }
}
