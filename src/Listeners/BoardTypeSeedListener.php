<?php

namespace Plugins\G7\Forum\Addon\Listeners;

use App\Contracts\Extension\HookListenerInterface;

/**
 * sirsoft-board 의 BoardTypeSeeder 재시드에도 `forum` 유형이 살아남게 하는 필터 리스너.
 *
 * BoardTypeSeeder 는 `HasTranslatableSeeder` 를 통해 기본 유형 목록을
 * `seed.sirsoft-board.board_types.translations` 필터에 통과시킨 결과를 (a) upsert 대상,
 * (b) stale-cleanup 화이트리스트 양쪽에 쓴다. 화이트리스트에 없는 슬러그는 무차별 삭제되므로
 * (`GenericEntitySyncHelper::cleanupStale`, 사용 중 보호 없음), 이 리스너가 `forum` 을
 * 기본 목록에 끼워 넣어 재시드 시에도 유지되도록 한다.
 *
 * install() 의 1회성 삽입과 짝을 이룬다: install 이 최초 행을 만들고, 이 리스너가 이후
 * BoardTypeSeeder 실행(모듈 재설치·`php artisan module:seed sirsoft-board` 등)에서 유지·복원한다.
 */
class BoardTypeSeedListener implements HookListenerInterface
{
    /** board_types 슬러그 */
    private const FORUM_SLUG = 'forum';

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'seed.sirsoft-board.board_types.translations' => [
                'method' => 'contributeForumType',
                'type' => 'filter',
                'priority' => 20,
            ],
        ];
    }

    /**
     * 기본 유형 목록에 `forum` 항목을 더한다(이미 있으면 그대로).
     *
     * @param  array<int, array<string, mixed>>  $defaults  BoardTypeSeeder::getDefaults() 결과(+언어팩 머지)
     * @return array<int, array<string, mixed>>
     */
    public function contributeForumType(array $defaults): array
    {
        foreach ($defaults as $entry) {
            if (is_array($entry) && ($entry['slug'] ?? null) === self::FORUM_SLUG) {
                return $defaults;
            }
        }

        $defaults[] = [
            'slug' => self::FORUM_SLUG,
            'name' => ['ko' => '포럼형', 'en' => 'Forum'],
        ];

        return $defaults;
    }

    /**
     * @inheritDoc
     */
    public function handle(...$args): void
    {
        // 이 리스너는 filter 훅만 구독한다.
    }
}
