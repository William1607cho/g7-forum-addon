<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 포럼게시판 애드온 — 게시글당 1행 메타 테이블.
 *
 * sirsoft-board 의 board_posts 에 컬럼을 더하지 않고, 포럼형 게시글에 딸리는 애드온 전용
 * 상태를 여기 모은다. 이 빌드(0.1.0-dev, 뼈대)에서는 스키마가 확정된 두 기능 컬럼만 둔다:
 *
 *  - `is_locked`         : 잠금(Lock) — true 면 애드온이 댓글 작성을 서버에서 거부한다(이후 단계).
 *  - `accepted_reply_id` : 베스트 답글 채택 — 채택된 답글(board_posts.id). 없으면 null.
 *
 * 추천/태그/멘션/구독/편집이력 등은 각자 별도 테이블로 이후 단계에서 추가한다(설계문서 §4).
 * FK 는 걸지 않는다 — board_posts 는 한때 파티션 테이블이었고 애드온이 대상 모듈 스키마에
 * 물리적으로 묶이지 않도록, 참조 무결성은 애드온 애플리케이션 계층에서 다룬다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('g7_forum_addon_post_meta', function (Blueprint $table) {
            $table->id()->comment('고유 ID');
            $table->unsignedBigInteger('post_id')->unique()->comment('board_posts.id (게시글당 1행)');
            $table->unsignedBigInteger('board_id')->index()->comment('boards.id (게시판 스코프·정리용)');
            $table->boolean('is_locked')->default(false)->comment('잠금 여부 — true 면 애드온이 댓글 작성을 거부');
            $table->unsignedBigInteger('accepted_reply_id')->nullable()->comment('채택된 베스트 답글의 board_posts.id');
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('g7_forum_addon_post_meta', function (Blueprint $table) {
                $table->comment('포럼게시판 애드온 — 게시글당 1행 메타(잠금/베스트답글)');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('g7_forum_addon_post_meta');
    }
};
