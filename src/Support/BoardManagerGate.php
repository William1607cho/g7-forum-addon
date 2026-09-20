<?php

namespace Plugins\G7\Forum\Addon\Support;

use Modules\Sirsoft\Board\Traits\ChecksBoardPermission;

/**
 * "이 게시판의 관리자급인가" 판정의 단일 지점 (1.3.0).
 *
 * 애드온의 관리 동작(핀, 잠금)은 모두 이 판정을 쓴다. 판정 대상 권한은 게시판 매니저
 * `sirsoft-board.{slug}.manager` 하나다.
 *
 * ── 왜 이 식별자인가 ────────────────────────────────────────────
 * 화면의 버튼 노출 조건이 `post.data.abilities.can_manage` 인데, 코어가 그 값을 채울 때
 * 쓰는 식별자가 정확히 이것이다(`PostResource::buildAbilities()`, User 요청 분기).
 * 서버와 화면이 **같은 식별자를 같은 방식으로** 판정해야 "버튼은 보이는데 누르면 403"
 * 또는 그 반대가 생기지 않는다. 그래서 `PermissionHelper::check()` 로 따로 묻지 않고
 * 코어와 동일한 `ChecksBoardPermission` 트레이트를 거친다.
 *
 * ── 사이트 관리자 ──────────────────────────────────────────────
 * 별도 우회 분기를 두지 않는다. 관리자 역할은 시더에서 모든 리프 권한을 명시 할당받으므로
 * `{slug}.manager` 도 함께 갖는다(코어 `AuthServiceProvider` 주석의 근거와 동일).
 *
 * 1.2.0 까지 잠금은 `AdminBaseController`(`auth:sanctum` + `admin` 미들웨어)로 사이트
 * 관리자만 가능했다. 1.3.0 에서 핀과 같은 기준으로 맞추면서 게시판 매니저도 쓸 수 있다.
 */
class BoardManagerGate
{
    use ChecksBoardPermission;

    /**
     * 현재 요청자가 이 게시판의 관리자급인지 판정한다.
     *
     * @param  string  $slug  boards.slug
     */
    public function canManage(string $slug): bool
    {
        return $this->checkPermissionByIdentifier("sirsoft-board.{$slug}.manager");
    }
}
